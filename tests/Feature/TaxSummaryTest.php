<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\CompanyLedgerService;
use App\Services\Tax\BirVatLedgerMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaxSummaryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Company, User, CompanyLedger, CompanySetting} */
    private function book(): array
    {
        $user = User::factory()->firm()->create(['must_change_password' => false]);
        $book = Company::factory()->create();
        $book->users()->attach($user, ['role' => 'owner']);
        $setting = CompanySetting::factory()->create(['workspace_key' => $book->workspaceKey(), 'lookup_key' => $book->workspaceKey(), 'profile' => ['company_name' => 'VAT Summary Book', 'full_name' => $user->name, 'tin' => '123456789', 'branch_code' => '00000', 'rdo' => '033', 'vat_type' => 'vat', 'entity_type' => 'corporation']]);
        $state = CompanyLedgerService::emptyState();
        $sale = ['id' => 1, 'number' => 'S-1', 'date' => '2026-01-10', 'status' => 'posted', 'gross' => 1120000, 'tax' => 'VAT (12%)'];
        $state['sales'] = [$sale, [...$sale, 'id' => 2, 'status' => 'draft'], [...$sale, 'id' => 3, 'status' => 'void'], [...$sale, 'id' => 4, 'date' => '2027-01-01']];
        $state['purchases'] = [[...$sale, 'number' => 'P-1', 'gross' => 560000], [...$sale, 'id' => 2, 'tax' => 'NR']];
        $ledger = CompanyLedger::factory()->create(['company_id' => $book->id, 'revision' => 1, 'state' => $state]);
        $this->actingAs($user)->withSession(['active_company_id' => $book->id]);

        return [$book, $user, $ledger, $setting];
    }

    public function test_posted_sources_only_and_unknown_credits_are_not_assumed_zero(): void
    {
        [$book, , $ledger] = $this->book();
        $response = $this->get(route('tax-summary.index', ['company_id' => $book->id, 'year' => 2026]))->assertOk()->assertSee('Tax Summary')->assertSee('Tagged payments');
        $summary = $response->viewData('summary');
        $this->assertSame(120000, $summary['annual']['output']);
        $this->assertSame(60000, $summary['annual']['input']);
        $this->assertSame(60000, $summary['quarters'][1]['amounts']['base']);
        $this->assertNull($summary['quarters'][1]['amounts']['payable']);
        $this->assertSame(3, count($summary['quarters'][1]['sources']));
        $this->assertDatabaseCount('tax_return_preparations', 0);
        $this->assertSame(1, $ledger->fresh()->revision);
        $this->get(route('sheets.sales', ['company_id' => $book->id]))->assertOk()->assertSee(route('tax-summary.index', ['company_id' => $book->id]));
    }

    public function test_saved_carryover_and_credits_reconcile_and_later_book_changes_are_detected(): void
    {
        [$book, $user, $ledger] = $this->book();
        $mapped = app(BirVatLedgerMapper::class)->map($ledger->state['sales'], $ledger->state['purchases'], ['from' => '2026-01-01', 'to' => '2026-03-31']);
        DB::table('tax_return_preparations')->insert(['company_id' => $book->id, 'request_id' => (string) Str::uuid(), 'prepared_by' => $user->id, 'form' => '2550Q', 'period_from' => '2026-01-01', 'period_to' => '2026-03-31', 'ledger_revision' => 1, 'source_hash' => str_repeat('a', 64), 'payload_hash' => str_repeat('b', 64), 'original_slot' => 1, 'amended' => false, 'revision' => 1, 'status' => 'filed', 'snapshot' => json_encode(['xml_vat_ledger' => $mapped, 'bir_xml' => ['fields' => ['frm2550qv2024:inputTaxCarried' => '100.00']], 'calculation' => ['credits' => 1000, 'additional_tax' => 0, 'surcharge' => 200, 'interest' => 0, 'compromise' => 0, 'payable' => 49200]], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        $url = route('tax-summary.index', ['company_id' => $book->id, 'year' => 2026]);
        $summary = $this->get($url)->assertOk()->assertSee('Filing recorded')->viewData('summary');
        $this->assertSame(49200, $summary['quarters'][1]['amounts']['payable']);
        $this->assertSame('Matches saved sources', $summary['quarters'][1]['reconciliation']);
        $state = $ledger->state;
        $state['sales'][0]['gross'] = 2240000;
        $ledger->update(['state' => $state, 'revision' => 2]);
        $this->get($url)->assertOk()->assertSee('Sources changed')->assertSee('492.00');
        $this->assertDatabaseCount('tax_return_preparations', 1);
    }

    public function test_fiscal_periods_unsupported_sources_and_non_vat_registration_are_visible(): void
    {
        [$book, , $ledger, $setting] = $this->book();
        $setting->update(['profile' => [...$setting->profile, 'accounting_period' => 'fiscal', 'fiscal_end_month' => 6, 'vat_type' => 'non_vat']]);
        $state = $ledger->state;
        $state['purchases'][] = ['id' => 5, 'number' => 'IMPORT-5', 'date' => '2026-01-12', 'status' => 'posted', 'gross' => 112000, 'tax' => 'VAT (12%)', 'isImportation' => true];
        $ledger->update(['state' => $state]);
        $response = $this->get(route('tax-summary.index', ['company_id' => $book->id, 'year' => 2026]))->assertOk()->assertSee('not set as VAT-registered')->assertSee('importation / non-resident VAT');
        $summary = $response->viewData('summary');
        $this->assertSame('2025-07-01', $summary['quarters'][1]['period']['from']);
        $this->assertSame(120000, $summary['quarters'][3]['amounts']['output']);
        $this->assertNull($summary['quarters'][3]['amounts']['payable']);
    }

    public function test_company_and_role_boundaries_apply_to_summary_and_year_is_validated(): void
    {
        [$book, $user] = $this->book();
        $other = Company::factory()->create();
        $this->get(route('tax-summary.index', ['company_id' => $other->id]))->assertForbidden();
        $this->getJson(route('tax-summary.index', ['company_id' => $book->id, 'year' => 2200]))->assertUnprocessable();
        $book->users()->updateExistingPivot($user->id, ['role' => 'staff', 'allowed_tabs' => json_encode(['sheets'])]);
        $this->get(route('tax-summary.index', ['company_id' => $book->id]))->assertForbidden();
    }

    public function test_vat_and_income_summary_use_the_selected_company_workspace(): void
    {
        [$book] = $this->book();

        foreach (['vat', 'income'] as $tax) {
            $this->get(route('tax-summary.index', ['company_id' => $book->id, 'year' => 2026, 'tax' => $tax]))
                ->assertOk()
                ->assertSee('data-company-workspace="'.$book->id.'"', false)
                ->assertSee('VAT Summary Book')
                ->assertSee('aria-label="Switch company book"', false)
                ->assertSee(route('sheets.sales', ['company_id' => $book->id]))
                ->assertSee('Company book')
                ->assertDontSee('Search client books')
                ->assertDontSee('Firm workspace');
        }
    }
}
