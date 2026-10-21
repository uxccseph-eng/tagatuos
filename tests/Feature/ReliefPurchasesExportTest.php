<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Tests\TestCase;
use ZipArchive;

class ReliefPurchasesExportTest extends TestCase
{
    public function test_export_requires_a_company_workspace(): void
    {
        $this->postJson('/tax-reports/relief-purchases/excel', $this->payload())->assertForbidden();
        $this->withSession(['preview_access' => true])->postJson('/tax-reports/relief-purchases/excel', $this->payload())->assertForbidden();
    }

    public function test_export_preserves_the_supplied_layout_styles_and_print_settings_with_live_data(): void
    {
        $this->workspace();
        $this->get('/tax-reports/relief-purchases')->assertOk()->assertSee('data-purchases-excel-url="http://localhost/tax-reports/relief-purchases/excel"', false);
        $response = $this->postJson('/tax-reports/relief-purchases/excel', $this->payload())->assertOk()
            ->assertDownload('relief-purchases-2026-10-01-to-2026-10-31.xlsx');
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $template = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path));
            $template->open(resource_path('templates/relief-purchases.xlsx'));
            $this->assertSame($template->getFromName('xl/styles.xml'), $zip->getFromName('xl/styles.xml'));
            $this->assertSame($template->getFromName('xl/workbook.xml'), $zip->getFromName('xl/workbook.xml'));
            $document = new DOMDocument;
            $document->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $this->assertSame('PURCHASE TRANSACTION', $xpath->evaluate('string(//s:c[@r="A1"]/s:is/s:t)'));
            $this->assertSame('FOR THE MONTH OF : OCTOBER 2026', $xpath->evaluate('string(//s:c[@r="A3"]/s:is/s:t)'));
            $this->assertSame('Purchases Export Company', $xpath->evaluate('string(//s:c[@r="B6"]/s:is/s:t)'));
            $this->assertSame('Purchases Trade', $xpath->evaluate('string(//s:c[@r="B7"]/s:is/s:t)'));
            $this->assertSame('000-000-000', $xpath->evaluate('string(//s:c[@r="B5"]/s:is/s:t)'));
            $this->assertSame('Office, Sample City', $xpath->evaluate('string(//s:c[@r="B8"]/s:is/s:t)'));
            $this->assertSame('A1:N21', $xpath->evaluate('string(//s:dimension/@ref)'));
            $this->assertSame('=literal corporation', $xpath->evaluate('string(//s:c[@r="C14"]/s:is/s:t)'));
            $this->assertSame('Person, Example Middle', $xpath->evaluate('string(//s:c[@r="D15"]/s:is/s:t)'));
            $this->assertSame('', $xpath->evaluate('string(//s:c[@r="C15"]/s:is/s:t)'));
            $this->assertSame('46326.00', $xpath->evaluate('string(//s:c[@r="A14"]/s:v)'));
            $this->assertSame('n', $xpath->evaluate('string(//s:c[@r="F14"]/@t)'));
            $this->assertSame('SUM(F14:F15)', $xpath->evaluate('string(//s:c[@r="F16"]/s:f)'));
            $this->assertSame('0.30', $xpath->evaluate('string(//s:c[@r="F16"]/s:v)'));
            $this->assertSame('0.34', $xpath->evaluate('string(//s:c[@r="N16"]/s:v)'));
            $this->assertSame(9.0, $xpath->evaluate('count(//s:f)'));
            $this->assertSame('portrait', $xpath->evaluate('string(//s:pageSetup/@orientation)'));
            $this->assertSame('28', $xpath->evaluate('string(//s:col[@min="4"]/@width)'));
            $this->assertSame('END OF REPORT', $xpath->evaluate('string(//s:c[@r="A18"]/s:is/s:t)'));
            $this->assertSame('VAT REG TIN 000-000-000-00000', $xpath->evaluate('string(//s:c[@r="A20"]/s:is/s:t)'));
            $this->assertStringContainsString('Software: kontadurya.io', $document->textContent);
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (str_ends_with($name, '.xml')) {
                    foreach (['TRND MARKETING INC', '731-214-454', 'ljc.seph@gmail.com', 'libtong road', 'MyBizMate'] as $sample) {
                        $this->assertStringNotContainsString($sample, $zip->getFromIndex($index));
                    }
                }
            }
        } finally {
            $zip->close();
            $template->close();
            unlink($path);
        }
    }

    public function test_empty_export_has_zero_formulas_without_circular_totals(): void
    {
        $this->workspace();
        $payload = $this->payload();
        $payload['rows'] = [];
        $response = $this->postJson('/tax-reports/relief-purchases/excel', $payload)->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        try {
            $zip->open($path);
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertStringContainsString('A1:N19', $xml);
            $this->assertStringContainsString('<f>SUM(0)</f><v>0.00</v>', $xml);
            $this->assertStringNotContainsString('F14:F13', $xml);
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_unclassified_vat_amounts_are_preserved_with_a_review_note(): void
    {
        $this->workspace();
        $payload = $this->payload();
        $payload['rows'][0][9] = 0;
        $response = $this->postJson('/tax-reports/relief-purchases/excel', $payload)->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        try {
            $zip->open($path);
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertStringContainsString('REVIEW: 1 row(s) have unclassified VAT purchase amounts.', $xml);
            $this->assertStringContainsString('<c r="I14" s="6" t="n"><v>0.10</v></c>', $xml);
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_invalid_ranges_columns_months_and_amounts_are_rejected(): void
    {
        $this->workspace();
        $payload = $this->payload();
        $payload['to'] = '2026-09-01';
        $payload['rows'][0][0] = '2026-13';
        $payload['rows'][0][5] = '=1+1';
        $this->postJson('/tax-reports/relief-purchases/excel', $payload)->assertUnprocessable()->assertJsonValidationErrors(['to', 'rows.0.0', 'rows.0.5']);
        $payload = $this->payload();
        array_pop($payload['rows'][0]);
        $this->postJson('/tax-reports/relief-purchases/excel', $payload)->assertUnprocessable()->assertJsonValidationErrors('rows.0');
    }

    private function workspace(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Purchases Export Company', 'trade_name' => 'Purchases Trade', 'full_name' => 'Test User', 'vat_type' => 'vat', 'tin' => '000000000', 'building' => 'Office', 'city' => 'Sample City']]);
    }

    /** @return array{from: string, to: string, rows: list<list<string|float|int>>} */
    private function payload(): array
    {
        return ['from' => '2026-10-01', 'to' => '2026-10-31', 'rows' => [
            ['2026-10', '000-000-000', '=literal corporation', '', 'Corporate address', 0.1, 0, 0, 0.1, 0.1, 0, 0, 0.01, 0.11],
            ['2026-10', '000-000-001', '', 'Person, Example Middle', 'Individual address', 0.2, 0, 0, 0.2, 0, 0, 0.2, 0.03, 0.23],
        ]];
    }
}
