<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\CompanyLedgerService;
use App\Services\Tax\TaxReturnCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaxReturnTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Company, User, CompanyLedger} */
    private function book(): array
    {
        $owner = User::factory()->firm()->create(['must_change_password' => false]);
        $company = Company::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => 'Example Trading', 'full_name' => $owner->name, 'tin' => '123456789', 'branch_code' => '00000', 'rdo' => '033', 'vat_type' => 'vat', 'entity_type' => 'corporation']]);
        $state = CompanyLedgerService::emptyState();
        $record = ['id' => 1, 'number' => 'WTP-1', 'date' => '2026-10-20', 'status' => 'posted', 'ewtCode' => 'WC157', 'ewt' => 2, 'taxBase' => 44643];
        $state['wtpCertificates'] = [$record, [...$record, 'id' => 2, 'status' => 'draft'], [...$record, 'id' => 3, 'status' => 'void'], [...$record, 'id' => 4, 'date' => '2027-01-01']];
        $employee = ['basic' => '10000.00', 'taxableAllowance' => '1000.00', 'deMinimis' => '500.00', 'benefits' => '0.00', 'withholding' => '123.45'];
        $run = ['id' => 'run-1', 'number' => 'PYRJ-1', 'status' => 'posted', 'date' => '2026-10-20', 'employees' => [$employee]];
        $ledger = CompanyLedger::factory()->create(['company_id' => $company->id, 'revision' => 1, 'state' => $state, 'payroll' => [$run, [...$run, 'id' => 'run-2', 'status' => 'draft']]]);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->withHeader('X-Ledger-Revision', '1');

        return [$company, $owner, $ledger];
    }

    /** @return array<string, mixed> */
    private function selection(string $form = '0619-E'): array
    {
        return ['form' => $form, 'year' => 2026, 'period' => 10];
    }

    /** @return array<string, mixed> */
    private function input(Company $company, string $form = '0619-E'): array
    {
        $selection = $this->selection($form);
        $preview = $this->getJson(route('tax-returns.preview', ['company_id' => $company->id, ...$selection]))->assertOk()->json();

        return [...$selection, 'request_id' => (string) Str::uuid(), 'ledger_revision' => $preview['ledger_revision'], 'source_hash' => $preview['source_hash'], 'additional_tax' => '0.00', 'credits' => '0.00', 'surcharge' => '0.00', 'interest' => '0.00', 'compromise' => '0.00', 'reviewed' => true, 'review_note' => 'Reconciled to saved records.'];
    }

    public function test_frequency_tabs_cards_and_real_navigation_render(): void
    {
        [$company] = $this->book();
        foreach (['monthly', 'quarterly', 'annual'] as $frequency) {
            $this->get(route('tax-returns.index', ['company_id' => $company->id, 'frequency' => $frequency, 'year' => 2026, 'period' => 1]))->assertOk()->assertSee('Filing archive')->assertSee('Prepare worksheet')->assertSee('XML unavailable');
        }
        $this->get(route('sheets.sales', ['company_id' => $company->id]))->assertOk()->assertSee(route('tax-returns.index', ['company_id' => $company->id]));
    }

    public function test_only_posted_in_period_withholding_and_payroll_feed_previews(): void
    {
        [$company] = $this->book();
        $this->getJson(route('tax-returns.preview', ['company_id' => $company->id, ...$this->selection()]))->assertOk()->assertJsonPath('source_count', 1)->assertJsonPath('source_totals.gross', 44643)->assertJsonPath('source_totals.withheld', 893)->assertJsonPath('tax_before_credits', 893)->assertJsonPath('xml_available', false);
        $this->getJson(route('tax-returns.preview', ['company_id' => $company->id, ...$this->selection('1601-C')]))->assertOk()->assertJsonPath('source_count', 1)->assertJsonPath('source_totals.gross', 1150000)->assertJsonPath('tax_before_credits', 12345);
        $this->assertDatabaseCount('tax_return_preparations', 0);
    }

    public function test_preparation_saves_snapshot_and_exact_adjustments_idempotently_without_changing_ledger(): void
    {
        [$company, , $ledger] = $this->book();
        $original = $ledger->getRawOriginal('state');
        $input = [...$this->input($company), 'additional_tax' => '1.00', 'credits' => '2.00', 'interest' => '0.10'];
        $url = route('tax-returns.prepare', ['company_id' => $company->id]);
        $id = $this->postJson($url, $input)->assertCreated()->json('id');
        $this->postJson($url, $input)->assertOk()->assertJsonPath('id', $id)->assertJsonPath('replayed', true);
        $this->postJson($url, [...$input, 'credits' => '3.00'])->assertConflict();
        $row = DB::table('tax_return_preparations')->find($id);
        $snapshot = json_decode($row->snapshot, true);
        $this->assertSame(803, $snapshot['calculation']['payable']);
        $this->assertSame('prepared', $row->status);
        $this->assertSame($original, $company->run(fn (): string => $ledger->fresh()->getRawOriginal('state')));
        $this->get(route('tax-returns.show', ['id' => $id, 'company_id' => $company->id]))->assertOk()->assertSee('Preparation worksheet')->assertSee('8.03');
        $this->get(route('tax-returns.download', ['id' => $id, 'company_id' => $company->id]))->assertOk()->assertDownload('tax-worksheet-0619-E-2026-10-31-'.$id.'.csv');
        $this->assertDatabaseCount('tax_return_preparations', 1);
    }

    public function test_source_or_profile_changes_require_a_fresh_review(): void
    {
        [$company, , $ledger] = $this->book();
        $input = $this->input($company);
        $company->run(fn () => CompanySetting::query()->where('company_id', $company->id)->firstOrFail()->update(['profile' => ['company_name' => 'Changed name', 'tin' => '123456789', 'branch_code' => '00000', 'rdo' => '033']]));
        $this->postJson(route('tax-returns.prepare', ['company_id' => $company->id]), $input)->assertConflict();
        $ledger->update(['revision' => 2]);
        $this->postJson(route('tax-returns.prepare', ['company_id' => $company->id]), $input)->assertConflict();
        $this->assertDatabaseCount('tax_return_preparations', 0);
    }

    public function test_manual_forms_require_an_explicit_reviewed_amount_and_confirmation(): void
    {
        [$company] = $this->book();
        $input = $this->input($company, '0619-F');
        $url = route('tax-returns.prepare', ['company_id' => $company->id]);
        $this->postJson($url, $input)->assertUnprocessable()->assertJsonValidationErrors('reviewed_tax');
        $this->postJson($url, [...$input, 'reviewed_tax' => '0.00', 'reviewed' => false])->assertUnprocessable()->assertJsonValidationErrors('reviewed');
        $id = $this->postJson($url, [...$input, 'reviewed_tax' => '0.00'])->assertCreated()->json('id');
        $this->get(route('tax-returns.show', ['company_id' => $company->id, 'id' => $id]))->assertOk()->assertSee('No automatic amounts have been inferred');
        $csv = $this->get(route('tax-returns.download', ['company_id' => $company->id, 'id' => $id]))->assertOk()->streamedContent();
        $this->assertStringContainsString('no automatic source totals inferred', $csv);
    }

    public function test_invalid_applicability_and_periods_cannot_be_prepared(): void
    {
        [$company] = $this->book();
        foreach ([['form' => '0619-E', 'year' => 2026, 'period' => 12], ['form' => '1702Q', 'year' => 2026, 'period' => 4], ['form' => '1701Q', 'year' => 2026, 'period' => 1], ['form' => '2551Q', 'year' => 2026, 'period' => 1], ['form' => '1702Q', 'year' => 2026, 'period' => 10]] as $selection) {
            $this->getJson(route('tax-returns.preview', ['company_id' => $company->id, ...$selection]))->assertUnprocessable();
        }
    }

    public function test_information_return_is_not_a_second_tax_payment(): void
    {
        [$company] = $this->book();
        $selection = ['form' => '1604-E', 'year' => 2026, 'period' => 1];
        $preview = $this->getJson(route('tax-returns.preview', ['company_id' => $company->id, ...$selection]))->assertOk()->assertJsonPath('tax_before_credits', 0)->assertJsonPath('source_totals.withheld', 893)->json();
        $input = [...$this->input($company), ...$selection, 'source_hash' => $preview['source_hash']];
        $url = route('tax-returns.prepare', ['company_id' => $company->id]);
        $this->postJson($url, [...$input, 'additional_tax' => '1.00'])->assertUnprocessable();
        $this->postJson($url, $input)->assertCreated();
    }

    public function test_external_filing_requires_reference_date_and_confirmation_and_never_overwrites_snapshot(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00:00', 'Asia/Manila'));
        [$company] = $this->book();
        $id = $this->postJson(route('tax-returns.prepare', ['company_id' => $company->id]), $this->input($company))->assertCreated()->json('id');
        $snapshot = DB::table('tax_return_preparations')->find($id)->snapshot;
        $url = route('tax-returns.filed', ['company_id' => $company->id, 'id' => $id]);
        $this->postJson($url, ['reference' => 'TR-123', 'filed_on' => '2026-11-01'])->assertUnprocessable();
        $this->postJson($url, ['reference' => 'TR-123', 'filed_on' => '2026-11-10', 'confirmed' => true])->assertUnprocessable();
        $input = ['reference' => 'TR-123', 'filed_on' => '2026-11-01', 'confirmed' => true];
        $this->postJson($url, $input)->assertOk();
        $this->postJson($url, $input)->assertOk();
        $this->postJson($url, [...$input, 'reference' => 'DIFFERENT'])->assertConflict();
        $this->assertSame($snapshot, DB::table('tax_return_preparations')->find($id)->snapshot);
        $this->assertDatabaseHas('tax_return_preparations', ['id' => $id, 'status' => 'filed', 'filing_reference' => 'TR-123']);
    }

    public function test_permissions_and_company_isolation_cover_previews_archive_and_downloads(): void
    {
        [$company] = $this->book();
        $id = $this->postJson(route('tax-returns.prepare', ['company_id' => $company->id]), $this->input($company))->assertCreated()->json('id');
        $foreign = Company::factory()->create();
        $this->getJson(route('tax-returns.preview', ['company_id' => $foreign->id, ...$this->selection()]))->assertForbidden();
        $staff = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($staff, ['role' => 'staff', 'allowed_tabs' => json_encode(['dashboard', 'sheets'])]);
        $this->actingAs($staff)->getJson(route('tax-returns.index', ['company_id' => $company->id]))->assertForbidden();
        $this->getJson(route('tax-returns.download', ['company_id' => $company->id, 'id' => $id]))->assertForbidden();
        $this->postJson(route('tax-returns.filed', ['company_id' => $company->id, 'id' => $id]), ['reference' => 'FAKE'])->assertForbidden();
    }

    public function test_fiscal_and_calendar_periods_and_standard_deadlines_are_distinct(): void
    {
        $catalog = app(TaxReturnCatalog::class);
        $profile = ['accounting_period' => 'fiscal', 'fiscal_end_month' => 6, 'vat_type' => 'vat'];
        $corporate = $catalog->period('1702Q', 2026, 1, $profile);
        $this->assertSame('2025-07-01', $corporate['from']);
        $this->assertSame('2025-09-30', $corporate['to']);
        $this->assertSame('2025-11-29', $corporate['due']);
        $this->assertSame('2026-01-01', $catalog->period('1601-EQ', 2026, 1, $profile)['from']);
        $this->assertSame('2027-01-15', $catalog->period('1601-C', 2026, 12, $profile)['due']);
        $this->assertSame('2027-03-01', $catalog->period('1604-E', 2026, 1, $profile)['due']);
    }

    public function test_saved_rates_preserve_four_decimal_precision_and_unknown_atcs_are_rejected(): void
    {
        [$company, , $ledger] = $this->book();
        $state = $ledger->state;
        $state['wtpCertificates'][0]['ewt'] = '2.1234';
        $company->run(fn () => $ledger->update(['state' => $state]));
        $url = route('tax-returns.preview', ['company_id' => $company->id, ...$this->selection()]);
        $this->getJson($url)->assertOk()->assertJsonPath('source_totals.withheld', 948);
        $state['wtpCertificates'][0]['ewtCode'] = 'WC999';
        $company->run(fn () => $ledger->update(['state' => $state]));
        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('sources');
    }

    public function test_guests_cannot_access_preparation_or_archive(): void
    {
        $this->getJson(route('tax-returns.index'))->assertUnauthorized();
        $this->postJson(route('tax-returns.prepare'), [])->assertUnauthorized();
    }
}
