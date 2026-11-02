<?php

namespace Tests\Feature;

use App\BooksExport;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class BooksExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_exports_require_a_workspace_and_validate_dates_amounts_and_row_limits(): void
    {
        $url = '/books/general-ledger/export/xlsx';
        $this->postJson($url, $this->payload())->assertForbidden();
        $this->workspace();
        $data = $this->payload();
        $data['to'] = '2026-09-01';
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('to');
        $data = $this->payload();
        $data['rows'][1]['debit'] = '=1+1';
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('rows.1.debit');
        $data = $this->payload();
        $data['rows'] = array_fill(0, 1501, $data['rows'][1]);
        $this->postJson('/books/general-ledger/export/pdf', $data)->assertUnprocessable()->assertJsonValidationErrors('rows');
    }

    public function test_workbook_has_company_headings_grouping_numeric_cents_and_unduplicated_totals(): void
    {
        $this->workspace();
        $response = $this->postJson('/books/general-ledger/export/xlsx', $this->payload())->assertOk()->assertDownload('gl-2026-10-01-to-2026-10-31.xlsx');
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path));
            $document = new DOMDocument;
            $this->assertTrue($document->loadXML($zip->getFromName('xl/worksheets/sheet1.xml')));
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $this->assertSame('Export & Company', $xpath->evaluate('string(//s:c[@r="A1"]/s:is/s:t)'));
            $this->assertSame('For the month of October 2026 (October 1, 2026 - October 31, 2026)', $xpath->evaluate('string(//s:c[@r="A6"]/s:is/s:t)'));
            $this->assertSame('Account: Cash', $xpath->evaluate('string(//s:c[@r="A11"]/s:is/s:t)'));
            $this->assertSame('=literal reference', $xpath->evaluate('string(//s:c[@r="B13"]/s:is/s:t)'));
            $this->assertSame('0.3', $xpath->evaluate('string(//s:c[@r="C16"]/s:v)'));
            $this->assertSame('-0.7', $xpath->evaluate('string(//s:c[@r="E15"]/s:v)'));
            $this->assertSame('', $xpath->evaluate('string(//s:c[@r="E16"]/s:is/s:t)'));
            $this->assertSame('A11:E11', $xpath->evaluate('string(//s:mergeCell[contains(@ref,"A11")]/@ref)'));
            $this->assertSame('landscape', $xpath->evaluate('string(//s:pageSetup/@orientation)'));
            $this->assertSame('0', $xpath->evaluate('string(//s:pageSetup/@fitToHeight)'));
            $this->assertSame('A11', $xpath->evaluate('string(//s:pane/@topLeftCell)'));
            $this->assertSame(0.0, $xpath->evaluate('count(//s:f)'));
            $this->assertStringContainsString("'General Ledger'!\$1:\$10", $zip->getFromName('xl/workbook.xml'));
            $this->assertStringContainsString('#,##0.00;(#,##0.00);0.00', $zip->getFromName('xl/styles.xml'));
            $this->assertStringNotContainsString('TRND', $document->textContent);
            $this->assertStringNotContainsString('MyBizMate', $document->textContent);
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_pdf_downloads_a_real_landscape_document_and_escapes_html(): void
    {
        $this->workspace();
        $data = $this->payload();
        $data['rows'][1]['number'] = '<script>alert(1)</script>';
        $response = $this->postJson('/books/general-ledger/export/pdf', $data)->assertOk()->assertDownload('gl-2026-10-01-to-2026-10-31.pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('/MediaBox [0.000 0.000 841.890 595.280]', $response->getContent());
        $export = new BooksExport;
        $html = view('books-export', [
            'headings' => $export->headings(['company_name' => '<script>Company</script>'], 'General Ledger', $data['from'], $data['to'], 'Tester'),
            'columns' => $data['columns'], 'rows' => $export->rows($data['columns'], $data['rows'], 'general-ledger'), 'widths' => [18, 18, 18, 18, 18],
        ])->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;Company&lt;/script&gt;', $html);
    }

    public function test_journal_continuations_are_blank_and_empty_books_keep_headers_and_zero_totals(): void
    {
        $export = new BooksExport;
        $columns = [['key' => 'number', 'title' => 'Entry No.'], ['key' => 'debit', 'title' => 'Debit', 'format' => 'money']];
        $rows = $export->rows($columns, [['number' => 'GJ-1', 'voucherId' => 'journal:1', 'debit' => 10], ['number' => 'GJ-1', 'voucherId' => 'journal:1', 'debit' => 0]], 'general-journal');
        $this->assertSame([null, null], $rows[1]['cells']);
        $this->assertEquals(['Totals', .1], $rows[2]['cells']);
        $this->assertEquals(['Totals', 0], $export->rows($columns, [], 'general-journal')[0]['cells']);
    }

    public function test_exports_cannot_cross_company_memberships_or_use_a_stale_revision(): void
    {
        $user = User::factory()->firm()->create();
        $company = Company::factory()->create();
        $foreign = Company::factory()->create();
        $company->users()->attach($user, ['role' => 'owner']);
        CompanySetting::query()->create(['company_id' => $company->id, 'workspace_key' => $company->workspaceKey(), 'lookup_key' => hash('sha256', 'export-test'), 'profile' => ['company_name' => 'Own Book'], 'preferences' => []]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
        $url = '/books/general-ledger/export/pdf';
        $this->withHeader('X-Company-ID', (string) $foreign->id)->postJson($url, $this->payload())->assertConflict();
        $this->withHeaders(['X-Company-ID' => (string) $company->id, 'X-Ledger-Revision' => '999'])->postJson($url, $this->payload())->assertConflict();
        $this->withHeader('X-Ledger-Revision', '0')->postJson($url, $this->payload())->assertOk();
        $company->users()->updateExistingPivot($user->id, ['role' => 'staff', 'allowed_tabs' => ['sheets']]);
        $this->postJson($url, $this->payload())->assertForbidden();
    }

    private function workspace(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Export & Company', 'full_name' => 'Tester', 'tin' => '123456789', 'vat_type' => 'vat']]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $group = ['group' => 'Cash', 'groupAccount' => 'Cash'];

        return ['from' => '2026-10-01', 'to' => '2026-10-31', 'columns' => [
            ['key' => 'date', 'title' => 'Date'], ['key' => 'number', 'title' => 'Document No.'],
            ['key' => 'debit', 'title' => 'Debit', 'format' => 'money'], ['key' => 'credit', 'title' => 'Credit', 'format' => 'money'], ['key' => 'balance', 'title' => 'Balance', 'format' => 'money'],
        ], 'rows' => [
            [...$group, 'kind' => 'opening', 'debit' => 0, 'credit' => 0, 'balance' => -100],
            [...$group, 'date' => '2026-10-01', 'number' => '=literal reference', 'debit' => 10, 'credit' => 0, 'balance' => -90],
            [...$group, 'date' => '2026-10-31', 'number' => 'GJ-2', 'debit' => 20, 'credit' => 0, 'balance' => -70],
            [...$group, 'kind' => 'subtotal', 'debit' => 30, 'credit' => 0, 'balance' => -70],
        ]];
    }
}
