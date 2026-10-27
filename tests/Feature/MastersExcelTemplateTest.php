<?php

namespace Tests\Feature;

use App\MasterImport;
use App\Models\Company;
use App\Models\User;
use App\Services\SheetImportWorkbookReader;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class MastersExcelTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_masters_excel_template_has_exact_headers_a_blank_import_sheet_and_examples(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Template QA']]);
        $reader = app(SheetImportWorkbookReader::class);
        foreach (['chart-of-accounts', 'parties', 'employees', 'bank-accounts', 'tax-codes', 'atc-codes'] as $registry) {
            $response = $this->get('/masters/'.$registry.'/import/template')->assertOk()->assertDownload('masters-'.$registry.'-dropdown-template.xlsx');
            $path = $response->baseResponse->getFile()->getPathname();
            $matrix = $reader->read($path);
            $this->assertSame(['Import', 'Examples', 'Choices'], $matrix['worksheets']);
            $this->assertSame([MasterImport::columns($registry)], $matrix['rows']);
            $examples = $reader->read($path, 1);
            $this->assertSame(MasterImport::columns($registry), $examples['rows'][0]);
            $this->assertGreaterThan(1, count($examples['rows']));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->assertDatabaseCount('parties', 0);
        $this->assertDatabaseCount('chart_accounts', 0);
    }

    public function test_party_selectors_cover_all_500_rows_and_match_the_forms(): void
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(resource_path('templates/masters-parties-import.xlsx')));
        try {
            $document = new DOMDocument;
            $document->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'), LIBXML_NONET);
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $choices = app(SheetImportWorkbookReader::class)->read(resource_path('templates/masters-parties-import.xlsx'), 2)['rows'];
            foreach (['C2:C501' => ['corporation', 'individual'], 'G2:G501' => ['Customer', 'Supplier', 'Both'], 'H2:H501' => ['Yes', 'No']] as $range => $expected) {
                $validation = $xpath->query('//s:dataValidation[@sqref="'.$range.'"]')->item(0);
                $this->assertNotNull($validation);
                $this->assertSame('list', $validation->getAttribute('type'));
                $this->assertNotSame('1', $validation->getAttribute('showDropDown'));
                $formula = $xpath->query('s:formula1', $validation)->item(0)->textContent;
                $this->assertMatchesRegularExpression('/^=?Choices!\$([A-Z])\$2:\$\1\$[0-9]+$/', $formula);
                preg_match('/!\$([A-Z])\$2/', $formula, $match);
                $column = ord($match[1]) - 65;
                $this->assertSame($expected, array_map(fn (int $index): string => $choices[$index + 1][$column], array_keys($expected)));
            }
        } finally {
            $zip->close();
        }
    }

    public function test_individual_example_can_be_uploaded_mapped_and_validated_after_replacing_its_tin(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Template QA', 'full_name' => 'Preview']]);
        $upload = new UploadedFile(resource_path('templates/masters-parties-import.xlsx'), 'parties.xlsx', null, null, true);
        $matrix = $this->post('/masters/parties/import/workbook', ['file' => $upload, 'worksheet' => 1], ['Accept' => 'application/json'])->assertOk()->json('rows');
        $row = array_combine($matrix[0], array_pad($matrix[2], count($matrix[0]), ''));
        $this->assertSame('individual', $row['entity_type']);
        $this->assertSame('DELA CRUZ', $row['last_name']);
        $this->assertSame('JUAN', $row['first_name']);
        $row['tin'] = '000-000-000-00000';
        $this->postJson('/masters/parties/import/preview', ['records' => [$row]])->assertOk()->assertJsonPath('errors', [])->assertJsonPath('records.0.entity_type', 'individual')->assertJsonPath('records.0.first_name', 'JUAN');
        $this->assertDatabaseCount('parties', 0);
    }

    public function test_template_download_requires_setup_and_masters_permission(): void
    {
        $this->getJson('/masters/parties/import/template')->assertForbidden();
        $company = Company::factory()->create();
        $user = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($user, ['role' => 'staff', 'allowed_tabs' => json_encode(['sheets'])]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id])->getJson('/masters/parties/import/template')->assertForbidden();
        $this->getJson('/masters/unsupported/import/template')->assertNotFound();
    }
}
