<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\CompanyLedgerService;
use App\Services\TaxPreflightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaxPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_na_sales_remain_readable_after_the_default_code_becomes_nr(): void
    {
        [$company, $owner] = $this->book();
        TaxCode::factory()->create(['workspace_key' => $company->workspaceKey(), 'code' => 'NA', 'name' => 'Not applicable', 'kind' => 'Exempt', 'rate' => 0]);
        TaxCode::seedWorkspace($company->workspaceKey());
        $state = CompanyLedger::query()->where('company_id', $company->id)->sole()->state;
        $state['sales'][] = [...$state['sales'][0], 'id' => 2, 'number' => 'SI-000002', 'reference' => 'NR-REF', 'gross' => 10000, 'tax' => 'TAX|NA|Exempt|0'];
        app(CompanyLedgerService::class)->save($company, $owner, ['state' => $state, 'payroll' => [], 'revision' => 1, 'request_id' => (string) Str::uuid()]);
        $response = $this->getJson($this->url())->assertOk()->assertJsonPath('source_count', 2);
        $this->assertNotContains('tax_code', array_column($response->json('issues'), 'code'));
    }

    public function test_relief_purchases_preflight_excludes_nr_nonvat_and_legacy_na_before_validation(): void
    {
        [$company] = $this->book();
        $ledger = CompanyLedger::query()->where('company_id', $company->id)->firstOrFail();
        $state = $ledger->state;
        $state['purchases'] = array_map(fn (string $tax, int $index): array => ['id' => $index + 1, 'number' => 'BILL-'.$index, 'date' => '2026-10-01', 'status' => 'posted', 'tax' => $tax, 'gross' => 10000, 'supplier' => 'No documentation', 'tin' => ''], ['NR', 'NA', 'NONVAT', 'Non-VAT', 'TAX|NR|Exempt|0', 'TAX|NA|Exempt|0', 'TAX|NONVAT|Exempt|0'], range(0, 6));
        $ledger->update(['state' => $state]);
        $response = $this->getJson(route('tax-preflight.show', [...$this->selection(), 'schedule' => 'relief-purchases']));
        $response->assertOk()->assertJsonPath('source_count', 0)->assertJsonPath('reconciled_count', 0);
        $this->assertNotContains('party_tin', array_column($response->json('issues'), 'code'));
    }

    public function test_saved_sources_reconcile_to_books_without_claiming_bir_acceptance(): void
    {
        [$company] = $this->book();
        $result = $this->getJson($this->url())->assertOk()->assertJsonPath('status', 'Checks passed')->assertJsonPath('source_count', 1)->assertJsonPath('reconciled_count', 1)->assertJsonPath('tax_centavos', 5357)->assertJsonPath('validator_status', 'Not verified')->json();
        $this->assertSame(['external_validation'], array_column($result['issues'], 'code'));
        $this->assertDatabaseCount('tax_preflight_reviews', 0);
        $this->assertDatabaseHas('company_ledgers', ['company_id' => $company->id, 'revision' => 1]);
    }

    public function test_missing_party_details_duplicates_and_books_differences_have_source_targets(): void
    {
        [$company] = $this->book();
        Party::query()->where('workspace_key', $company->workspaceKey())->update(['address' => '', 'tin' => '000-000-000-00000']);
        $ledger = CompanyLedger::query()->where('company_id', $company->id)->first();
        $state = $ledger->state;
        $state['sales'][] = [...$state['sales'][0], 'id' => 2, 'number' => 'SI-000002'];
        $state['sales'][] = [...$state['sales'][0], 'id' => 3, 'status' => 'draft'];
        $state['sales'][] = [...$state['sales'][0], 'id' => 4, 'date' => '2027-01-01'];
        $ledger->update(['state' => $state]);
        DB::table('journal_entry_lines')->where('company_id', $company->id)->where('account_name', 'like', '2210%')->update(['credit' => '53.58']);
        $result = $this->getJson($this->url())->assertOk()->assertJsonPath('status', 'Review needed')->assertJsonPath('source_count', 2)->json();
        foreach (['party_tin', 'party_address', 'duplicate', 'journal_difference', 'journal_missing'] as $code) {
            $this->assertContains($code, array_column($result['issues'], 'code'));
        }
        $this->assertSame('parties', collect($result['issues'])->firstWhere('code', 'party_address')['target']);
    }

    public function test_reviews_are_snapshot_bound_and_company_isolated(): void
    {
        [$company, $owner] = $this->book();
        $result = $this->getJson($this->url())->assertOk()->json();
        $payload = [...$this->selection(), 'snapshot_hash' => $result['snapshot_hash'], 'note' => 'Source and Books compared; validator still required.'];
        $this->withHeader('X-Ledger-Revision', '1')->postJson(route('tax-preflight.store'), $payload)->assertOk();
        $this->assertDatabaseHas('tax_preflight_reviews', ['company_id' => $company->id, 'user_id' => $owner->id, 'action' => 'review']);
        $this->getJson($this->url())->assertJsonCount(1, 'history')->assertJsonPath('history.0.actor', $owner->name);
        Party::query()->where('workspace_key', $company->workspaceKey())->update(['address' => 'Changed company address']);
        $this->postJson(route('tax-preflight.store'), $payload)->assertConflict();
        [$other] = $this->book();
        $this->getJson($this->url())->assertJsonCount(0, 'history');
        $this->withHeader('X-Company-ID', (string) $company->id)->getJson($this->url())->assertConflict();
        $this->assertDatabaseCount('tax_preflight_reviews', 1);
        $this->assertNotSame($company->id, $other->id);
    }

    public function test_reports_permission_is_required_and_no_forged_pass_status_is_saved(): void
    {
        [$company] = $this->book();
        $staff = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($staff, ['role' => 'staff', 'allowed_tabs' => json_encode(['dashboard', 'sheets'])]);
        $this->actingAs($staff)->getJson($this->url())->assertForbidden();
        $this->postJson(route('tax-preflight.store'), [...$this->selection(), 'snapshot_hash' => str_repeat('a', 64), 'note' => 'Checked'])->assertForbidden();
        $this->assertDatabaseCount('tax_preflight_reviews', 0);
    }

    public function test_qap_and_sawt_layout_findings_prevent_a_false_checks_passed_status(): void
    {
        [$company] = $this->book();
        foreach (['qap-ewt' => 'layout_qap', 'sawt' => 'layout_sawt'] as $schedule => $code) {
            $result = app(TaxPreflightService::class)->check($company, [...$this->selection(), 'schedule' => $schedule, 'form' => '1702Q']);
            $this->assertSame('Review needed', $result['status']);
            $this->assertContains($code, array_column($result['issues'], 'code'));
        }
    }

    public function test_export_history_records_generated_bytes_hash_without_changing_financial_records(): void
    {
        [$company, $owner] = $this->book();
        $request = Request::create('/reports/generate-sales-dat', 'POST');
        $request->headers->set('X-Ledger-Revision', '1');
        $request->setUserResolver(fn (): User => $owner);
        $request->attributes->set('active_company', $company);
        app(TaxPreflightService::class)->recordExport($request, $this->selection(), 'S-SAMPLE.DAT', ["H,SAMPLE\r\n", "D,SAMPLE\r\n"]);
        $this->assertDatabaseHas('tax_preflight_reviews', ['company_id' => $company->id, 'action' => 'export_generated', 'filename' => 'S-SAMPLE.DAT', 'file_hash' => hash('sha256', "H,SAMPLE\r\nD,SAMPLE\r\n")]);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->getJson($this->url())->assertJsonCount(1, 'history')->assertJsonPath('history.0.action', 'export_generated');
    }

    /** @return array<string, string> */
    private function selection(): array
    {
        return ['schedule' => 'relief-sales', 'from' => '2026-10-01', 'to' => '2026-12-31', 'form' => '', 'tax_kind' => 'income'];
    }

    public function test_qap_uses_the_linked_purchase_atc_and_reconciles_its_existing_voucher(): void
    {
        [$company, $owner] = $this->book();
        Party::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => 'Sample Supplier', 'classification' => 'Supplier', 'tin' => '987-654-322-00000', 'address' => 'Supplier address']);
        $ledger = CompanyLedger::query()->where('company_id', $company->id)->first();
        $state = $ledger->state;
        $state['purchases'] = [['id' => 1, 'number' => 'BILL-000001', 'date' => '2026-10-01', 'status' => 'posted', 'supplier' => 'Sample Supplier', 'gross' => 112000, 'tax' => 'VAT (12%)', 'ewt' => 2, 'ewtCode' => 'WC160', 'wvat' => 0, 'account' => '7110 · Rent Expense', 'terms' => 'On Account']];
        $state['wtpCertificates'] = [['id' => 1, 'number' => '2307I-000001', 'date' => '2026-10-01', 'status' => 'posted', 'supplier' => 'Sample Supplier', 'billId' => 1, 'taxBase' => 100000, 'ewt' => 2, 'ewtCode' => 'WC160', 'wvat' => 0, 'wvatCode' => '']];
        app(CompanyLedgerService::class)->save($company, $owner, ['state' => $state, 'payroll' => [], 'revision' => 1, 'request_id' => (string) Str::uuid()]);
        $state['wtpCertificates'][0]['ewtCode'] = '';
        $state['wtpCertificates'][] = [...$state['wtpCertificates'][0], 'id' => 2, 'number' => 'VAT-ONLY', 'ewt' => 0, 'wvat' => 5];
        $ledger->refresh()->update(['state' => $state]);
        $result = app(TaxPreflightService::class)->check($company, [...$this->selection(), 'schedule' => 'qap-ewt']);
        $this->assertSame(1, $result['source_count']);
        $this->assertSame(1, $result['reconciled_count']);
        $this->assertSame(2000, $result['tax_centavos']);
        $this->assertNotContains('atc', array_column($result['issues'], 'code'));
    }

    public function test_partial_annual_period_and_malformed_tin_are_flagged(): void
    {
        [$company] = $this->book();
        Party::query()->where('workspace_key', $company->workspaceKey())->update(['tin' => 'ABC98765432100000']);
        $result = app(TaxPreflightService::class)->check($company, $this->selection());
        $this->assertContains('party_tin', array_column($result['issues'], 'code'));
        $annual = app(TaxPreflightService::class)->check($company, [...$this->selection(), 'schedule' => 'sawt', 'form' => '1702']);
        $this->assertContains('period', array_column($annual['issues'], 'code'));
    }

    public function test_sample_preview_cannot_record_reviews_and_is_unavailable_in_production(): void
    {
        $this->get(route('workspace.preview', ['page' => 'tax-preflight']))->assertOk()->assertSee('SAMPLE DATA')->assertSee('REVIEW SAVING DISABLED')->assertSee('tax-preflight.js');
        $this->assertDatabaseCount('tax_preflight_reviews', 0);
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->get(route('workspace.preview', ['page' => 'tax-preflight']))->assertNotFound();
    }

    private function url(): string
    {
        return route('tax-preflight.show', $this->selection());
    }

    /** @return array{Company, User} */
    private function book(): array
    {
        $company = Company::factory()->create();
        $owner = User::factory()->firm()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => $company->name, 'full_name' => 'Example', 'vat_type' => 'vat', 'tin' => '123456789', 'branch_code' => '00000', 'rdo' => '039', 'street' => 'Sample street', 'city' => 'Sample city']]);
        ChartAccount::seedWorkspace($company->workspaceKey());
        ChartAccount::query()->where('workspace_key', $company->workspaceKey())->where('code', '4020')->update(['relief_category' => 'vatable_sales']);
        Party::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => 'Sample Customer', 'tin' => '987-654-321-00000', 'address' => 'Sample business address']);
        $state = CompanyLedgerService::emptyState();
        $state['sales'] = [['id' => 1, 'number' => 'SI-000001', 'date' => '2026-10-01', 'status' => 'posted', 'customer' => 'Sample Customer', 'gross' => 50000, 'tax' => '12% VAT', 'account' => '4020 · Sales - Services', 'terms' => 'On Account', 'cwt' => 'None', 'vatCwt' => 'None', 'reference' => 'SAMPLE-REF']];
        app(CompanyLedgerService::class)->save($company, $owner, ['state' => $state, 'payroll' => [], 'revision' => 0, 'request_id' => (string) Str::uuid()]);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->withHeader('X-Company-ID', (string) $company->id);

        return [$company, $owner];
    }
}
