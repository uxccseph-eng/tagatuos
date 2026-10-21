<?php

namespace Tests\Feature;

use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MastersImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Import QA', 'full_name' => 'Preview']]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function preview(string $registry, array $rows): string
    {
        $response = $this->postJson('/masters/'.$registry.'/import/preview', ['records' => $rows])->assertOk();
        $this->assertSame([], $response->json('errors'), json_encode($response->json()));

        return $response->json('preview_token');
    }

    public function test_all_five_pages_include_templates_and_the_preview_import_controls(): void
    {
        foreach (['chart-of-accounts', 'parties', 'tax-codes', 'bank-accounts', 'atc-codes'] as $registry) {
            $this->get('/masters/'.$registry)->assertOk()->assertSee('data-masters-import', false)->assertSee('masters-import.js')->assertSee('masters-import-config');
        }
    }

    public function test_preview_does_not_write_and_commit_generates_distinct_codes_and_preserves_zero_tins(): void
    {
        $rows = [['name' => 'First Customer', 'address' => '10 Sample Street', 'tin' => '000-000-000-00000', 'classification' => 'Customer', 'tin' => '000-000-000-00000'], ['name' => 'Second Customer', 'address' => '10 Sample Street', 'classification' => 'Both', 'tin' => '000-000-000-00000']];
        $token = $this->preview('parties', $rows);
        $this->assertDatabaseCount('parties', 0);
        $this->postJson('/masters/parties/import', ['records' => $rows, 'preview_token' => $token])->assertCreated()->assertJsonPath('records.0.code', 'CUS-000001')->assertJsonPath('records.1.code', 'PTY-000001')->assertJsonPath('records.0.tin', '000-000-000-00000');
        $this->assertDatabaseCount('parties', 2);
        $this->postJson('/masters/parties/import', ['records' => $rows, 'preview_token' => $token])->assertUnprocessable();
        $this->assertDatabaseCount('parties', 2);
    }

    public function test_duplicates_within_batch_include_names_codes_and_formatted_nonplaceholder_tins(): void
    {
        $base = ['code' => 'CUS-A', 'name' => 'Sample Client', 'address' => '10 Sample Street', 'tin' => '000-000-000-00000', 'classification' => 'Customer', 'tin' => '123-456-789-00000'];
        foreach ([['code' => 'CUS-B', 'name' => ' sample   CLIENT ', 'tin' => '000-000-000-00000'], ['code' => 'cus-a', 'name' => 'Another', 'tin' => '000-000-000-00000'], ['code' => 'CUS-C', 'name' => 'Different', 'tin' => '12345678900000']] as $other) {
            $this->postJson('/masters/parties/import/preview', ['records' => [$base, [...$base, ...$other]]])->assertOk()->assertJsonPath('errors.0.row', 3)->assertJsonPath('preview_token', null);
        }
        $this->assertDatabaseCount('parties', 0);
    }

    public function test_archived_duplicates_are_blocked_and_other_companies_remain_isolated(): void
    {
        $this->get('/masters/parties');
        $party = Party::query()->firstOrFail();
        $party->update(['name' => 'Archived Customer', 'archived' => true]);
        $row = ['name' => 'archived customer', 'address' => '10 Sample Street', 'tin' => '000-000-000-00000', 'classification' => 'Customer'];
        $this->postJson('/masters/parties/import/preview', ['records' => [$row]])->assertOk()->assertJsonPath('errors.0.field', 'name')->assertJsonPath('preview_token', null);
        $this->withSession(['company_setup' => ['company_name' => 'Other Import QA', 'full_name' => 'Preview']]);
        $token = $this->preview('parties', [$row]);
        $this->postJson('/masters/parties/import', ['records' => [$row], 'preview_token' => $token])->assertCreated();
        $this->assertDatabaseCount('parties', 3);
    }

    public function test_errors_block_the_entire_batch_and_revalidation_catches_new_duplicates(): void
    {
        $rows = [['name' => 'First Customer', 'address' => '10 Sample Street', 'tin' => '000-000-000-00000', 'classification' => 'Customer'], ['name' => 'Second Customer', 'address' => '10 Sample Street', 'tin' => '000-000-000-00000', 'classification' => 'Customer']];
        $token = $this->preview('parties', $rows);
        $this->postJson('/masters/parties', ['code' => 'LATE', 'name' => 'Second Customer', 'address' => '10 Sample Street', 'tin' => '000-000-000-00000', 'classification' => 'Supplier'])->assertCreated();
        $this->postJson('/masters/parties/import', ['records' => $rows, 'preview_token' => $token])->assertUnprocessable()->assertJsonValidationErrors('records.1.name');
        $this->assertDatabaseMissing('parties', ['name' => 'First Customer']);
    }

    public function test_changed_expired_or_unpreviewed_payloads_cannot_be_saved(): void
    {
        $rows = [['name' => 'Test Customer', 'address' => '10 Sample Street', 'tin' => '000-000-000-00000', 'classification' => 'Customer']];
        $this->postJson('/masters/parties/import', ['records' => $rows])->assertUnprocessable()->assertJsonValidationErrors('preview');
        $token = $this->preview('parties', $rows);
        $this->postJson('/masters/parties/import', ['records' => [[...$rows[0], 'name' => 'Altered']], 'preview_token' => $token])->assertUnprocessable();
        $this->travel(16)->minutes();
        $this->postJson('/masters/parties/import', ['records' => $rows, 'preview_token' => $token])->assertUnprocessable();
        $this->assertDatabaseCount('parties', 0);
    }

    public function test_account_import_reserves_manual_codes_and_validates_types_flags_and_controls(): void
    {
        $rows = [['name' => 'Imported Asset', 'type' => 'Asset'], ['code' => '1591', 'name' => 'Manual Asset', 'type' => 'Asset']];
        $token = $this->preview('chart-of-accounts', $rows);
        $this->postJson('/masters/chart-of-accounts/import', ['records' => $rows, 'preview_token' => $token])->assertCreated()->assertJsonPath('records.0.code', '1592')->assertJsonPath('records.0.normal_balance', 'Debit')->assertJsonPath('records.0.statement_section', 'Current Assets');
        foreach ([['name' => 'Bad Cash', 'type' => 'Income', 'is_cash_bank' => 'Yes'], ['name' => 'Bad Control', 'type' => 'Asset', 'is_control' => 'Yes'], ['name' => 'Bad Flag', 'type' => 'Asset', 'is_cash_bank' => 'maybe']] as $row) {
            $response = $this->postJson('/masters/chart-of-accounts/import/preview', ['records' => [$row]])->assertOk();
            $this->assertNotEmpty($response->json('errors'));
        }
        $this->assertDatabaseCount('chart_accounts', 2);
    }

    public function test_bank_import_requires_active_company_cash_accounts_and_blocks_account_number_duplicates(): void
    {
        $this->get('/masters/bank-accounts');
        $row = ['name' => 'Operating Bank', 'bank' => 'Sample Bank', 'account_number' => '001-234', 'gl_account' => '1010'];
        $token = $this->preview('bank-accounts', [$row]);
        $this->postJson('/masters/bank-accounts/import', ['records' => [$row], 'preview_token' => $token])->assertCreated()->assertJsonPath('records.0.currency', 'PHP')->assertJsonPath('records.0.account_number', '001-234');
        $this->postJson('/masters/bank-accounts/import/preview', ['records' => [[...$row, 'name' => 'Another Bank Label', 'bank' => 'sample bank', 'account_number' => '001234']]])->assertOk()->assertJsonPath('errors.0.field', 'account_number');
        $this->postJson('/masters/bank-accounts/import/preview', ['records' => [[...$row, 'name' => 'Invalid Bank', 'account_number' => '002345', 'gl_account' => '4000']]])->assertOk()->assertJsonPath('errors.0.field', 'gl_account');
    }

    public function test_tax_import_validates_each_rows_kind_and_rejects_redundant_atc_rates(): void
    {
        $rows = [['name' => 'Custom Tax', 'kind' => 'VAT', 'rate' => '7.1250'], ['name' => 'Withholding reference', 'kind' => 'Withholding', 'rate' => '2', 'atc' => 'WC160']];
        $token = $this->preview('tax-codes', $rows);
        $this->postJson('/masters/tax-codes/import', ['records' => $rows, 'preview_token' => $token])->assertCreated()->assertJsonPath('records.0.rate', '7.1250')->assertJsonPath('records.1.code', 'TAX-000002');
        $this->postJson('/masters/tax-codes/import/preview', ['records' => [[...$rows[1], 'name' => 'Duplicate Withholding']]])->assertOk()->assertJsonPath('errors.0.field', 'atc');
        $this->postJson('/masters/tax-codes/import/preview', ['records' => [['code' => 'WC100', 'name' => 'Reserved official', 'kind' => 'Withholding', 'rate' => '1', 'atc' => 'WC100']]])->assertOk()->assertJsonPath('errors.0.field', 'code');
    }

    public function test_custom_atc_import_preserves_official_catalog_and_reaches_sheet_choices(): void
    {
        $row = ['code' => 'WC999', 'name' => 'Custom imported withholding', 'rate' => '2.5'];
        $token = $this->preview('atc-codes', [$row]);
        $this->postJson('/masters/atc-codes/import', ['records' => [$row], 'preview_token' => $token])->assertCreated();
        $this->get('/masters/chart-of-accounts/catalog.js')->assertOk()->assertSee('Custom imported withholding')->assertSee('WC999');
        $this->postJson('/masters/atc-codes/import/preview', ['records' => [[...$row, 'code' => 'WC160']]])->assertOk()->assertJsonPath('errors.0.field', 'code');
        $this->postJson('/masters/atc-codes/import/preview', ['records' => [[...$row, 'code' => '']]])->assertOk()->assertJsonPath('errors.0.field', 'code');
    }

    public function test_rejects_unknown_columns_nested_details_and_oversized_batches(): void
    {
        foreach ([[['name' => 'Unknown', 'address' => '10 Sample Street', 'tin' => '000-000-000-00000', 'classification' => 'Customer', 'archived' => true]], [['name' => ['Bad'], 'address' => '10 Sample Street', 'tin' => '000-000-000-00000', 'classification' => 'Customer']]] as $rows) {
            $this->postJson('/masters/parties/import/preview', ['records' => $rows])->assertOk()->assertJsonPath('preview_token', null);
        }
        $this->postJson('/masters/parties/import/preview', ['records' => array_fill(0, 501, ['name' => 'Test', 'address' => '10 Sample Street', 'tin' => '000-000-000-00000', 'classification' => 'Customer'])])->assertUnprocessable();
        $this->postJson('/masters/parties/import/preview', ['records' => ['key' => ['name' => 'Test', 'address' => '10 Sample Street', 'tin' => '000-000-000-00000', 'classification' => 'Customer']]])->assertUnprocessable();
        $this->assertDatabaseCount('parties', 0);
    }

    public function test_import_endpoints_require_access_company_and_csrf(): void
    {
        $row = ['name' => 'Test', 'address' => '10 Sample Street', 'tin' => '000-000-000-00000', 'classification' => 'Customer'];
        $this->withSession(['preview_access' => false])->postJson('/masters/parties/import/preview', ['records' => [$row]])->assertForbidden();
        $this->withSession(['preview_access' => true, 'company_setup' => null])->postJson('/masters/parties/import', ['records' => [$row]])->assertForbidden();
        $this->app->instance('env', 'production');
        $this->postJson('/masters/parties/import/preview', ['records' => [$row]])->assertStatus(419);
    }

    public function test_generated_codes_are_unique_within_a_classification_and_changed_allocations_need_a_new_preview(): void
    {
        $rows = [['name' => 'Customer A', 'address' => '10 Sample Street', 'tin' => '000-000-000-00000', 'classification' => 'Customer'], ['name' => 'Customer B', 'address' => '10 Sample Street', 'tin' => '000-000-000-00000', 'classification' => 'Customer']];
        $token = $this->preview('parties', $rows);
        $this->postJson('/masters/parties', ['code' => 'CUS-000001', 'name' => 'Another Customer', 'address' => '10 Sample Street', 'tin' => '000-000-000-00000', 'classification' => 'Customer'])->assertCreated();
        $this->postJson('/masters/parties/import', ['records' => $rows, 'preview_token' => $token])->assertUnprocessable()->assertJsonValidationErrors('preview');
        $token = $this->preview('parties', $rows);
        $this->postJson('/masters/parties/import', ['records' => $rows, 'preview_token' => $token])->assertCreated()->assertJsonPath('records.0.code', 'CUS-000002')->assertJsonPath('records.1.code', 'CUS-000003');
    }

    public function test_reserved_default_names_and_invalid_zero_account_codes_do_not_bypass_validation(): void
    {
        $this->postJson('/masters/parties/import/preview', ['records' => [['name' => 'various customers', 'address' => '10 Sample Street', 'tin' => '000-000-000-00000', 'classification' => 'Customer']]])->assertOk()->assertJsonPath('errors.0.field', 'name');
        $this->postJson('/masters/chart-of-accounts/import/preview', ['records' => [['code' => '0', 'name' => 'Invalid zero code', 'type' => 'Asset']]])->assertOk()->assertJsonPath('errors.0.field', 'code');
    }
}
