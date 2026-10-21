<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class BirPurchasesDatTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchases_export_requires_a_workspace(): void
    {
        $this->postJson('/reports/generate-purchases-dat', $this->payload())->assertForbidden();
        $this->withSession(['preview_access' => true])->postJson('/reports/generate-purchases-dat', $this->payload())->assertForbidden();
    }

    public function test_monthly_purchase_file_matches_the_repository_header_and_detail_layout(): void
    {
        $this->workspace();
        $response = $this->postJson('/reports/generate-purchases-dat', $this->payload());
        $response->assertOk()->assertDownload('123456789P102026.DAT')->assertHeader('Content-Type', 'text/plain; charset=windows-1252');
        $lines = explode("\r\n", rtrim($response->streamedContent(), "\r\n"));
        $this->assertCount(2, $lines);
        $this->assertSame('H,P,"123456789","TEST COMPANY","","","","TRADE","COMPANY ST.","CITY",50.00,75.00,100.00,200.00,300.00,72.00,66.00,6.00,001,10/31/2026,12', $lines[0]);
        $this->assertSame('D,P,"234567890","ACME SUPPLIER","","","","1 SUPPLIER ST.","CITY",50,75,100,200,300,72,123456789,10/31/2026', $lines[1]);
        $this->assertCount(21, str_getcsv($lines[0], ',', '"', ''));
        $this->assertCount(17, str_getcsv($lines[1], ',', '"', ''));
    }

    public function test_supplier_groups_sort_by_name_and_keep_different_tins_separate(): void
    {
        $this->workspace();
        $payload = $this->payload();
        $payload['purchase_records'][] = [...$payload['purchase_records'][0], 'supplier_tin' => '34567890100000', 'supplier_name' => 'AAA Supplier'];
        $lines = explode("\r\n", rtrim($this->postJson('/reports/generate-purchases-dat', $payload)->assertOk()->streamedContent(), "\r\n"));
        $this->assertCount(3, $lines);
        $this->assertStringStartsWith('D,P,"345678901","AAA SUPPLIER"', $lines[1]);
        $this->assertStringStartsWith('D,P,"234567890","ACME SUPPLIER"', $lines[2]);
    }

    public function test_monthly_zip_handles_calendar_year_changes(): void
    {
        $this->workspace();
        $payload = $this->payload();
        $payload['from'] = '2026-12-01';
        $payload['to'] = '2027-01-31';
        $payload['purchase_records'] = [
            [...$payload['purchase_records'][0], 'date' => '2026-12-15'],
            [...$payload['purchase_records'][1], 'date' => '2027-01-15'],
        ];
        $response = $this->postJson('/reports/generate-purchases-dat', $payload)->assertOk()->assertDownload('RELIEF-Purchases-2026-12-01-to-2027-01-31.zip');
        $path = tempnam(sys_get_temp_dir(), 'purchases-test-');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path));
            $this->assertSame(2, $zip->numFiles);
            $this->assertStringContainsString('12/31/2026', $zip->getFromName('123456789P122026.DAT'));
            $this->assertStringContainsString('01/31/2027', $zip->getFromName('123456789P012027.DAT'));
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_invalid_supplier_tins_categories_and_vat_are_rejected(): void
    {
        $this->workspace();
        foreach (['', '000-000-000-00000', '12345678900001'] as $tin) {
            $payload = $this->payload();
            $payload['purchase_records'][0]['supplier_tin'] = $tin;
            $this->postJson('/reports/generate-purchases-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors(['purchase_records.0.supplier_tin']);
        }
        $payload = $this->payload();
        ChartAccount::query()->where('code', '7110')->update(['relief_category' => null]);
        $this->postJson('/reports/generate-purchases-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors(['purchase_records.0.relief_category']);
        ChartAccount::query()->where('code', '7110')->update(['relief_category' => 'services']);
        $payload = $this->payload();
        $payload['purchase_records'][0]['non_creditable_vat_amount'] = 13;
        $this->postJson('/reports/generate-purchases-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors(['purchase_records.0.non_creditable_vat_amount']);
        $payload = $this->payload();
        $payload['purchase_records'][0]['gross_amount'] = 999;
        $this->postJson('/reports/generate-purchases-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors(['purchase_records.0.gross_amount']);
    }

    public function test_empty_lists_and_dates_outside_the_selected_period_are_rejected(): void
    {
        $this->workspace();
        $payload = $this->payload();
        $payload['purchase_records'] = [];
        $this->postJson('/reports/generate-purchases-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors(['purchase_records']);
        $payload = $this->payload();
        $payload['purchase_records'][0]['date'] = '2027-01-01';
        $this->postJson('/reports/generate-purchases-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors(['purchase_records.0.date']);
    }

    public function test_export_uses_live_master_categories_instead_of_payload_overrides(): void
    {
        $this->workspace();
        $payload = $this->payload();
        $payload['purchase_records'][0]['relief_category'] = 'capital_goods';
        $header = str_getcsv(explode("\r\n", $this->postJson('/reports/generate-purchases-dat', $payload)->assertOk()->streamedContent())[0], ',', '"', '');
        $this->assertSame('100.00', $header[12]);
        $this->assertSame('200.00', $header[13]);
        ChartAccount::query()->where('code', '7110')->update(['relief_category' => 'goods']);
        $header = str_getcsv(explode("\r\n", $this->postJson('/reports/generate-purchases-dat', $payload)->assertOk()->streamedContent())[0], ',', '"', '');
        $this->assertSame('0.00', $header[12]);
        $this->assertSame('400.00', $header[14]);
    }

    public function test_accounts_from_another_company_cannot_supply_export_categories(): void
    {
        $this->workspace();
        ChartAccount::factory()->create(['code' => '7999', 'relief_category' => 'services']);
        $payload = $this->payload();
        $payload['purchase_records'][0]['account_code'] = '7999';
        $this->postJson('/reports/generate-purchases-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors(['purchase_records.0.account_code']);
    }

    private function workspace(): void
    {
        $company = ['company_name' => 'Test company'];
        foreach (['7110' => 'services', '1500' => 'capital_goods', '6150' => 'goods'] as $code => $category) {
            ChartAccount::factory()->create(['workspace_key' => ChartAccount::workspaceKey($company), 'code' => (string) $code, 'type' => $code === 1500 ? 'Asset' : 'Expense', 'name' => $category, 'relief_category' => $category]);
        }
        $this->withSession(['preview_access' => true, 'company_setup' => $company]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $common = ['account_code' => '7110', 'date' => '2026-10-16', 'supplier_tin' => '23456789000000', 'supplier_name' => 'Acme Supplier', 'supplier_street' => '1 Supplier St.', 'supplier_city' => 'City', 'exempt_amount' => 0, 'zero_rated_amount' => 0, 'taxable_amount' => 0, 'input_vat_amount' => 0, 'non_creditable_vat_amount' => 0, 'relief_category' => ''];

        return [
            'company_tin' => '12345678900000', 'company_name' => 'Test Company', 'non_individual' => true, 'trade_name' => 'Trade', 'street' => 'Company St.', 'city' => 'City', 'rdo' => '001', 'period_end' => 12, 'from' => '2026-10-01', 'to' => '2026-12-31',
            'purchase_records' => [
                [...$common, 'gross_amount' => 112, 'taxable_amount' => 100, 'input_vat_amount' => 12, 'non_creditable_vat_amount' => 2, 'relief_category' => 'services'],
                [...$common, 'account_code' => '1500', 'gross_amount' => 224, 'taxable_amount' => 200, 'input_vat_amount' => 24, 'relief_category' => 'capital_goods'],
                [...$common, 'account_code' => '6150', 'gross_amount' => 336, 'taxable_amount' => 300, 'input_vat_amount' => 36, 'non_creditable_vat_amount' => 4, 'relief_category' => 'goods'],
                [...$common, 'gross_amount' => 50, 'exempt_amount' => 50],
                [...$common, 'gross_amount' => 75, 'zero_rated_amount' => 75],
            ],
        ];
    }
}
