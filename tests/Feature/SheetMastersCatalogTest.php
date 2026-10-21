<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\ChartAccount;
use App\Models\Party;
use App\Models\TaxCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SheetMastersCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_relief_catalog_returns_current_company_categories_and_rejects_unauthorized_access(): void
    {
        $this->getJson('/masters/chart-of-accounts/relief-catalog')->assertForbidden();
        $company = ['company_name' => 'RELIEF category QA', 'full_name' => 'QA user'];
        ChartAccount::factory()->create(['workspace_key' => ChartAccount::workspaceKey($company), 'code' => '7110', 'relief_category' => 'services']);
        ChartAccount::factory()->create(['code' => '7110', 'relief_category' => 'capital_goods']);
        $this->withSession(['preview_access' => true, 'company_setup' => $company]);
        $this->getJson('/masters/chart-of-accounts/relief-catalog')->assertOk()->assertJsonPath('categories.goods', 'Other-than-Capital Goods')->assertJsonPath('accounts.0.relief_category', 'services')->assertJsonCount(1, 'accounts');
        ChartAccount::query()->where('workspace_key', ChartAccount::workspaceKey($company))->update(['relief_category' => 'goods']);
        $this->getJson('/masters/chart-of-accounts/relief-catalog')->assertOk()->assertJsonPath('accounts.0.relief_category', 'goods');
        $this->get('/masters/chart-of-accounts')->assertOk()->assertSee('RELIEF category (Sales / Purchases)')->assertSee('Other-than-Capital Goods');
    }

    public function test_catalog_is_company_scoped_escaped_and_includes_active_and_historical_master_data(): void
    {
        $company = ['company_name' => 'Catalog QA', 'full_name' => 'Preview', 'tin' => ''];
        $key = ChartAccount::workspaceKey($company);
        ChartAccount::seedWorkspace($key);
        $account = ChartAccount::query()->where('workspace_key', $key)->where('code', '1020')->firstOrFail();
        Party::factory()->create(['workspace_key' => $key, 'name' => 'Master Customer', 'tin' => '123-456-789-00000', 'classification' => 'Customer']);
        Party::factory()->create(['name' => 'Other company private customer']);
        TaxCode::factory()->create(['workspace_key' => $key, 'code' => 'CUSTOM', 'name' => 'Custom rate', 'rate' => 7.5]);
        BankAccount::factory()->create(['workspace_key' => $key, 'chart_account_id' => $account->id, 'name' => 'Main Operating Bank']);
        $this->withSession(['preview_access' => true, 'company_setup' => $company])->get('/masters/chart-of-accounts/catalog.js')->assertOk()->assertSee('window.WorkspaceMasters')->assertSee('Master Customer')->assertSee('Main Operating Bank')->assertSee('CUSTOM')->assertSee('WI156')->assertDontSee('Other company private customer');
        $this->withSession(['preview_access' => false])->get('/masters/chart-of-accounts/catalog.js')->assertForbidden();
    }

    public function test_every_sheet_loads_master_choices_before_the_ledger(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Catalog QA', 'full_name' => 'Preview']]);
        foreach (['sales', 'collections', 'purchases', 'payments', 'cwt', 'wtp', 'journal'] as $sheet) {
            $this->get('/sheets/'.$sheet)->assertOk()->assertSee('data-sheet-import', false)->assertSee('data-period-from', false)->assertSee('data-period-to', false)->assertSeeInOrder(['sheet-posting.js', 'working-period.js', 'catalog.js', 'sheet-masters.js', 'workspace-ledger.js', 'sheet-import.js', 'sheet-import-ui.js']);
        }
    }

    public function test_withholding_sheets_have_no_competing_hardcoded_atc_options(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Catalog QA', 'full_name' => 'Preview']]);
        foreach (['sales', 'purchases', 'wtp'] as $sheet) {
            $html = $this->get('/sheets/'.$sheet)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/<option[^>]*>[^<]*W[ICVB][0-9]{3}[^<]*<\/option>/', $html);
        }
    }

    public function test_sheet_creators_save_scoped_records_and_custom_atc_choices_without_replacing_official_codes(): void
    {
        $company = ['company_name' => 'Quick create QA', 'full_name' => 'Preview'];
        $this->withSession(['preview_access' => true, 'company_setup' => $company]);
        $this->postJson('/masters/parties', ['code' => 'QUICK-CUSTOMER', 'name' => 'Quick customer', 'tin' => '000-000-000-00000', 'address' => '10 Sample Street', 'classification' => 'Customer'])->assertCreated();
        $this->postJson('/masters/tax-codes', ['code' => 'WC999', 'name' => 'Custom withholding QA', 'kind' => 'Withholding', 'rate' => 2.5, 'atc' => 'WC999'])->assertCreated();
        $this->postJson('/masters/tax-codes', ['code' => 'WC160', 'name' => 'Cannot replace catalog', 'kind' => 'Withholding', 'rate' => 99, 'atc' => 'WC160'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->get('/masters/chart-of-accounts/catalog.js')->assertOk()->assertSee('Quick customer')->assertSee('Custom withholding QA')->assertSee('csrfToken')->assertSee('endpoints');
        $this->get('/masters/atc-codes')->assertOk()->assertSee('Custom withholding QA');
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Other quick company', 'full_name' => 'Preview']])->get('/masters/chart-of-accounts/catalog.js')->assertOk()->assertDontSee('Quick customer')->assertDontSee('Custom withholding QA');
    }
}
