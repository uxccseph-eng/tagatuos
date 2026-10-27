<?php

namespace Tests\Feature;

use App\MasterImport;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class MastersGuidedImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_worksheet_selection_preserves_text_identifiers_without_writing_masters(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Import QA', 'full_name' => 'Preview']]);
        $path = $this->workbook();
        try {
            $response = $this->post('/masters/parties/import/workbook', ['file' => new UploadedFile($path, 'suppliers.xlsx', null, null, true), 'worksheet' => 1], ['Accept' => 'application/json']);
            $response->assertOk()->assertJsonPath('selected', 1)->assertJsonPath('worksheets.1', 'Suppliers')->assertJsonPath('rows.1.1', '00123456700000');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertDatabaseCount('parties', 0);
            $this->assertDatabaseCount('chart_accounts', 0);
        } finally {
            unlink($path);
        }
    }

    public function test_workbook_upload_rejects_missing_setup_invalid_files_oversized_files_and_invalid_sheet(): void
    {
        $this->postJson('/masters/parties/import/workbook')->assertForbidden();
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Import QA']]);
        foreach ([UploadedFile::fake()->create('legacy.xls', 1), UploadedFile::fake()->create('huge.xlsx', 2049), UploadedFile::fake()->createWithContent('broken.xlsx', 'not a zip')] as $file) {
            $this->post('/masters/parties/import/workbook', ['file' => $file], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $path = $this->workbook();
        try {
            $this->post('/masters/parties/import/workbook', ['file' => new UploadedFile($path, 'suppliers.xlsx', null, null, true), 'worksheet' => 49], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        } finally {
            unlink($path);
        }
    }

    public function test_sheet_only_members_cannot_use_the_masters_workbook_endpoint(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($user, ['role' => 'staff', 'allowed_tabs' => json_encode(['sheets'])]);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => $company->name]]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id])->postJson('/masters/parties/import/workbook')->assertForbidden();
        $this->assertDatabaseCount('parties', 0);
    }

    public function test_excluding_or_editing_rows_requires_a_new_preview_and_saves_only_the_reviewed_subset(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Import QA', 'full_name' => 'Preview']]);
        $rows = [['name' => 'First Expense', 'type' => 'Expense'], ['name' => 'Unwanted Expense', 'type' => 'Expense']];
        $token = $this->postJson('/masters/chart-of-accounts/import/preview', ['records' => $rows])->assertOk()->json('preview_token');
        $edited = [['name' => 'Corrected Expense', 'type' => 'Expense']];
        $this->postJson('/masters/chart-of-accounts/import', ['records' => $edited, 'preview_token' => $token])->assertUnprocessable();
        $this->assertDatabaseCount('chart_accounts', 0);
        $token = $this->postJson('/masters/chart-of-accounts/import/preview', ['records' => $edited])->assertOk()->json('preview_token');
        $this->postJson('/masters/chart-of-accounts/import', ['records' => $edited, 'preview_token' => $token])->assertCreated()->assertJsonPath('count', 1);
        $this->assertDatabaseHas('chart_accounts', ['name' => 'Corrected Expense']);
        $this->assertDatabaseMissing('chart_accounts', ['name' => 'Unwanted Expense']);
    }

    public function test_excel_formulas_are_reported_as_validation_errors(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Import QA']]);
        $path = $this->workbook(true);
        try {
            $this->post('/masters/parties/import/workbook', ['file' => new UploadedFile($path, 'formula.xlsx', null, null, true)], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        } finally {
            unlink($path);
        }
    }

    public function test_unmapped_blank_fields_keep_registry_defaults_and_petty_cash_tin_is_optional(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Import QA', 'full_name' => 'Preview']]);
        foreach (['chart-of-accounts' => ['name' => 'Imported Expense', 'type' => 'Expense'], 'employees' => ['name' => 'Imported Employee'], 'parties' => ['name' => 'Petty Cash Import', 'classification' => 'Supplier', 'is_petty_cash' => 'Yes']] as $registry => $values) {
            $row = [...array_fill_keys(MasterImport::columns($registry), ''), ...$values];
            $response = $this->postJson('/masters/'.$registry.'/import/preview', ['records' => [$row]])->assertOk()->assertJsonPath('errors', []);
            $this->assertNotEmpty($response->json('preview_token'));
            if ($registry === 'employees') {
                $response->assertJsonPath('records.0.classification', 'Regular');
            }
            if ($registry === 'chart-of-accounts') {
                $response->assertJsonPath('records.0.normal_balance', 'Debit');
            }
            if ($registry === 'parties') {
                $response->assertJsonPath('records.0.is_petty_cash', true);
            }
        }
        $this->assertDatabaseCount('parties', 0);
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('chart_accounts', 0);
    }

    private function workbook(bool $formula = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'masters-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="'.$namespace.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Customers" sheetId="1" r:id="rId1"/><sheet name="Suppliers" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>');
        $rows = '<row r="1"><c r="A1" t="inlineStr"><is><t>Supplier Name</t></is></c><c r="B1" t="inlineStr"><is><t>TIN</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Sample Supplier</t></is></c><c r="B2" t="inlineStr">'.($formula ? '<f>1+1</f>' : '').'<is><t>00123456700000</t></is></c></row>';
        foreach ([1, 2] as $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.$sheet.'.xml', '<worksheet xmlns="'.$namespace.'"><sheetData>'.$rows.'</sheetData></worksheet>');
        }
        $zip->close();

        return $path;
    }
}
