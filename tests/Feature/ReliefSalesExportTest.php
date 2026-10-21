<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Tests\TestCase;
use ZipArchive;

class ReliefSalesExportTest extends TestCase
{
    public function test_export_requires_the_current_workspace(): void
    {
        $this->postJson('/tax-reports/relief-sales/excel', $this->payload())->assertForbidden();
        $this->withSession(['preview_access' => true])->postJson('/tax-reports/relief-sales/excel', $this->payload())->assertForbidden();
    }

    public function test_export_fills_the_uploaded_template_and_recalculates_totals(): void
    {
        $this->workspace();
        $this->get('/tax-reports/relief-sales?view=detailed')->assertOk()->assertSee('data-sales-excel-url="http://localhost/tax-reports/relief-sales/excel"', false);
        $response = $this->postJson('/tax-reports/relief-sales/excel', $this->payload());
        $response->assertOk()->assertDownload('relief-sales-2026-10-01-to-2026-12-31.xlsx');
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $template = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path));
            $this->assertTrue($template->open(resource_path('templates/relief-sales.xlsx')));
            $this->assertSame($template->getFromName('xl/styles.xml'), $zip->getFromName('xl/styles.xml'));
            $document = new DOMDocument;
            $document->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $this->assertSame('Export Test Company', $xpath->evaluate('string(//s:c[@r="A1"]/s:is/s:t)'));
            $this->assertSame('VAT REG TIN 000-000-000-00000', $xpath->evaluate('string(//s:c[@r="A3"]/s:is/s:t)'));
            $this->assertSame('Month', $xpath->evaluate('string(//s:c[@r="A12"]/s:is/s:t)'));
            $this->assertSame('Gross amount', $xpath->evaluate('string(//s:c[@r="K12"]/s:is/s:t)'));
            $this->assertSame('A1:K15', $xpath->evaluate('string(//s:dimension/@ref)'));
            $this->assertSame('A13', $xpath->evaluate('string(//s:pane/@topLeftCell)'));
            $this->assertSame('=literal customer', $xpath->evaluate('string(//s:c[@r="B13"]/s:is/s:t)'));
            $this->assertSame(0.0, $xpath->evaluate('count(//s:f)'));
            $this->assertSame('0.30', $xpath->evaluate('string(//s:c[@r="F15"]/s:v)'));
            $this->assertSame('0.34', $xpath->evaluate('string(//s:c[@r="K15"]/s:v)'));
            $this->assertStringContainsString('For the quarter ended December 31, 2026', $document->textContent);
            $this->assertStringNotContainsString('EDI WOW', $document->textContent);
            $this->assertStringContainsString("'RELIEF Sales'!\$A\$1:\$K\$15", $zip->getFromName('xl/workbook.xml'));
        } finally {
            $zip->close();
            $template->close();
            unlink($path);
        }
    }

    public function test_empty_export_has_headers_and_zero_totals(): void
    {
        $this->workspace();
        $payload = $this->payload();
        $payload['rows'] = [];
        $response = $this->postJson('/tax-reports/relief-sales/excel', $payload)->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        try {
            $zip->open($path);
            $this->assertStringContainsString('ref="A1:K13"', $zip->getFromName('xl/worksheets/sheet1.xml'));
            $this->assertStringContainsString('<v>0.00</v>', $zip->getFromName('xl/worksheets/sheet1.xml'));
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_invalid_range_and_non_numeric_amounts_are_rejected(): void
    {
        $this->workspace();
        $payload = $this->payload();
        $payload['to'] = '2026-09-01';
        $payload['rows'][0][3] = '=1+1';
        $this->postJson('/tax-reports/relief-sales/excel', $payload)->assertUnprocessable()->assertJsonValidationErrors(['to', 'rows.0.3']);
        $payload = $this->payload();
        array_pop($payload['rows'][0]);
        $this->postJson('/tax-reports/relief-sales/excel', $payload)->assertUnprocessable()->assertJsonValidationErrors(['rows.0']);
    }

    private function workspace(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Export Test Company', 'full_name' => 'Test User', 'accounting_period' => 'calendar', 'vat_type' => 'vat', 'tin' => '000000000']]);
    }

    /** @return array{from: string, to: string, rows: list<list<string|float|int>>} */
    private function payload(): array
    {
        return ['from' => '2026-10-01', 'to' => '2026-12-31', 'rows' => [
            ['2026-10', '=literal customer', '000-000-000-00000', 0, 0, 0.1, 0, '12%', 0.01, 0.1, 0.11],
            ['2026-11', 'Second customer', '000-000-000-00000', 0, 0, 0.2, 0, '12%', 0.03, 0.2, 0.23],
        ]];
    }
}
