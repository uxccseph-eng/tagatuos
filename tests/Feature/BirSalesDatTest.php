<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class BirSalesDatTest extends TestCase
{
    use RefreshDatabase;

    public function test_dat_export_requires_a_workspace(): void
    {
        $this->postJson('/reports/generate-sales-dat', $this->payload())->assertForbidden();
        $this->withSession(['preview_access' => true])->postJson('/reports/generate-sales-dat', $this->payload())->assertForbidden();
    }

    public function test_single_month_matches_the_repository_sales_layout(): void
    {
        $this->workspace();
        $response = $this->postJson('/reports/generate-sales-dat', $this->payload());
        $response->assertOk()->assertDownload('123456789S102026.DAT')->assertHeader('Content-Type', 'text/plain; charset=windows-1252');
        $content = $response->streamedContent();
        $lines = explode("\r\n", rtrim($content, "\r\n"));
        $this->assertSame('H,S,"123456789","TEST COMPANY INC.","","","","TEST TRADE","123 MAIN ST.","CITY",10.50,0.00,100.00,12.00,001,10/31/2026,12', $lines[0]);
        $this->assertSame('D,S,"","VARIOUS CUSTOMERS","","","","123 MAIN ST. CITY","",0,0,100,12,123456789,10/31/2026', $lines[1]);
        $this->assertSame('D,S,"234567890","SECOND CUSTOMER","","","","","",10.5,0,0,0,123456789,10/31/2026', $lines[2]);
        $this->assertCount(17, str_getcsv($lines[0], ',', '"', ''));
        $this->assertCount(15, str_getcsv($lines[1], ',', '"', ''));
        $this->assertStringEndsWith("\r\n", $content);
    }

    public function test_customer_rows_are_grouped_by_tin_or_missing_tin_name(): void
    {
        $this->workspace();
        $payload = $this->payload();
        $payload['sales_records'][] = $payload['sales_records'][0];
        $payload['sales_records'][] = [...$payload['sales_records'][0], 'customer_name' => 'Other unnamed-TIN customer'];
        $payload['sales_records'][] = [...$payload['sales_records'][1], 'customer_tin' => '23456789000002'];
        $response = $this->postJson('/reports/generate-sales-dat', $payload)->assertOk();
        $lines = explode("\r\n", rtrim($response->streamedContent(), "\r\n"));
        $this->assertCount(4, $lines);
        $this->assertStringContainsString('200,24,123456789,10/31/2026', $lines[2]);
        $this->assertStringContainsString('21,0,0,0,123456789,10/31/2026', $lines[3]);
        $this->assertStringContainsString('21.00,0.00,300.00,36.00', $lines[0]);
    }

    public function test_multiple_months_download_a_zip_with_separate_reconciled_monthly_files(): void
    {
        $this->workspace();
        $payload = $this->payload();
        $payload['sales_records'][1]['date'] = '2026-11-15';
        $payload['sales_records'][] = [...$payload['sales_records'][0], 'date' => '2026-12-20'];
        $response = $this->postJson('/reports/generate-sales-dat', $payload)->assertOk()->assertDownload('RELIEF-Sales-2026-10-01-to-2026-12-31.zip');
        $path = tempnam(sys_get_temp_dir(), 'relief-test-');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path));
            $this->assertSame(3, $zip->numFiles);
            foreach (['10' => '31', '11' => '30', '12' => '31'] as $month => $day) {
                $content = $zip->getFromName('123456789S'.$month.'2026.DAT');
                $this->assertIsString($content);
                $this->assertStringContainsString($month.'/'.$day.'/2026', $content);
                $this->assertSame(2, substr_count($content, "\r\n"));
            }
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_individual_taxpayer_names_use_their_own_header_slots(): void
    {
        $this->workspace();
        $payload = [...$this->payload(), 'non_individual' => false, 'last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => 'Santos', 'period_end' => 3];
        $content = $this->postJson('/reports/generate-sales-dat', $payload)->assertOk()->streamedContent();
        $this->assertStringStartsWith('H,S,"123456789","","DELA CRUZ","JUAN","SANTOS",', $content);
        $this->assertStringContainsString('001,10/31/2026,3', $content);
    }

    public function test_invalid_or_missing_details_are_rejected_before_download(): void
    {
        $this->workspace();
        $this->postJson('/reports/generate-sales-dat', [])->assertUnprocessable()->assertJsonValidationErrors(['sales_records', 'from', 'to', 'rdo']);
        foreach (['rdo', 'street', 'city'] as $field) {
            $payload = $this->payload();
            $payload[$field] = '';
            $this->postJson('/reports/generate-sales-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }
        $payload = $this->payload();
        $payload['sales_records'][0]['date'] = '2027-01-01';
        $this->postJson('/reports/generate-sales-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors(['sales_records.0.date']);
        $payload = $this->payload();
        $payload['sales_records'][1]['customer_tin'] = 'badTIN';
        $this->postJson('/reports/generate-sales-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors(['sales_records.1.customer_tin']);
        $payload = $this->payload();
        $payload['sales_records'][0]['gross_amount'] = 500;
        $this->postJson('/reports/generate-sales-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors(['sales_records.0.gross_amount']);
        $payload = [...$this->payload(), 'non_individual' => false];
        $this->postJson('/reports/generate-sales-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors(['company_name']);
    }

    public function test_empty_list_does_not_create_empty_monthly_files(): void
    {
        $this->workspace();
        $payload = $this->payload();
        $payload['sales_records'] = [];
        $this->postJson('/reports/generate-sales-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors(['sales_records']);
    }

    public function test_sales_categories_require_the_linked_master_tag_even_if_the_payload_supplies_one(): void
    {
        $this->workspace();
        ChartAccount::query()->where('code', '4020')->update(['relief_category' => null]);
        $payload = $this->payload();
        $payload['sales_records'][0]['relief_category'] = 'services';
        $this->postJson('/reports/generate-sales-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors(['sales_records.0.relief_category']);
        ChartAccount::query()->where('code', '4020')->update(['relief_category' => 'goods']);
        $this->postJson('/reports/generate-sales-dat', $payload)->assertOk()->assertDownload('123456789S102026.DAT');
    }

    private function workspace(): void
    {
        $company = ['company_name' => 'Current workspace'];
        ChartAccount::factory()->create(['workspace_key' => ChartAccount::workspaceKey($company), 'code' => '4020', 'type' => 'Income', 'name' => 'Service revenue', 'relief_category' => 'services']);
        $this->withSession(['preview_access' => true, 'company_setup' => $company]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'company_tin' => '123-456-789-00000', 'company_name' => ' test, Company Inc. ',
            'non_individual' => true, 'trade_name' => 'Test Trade', 'street' => '123 Main St.', 'city' => 'City', 'rdo' => '001', 'period_end' => 12,
            'from' => '2026-10-01', 'to' => '2026-12-31',
            'sales_records' => [
                ['account_code' => '4020', 'date' => '2026-10-16', 'customer_tin' => '', 'customer_name' => 'various customers', 'customer_address' => "123 Main St.,\nCity", 'gross_amount' => 112, 'exempt_amount' => 0, 'zero_rated_amount' => 0, 'taxable_amount' => 100, 'output_vat_amount' => 12],
                ['account_code' => '4020', 'date' => '2026-10-17', 'customer_tin' => '234-567-890-00001', 'customer_name' => 'Second Customer', 'customer_address' => null, 'gross_amount' => '10.50', 'exempt_amount' => '10.50', 'zero_rated_amount' => 0, 'taxable_amount' => 0, 'output_vat_amount' => 0],
            ],
        ];
    }
}
