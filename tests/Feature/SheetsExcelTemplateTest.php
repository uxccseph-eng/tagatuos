<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\SheetImportWorkbookReader;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class SheetsExcelTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_sheets_templates_download_without_posting_and_keep_import_first(): void
    {
        $this->member();
        $reader = app(SheetImportWorkbookReader::class);
        foreach (['sales' => 13, 'purchases' => 13, 'collections' => 9, 'payments' => 11, 'cwt' => 5, 'wtp' => 10, 'journal' => 11, 'payroll' => 20] as $sheet => $columns) {
            $response = $this->get('/sheets/import/template/'.$sheet)->assertOk()->assertDownload('sheets-'.$sheet.'-dropdown-template.xlsx');
            $path = $response->baseResponse->getFile()->getPathname();
            $matrix = $reader->read($path);
            $this->assertSame(['Import', 'Examples', 'Choices'], $matrix['worksheets']);
            $this->assertCount(1, $matrix['rows']);
            $this->assertCount($columns, $matrix['rows'][0]);
            $this->assertSame($sheet === 'payroll' ? 'pay_date' : ($sheet === 'journal' ? 'Voucher Key' : 'Date'), $matrix['rows'][0][0]);
            $examples = $reader->read($path, 1)['rows'];
            $this->assertSame($matrix['rows'][0], $examples[0]);
            $this->assertGreaterThan(1, count($examples));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('sheet_import_batches', 0);
    }

    public function test_template_selectors_use_form_choices_for_all_500_entry_rows(): void
    {
        foreach (['sales' => ['D2:D501' => ['VAT', 'NONVAT', 'EXEMPT', 'ZERO', 'NR'], 'H2:H501' => ['Cash', 'On Account'], 'J2:J501' => ['Regular', 'Top Withholding Agent', 'Government', 'ZeroRated', 'PEZA']], 'purchases' => ['D2:D501' => ['VAT', 'NONVAT', 'EXEMPT', 'ZERO', 'NR']], 'payments' => ['I2:I501' => ['AP Settlement', 'Direct Expense', 'Supplier Advance']], 'journal' => ['E2:E501' => ['General', 'Adjusting', 'Opening', 'Closing', 'Reclassification']], 'payroll' => ['F2:F501' => ['Taxable', 'Non-taxable', 'Minimum Wage Earner'], 'T2:T501' => ['regular', 'year_end', 'final_pay', 'other']]] as $sheet => $ranges) {
            $path = resource_path('templates/sheets-'.$sheet.'-import.xlsx');
            $zip = new ZipArchive;
            $zip->open($path);
            try {
                $document = new DOMDocument;
                $document->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'), LIBXML_NONET);
                $xpath = new DOMXPath($document);
                $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $choices = app(SheetImportWorkbookReader::class)->read($path, 2)['rows'];
                foreach ($ranges as $range => $expected) {
                    $validation = $xpath->query('//s:dataValidation[@sqref="'.$range.'"]')->item(0);
                    $this->assertNotNull($validation);
                    $this->assertSame('list', $validation->getAttribute('type'));
                    $this->assertNotSame('1', $validation->getAttribute('showDropDown'));
                    $formula = $xpath->query('s:formula1', $validation)->item(0)->textContent;
                    preg_match('/!\$([A-Z])\$2/', $formula, $match);
                    $column = ord($match[1]) - 65;
                    $this->assertSame($expected, array_map(fn (int $index): string => $choices[$index + 1][$column], array_keys($expected)));
                }
            } finally {
                $zip->close();
            }
        }
    }

    public function test_legacy_sheet_excel_template_is_accepted_by_the_shared_workbook_reader(): void
    {
        $this->member();
        $file = new UploadedFile(resource_path('templates/sheets-journal-import.xlsx'), 'journal.xlsx', null, null, true);
        $this->post('/sheets/import/workbook', ['file' => $file, 'worksheet' => 1], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('rows.0.0', 'Voucher Key')->assertJsonPath('rows.1.0', 'SAMPLE-JV-001')->assertJsonPath('rows.2.0', 'SAMPLE-JV-001');
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_template_download_requires_an_assigned_company_and_sheets_permission(): void
    {
        $this->getJson('/sheets/import/template/sales')->assertUnauthorized();
        $this->member(['masters']);
        $this->getJson('/sheets/import/template/sales')->assertForbidden();
        $this->getJson('/sheets/import/template/unsupported')->assertNotFound();
    }

    /** @param list<string> $tabs */
    private function member(array $tabs = ['sheets']): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($user, ['role' => 'staff', 'allowed_tabs' => json_encode($tabs)]);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => $company->name, 'full_name' => 'Example']]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id])->withHeader('X-Company-ID', (string) $company->id);

    }
}
