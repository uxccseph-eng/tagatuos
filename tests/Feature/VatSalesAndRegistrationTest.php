<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VatSalesAndRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function company(): array
    {
        return ['company_name' => 'VAT account test', 'full_name' => 'Test owner', 'tin' => '123456789', 'branch_code' => '000', 'vat_type' => 'vat'];
    }

    public function test_existing_income_accounts_are_classified_for_sales_relief_only_in_vat_companies(): void
    {
        $company = $this->company();
        $workspace = ChartAccount::workspaceKey($company);
        ChartAccount::seedWorkspace($workspace);
        ChartAccount::query()->where('workspace_key', $workspace)->where('code', '7110')->update(['relief' => true, 'relief_category' => 'services']);
        $this->withSession(['preview_access' => true, 'company_setup' => $company])->get('/masters/chart-of-accounts/relief-catalog')->assertOk()->assertJsonPath('categories.vatable_sales', 'Vatable Sales');
        foreach (['4000', '4020', '4050'] as $code) {
            $this->assertDatabaseHas('chart_accounts', ['workspace_key' => $workspace, 'code' => $code, 'relief' => true, 'relief_category' => 'vatable_sales']);
        }
        $this->assertDatabaseHas('chart_accounts', ['workspace_key' => $workspace, 'code' => '7110', 'relief_category' => 'services']);
        $other = [...$company, 'company_name' => 'Other VAT company'];
        ChartAccount::seedWorkspace(ChartAccount::workspaceKey($other));
        $this->assertDatabaseHas('chart_accounts', ['workspace_key' => ChartAccount::workspaceKey($other), 'code' => '4020', 'relief' => false]);
        $this->withSession(['company_setup' => [...$company, 'vat_type' => 'non_vat']])->get('/masters/chart-of-accounts/relief-catalog')->assertOk();
        $this->assertDatabaseHas('chart_accounts', ['workspace_key' => $workspace, 'code' => '4020', 'relief' => false, 'relief_category' => null]);
        $this->assertDatabaseHas('chart_accounts', ['workspace_key' => $workspace, 'code' => '7110', 'relief_category' => 'services']);
    }

    public function test_income_accounts_created_edited_and_imported_get_the_automatic_sales_tag(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => $this->company()]);
        $payload = ['code' => '4090', 'name' => 'New revenue', 'type' => 'Income', 'normal_balance' => 'Credit', 'statement_section' => 'Revenue', 'is_cash_bank' => false, 'is_advance' => false, 'is_control' => false, 'relief' => false, 'percentage_tax' => false, 'ewt' => false];
        $created = $this->postJson('/masters/chart-of-accounts', $payload)->assertCreated()->assertJsonPath('account.relief_category', 'vatable_sales')->assertJsonPath('account.relief', true);
        $this->putJson('/masters/chart-of-accounts/'.$created->json('account.id'), [...$payload, 'relief_category' => null])->assertOk()->assertJsonPath('account.relief_category', 'vatable_sales');
        $row = ['name' => 'Imported revenue', 'type' => 'Income'];
        $preview = $this->postJson('/masters/chart-of-accounts/import/preview', ['records' => [$row]])->assertOk()->assertJsonPath('errors', []);
        $this->postJson('/masters/chart-of-accounts/import', ['records' => [$row], 'preview_token' => $preview->json('preview_token')])->assertCreated()->assertJsonPath('records.0.relief_category', 'vatable_sales');
    }

    public function test_sales_dat_accepts_automatically_classified_income_without_manual_purchase_tags(): void
    {
        $company = $this->company();
        ChartAccount::seedWorkspace(ChartAccount::workspaceKey($company));
        $payload = ['company_tin' => '123456789000', 'company_name' => 'VAT account test', 'non_individual' => true, 'street' => 'Test Street', 'city' => 'Test City', 'rdo' => '001', 'period_end' => 12, 'from' => '2026-10-01', 'to' => '2026-10-31', 'sales_records' => [['date' => '2026-10-10', 'account_code' => '4020', 'customer_tin' => '234567890000', 'customer_name' => 'Test Customer', 'customer_address' => 'Customer Street', 'gross_amount' => 112, 'exempt_amount' => 0, 'zero_rated_amount' => 0, 'taxable_amount' => 100, 'output_vat_amount' => 12]]];
        $response = $this->withSession(['preview_access' => true, 'company_setup' => $company])->postJson('/reports/generate-sales-dat', $payload)->assertOk()->assertDownload('123456789S102026.DAT');
        $this->assertStringContainsString('100,12,123456789,10/31/2026', $response->streamedContent());
        $this->assertDatabaseHas('chart_accounts', ['workspace_key' => ChartAccount::workspaceKey($company), 'code' => '4020', 'relief_category' => 'vatable_sales']);
    }

    public function test_party_addresses_and_full_tins_are_required_on_create_update_and_import(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => $this->company()]);
        foreach (['Customer', 'Supplier', 'Both'] as $classification) {
            $payload = ['name' => 'Test '.$classification, 'classification' => $classification];
            $this->postJson('/masters/parties', $payload)->assertUnprocessable()->assertJsonValidationErrors(['tin', 'address']);
            $this->postJson('/masters/parties', [...$payload, 'tin' => '123456789', 'address' => 'Test Street'])->assertUnprocessable()->assertJsonValidationErrors('tin');
            $created = $this->postJson('/masters/parties', [...$payload, 'tin' => '123456789001', 'address' => 'Test Street'])->assertCreated()->assertJsonPath('record.tin', '123-456-789-00001');
            $this->putJson('/masters/parties/'.$created->json('record.id'), [...$payload, 'code' => $created->json('record.code'), 'tin' => '123-456-789-00001', 'address' => ' '])->assertUnprocessable()->assertJsonValidationErrors('address');
        }
        $preview = $this->postJson('/masters/parties/import/preview', ['records' => [['name' => 'Incomplete import', 'classification' => 'Customer']]])->assertOk()->assertJsonPath('preview_token', null);
        $this->assertEqualsCanonicalizing(['tin', 'address'], array_column($preview->json('errors'), 'field'));
    }

    public function test_company_registration_requires_separate_tin_and_branch_and_supports_legacy_full_tins(): void
    {
        $this->withSession(['preview_access' => true]);
        $payload = ['full_name' => 'Test Owner', 'preview_ack' => '1', 'vat_type' => 'vat', 'entity_type' => 'corporation', 'company_name' => 'New Test Company', 'contact_number' => '09170000000', 'accounting_period' => 'calendar', 'payroll' => 'no', 'supplier_tax' => 'none', 'customer_tax' => 'no', 'ppe' => 'no'];
        foreach (['', '123-456-789-000'] as $tin) {
            $this->post('/company/setup', [...$payload, 'tin' => $tin, 'branch_code' => '00000'])->assertSessionHasErrors('tin')->assertSessionMissing('company_setup');
        }
        $this->post('/company/setup', [...$payload, 'tin' => '123456789'])->assertSessionHasErrors('branch_code')->assertSessionMissing('company_setup');
        $this->post('/company/setup', [...$payload, 'tin' => '123456789', 'branch_code' => '00003'])->assertSessionHasNoErrors()->assertSessionHas('company_setup.tin', '123456789')->assertSessionHas('company_setup.branch_code', '00003');
        $this->post('/company/setup', [...$payload, 'tin' => '123-456-789-00007', 'branch_code' => '999'])->assertSessionHasNoErrors()->assertRedirect('/dashboard')->assertSessionHas('company_setup.tin', '123456789')->assertSessionHas('company_setup.branch_code', '00007');
    }
}
