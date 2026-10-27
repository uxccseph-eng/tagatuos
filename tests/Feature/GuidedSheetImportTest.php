<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\User;
use App\Services\CompanyLedgerService;
use App\Services\SheetImportWorkbookReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class GuidedSheetImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_payroll_formats_are_shared_only_with_the_selected_company_and_preserve_version_checks(): void
    {
        [$company, $owner] = $this->book();
        $this->asMember($owner, $company);
        $payload = ['sheet' => 'payroll', 'headers' => ['Employee Name', 'Basic Salary', 'Tax Deducted'], 'mapping' => ['employee' => 0, 'basic' => 1, 'withholding' => 2], 'version' => 0];
        $this->putJson(route('sheets.import.mapping.save'), $payload)->assertOk()->assertJsonPath('version', 1);
        $accountant = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($accountant, ['role' => 'accountant']);
        $this->asMember($accountant, $company);
        $this->postJson(route('sheets.import.mapping'), $payload)->assertOk()->assertJsonPath('mapping.withholding', 2)->assertJsonPath('version', 1);
        $this->putJson(route('sheets.import.mapping.save'), $payload)->assertConflict();
        $this->putJson(route('sheets.import.mapping.save'), [...$payload, 'version' => 1])->assertOk()->assertJsonPath('version', 2);
        $this->postJson(route('sheets.import.mapping'), [...$payload, 'headers' => ['Basic Salary', 'Employee Name', 'Tax Deducted']])->assertOk()->assertJsonPath('mapping', null);
        $this->postJson(route('sheets.import.mapping'), [...$payload, 'sheet' => 'sales'])->assertOk()->assertJsonPath('mapping', null);
        [$other, $otherOwner] = $this->book();
        $this->asMember($otherOwner, $other);
        $this->postJson(route('sheets.import.mapping'), $payload)->assertOk()->assertJsonPath('mapping', null);
        $this->assertDatabaseCount('sheet_import_profiles', 1);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_payroll_format_writes_reject_unsupported_fields_and_members_without_sheets_access(): void
    {
        [$company, $owner] = $this->book();
        $this->asMember($owner, $company);
        $payload = ['sheet' => 'payroll', 'headers' => ['Employee', 'Tax'], 'mapping' => ['employee' => 0, 'withholding' => 1], 'version' => 0];
        foreach ([['employee' => 0, 'withholding' => 0], ['withholding' => 2], ['company_id' => 1], ['withholding' => -1]] as $mapping) {
            $this->putJson(route('sheets.import.mapping.save'), [...$payload, 'mapping' => $mapping])->assertUnprocessable();
        }
        $staff = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($staff, ['role' => 'staff', 'allowed_tabs' => json_encode(['dashboard'])]);
        $this->asMember($staff, $company);
        $this->putJson(route('sheets.import.mapping.save'), $payload)->assertForbidden();
        $this->postJson(route('sheets.import.mapping'), $payload)->assertForbidden();
        $this->assertDatabaseCount('sheet_import_profiles', 0);
    }

    public function test_mappings_are_shared_with_members_and_isolated_by_company_sheet_and_headers(): void
    {
        [$company, $owner] = $this->book();
        $this->asMember($owner, $company);
        $payload = ['sheet' => 'sales', 'headers' => ['Day', 'Client', 'Total'], 'mapping' => ['Date' => 0, 'Customer' => 1, 'Gross Amount' => 2], 'version' => 0];
        $this->putJson(route('sheets.import.mapping.save'), $payload)->assertOk()->assertJsonPath('version', 1);
        $member = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($member, ['role' => 'accountant']);
        $this->asMember($member, $company);
        $this->postJson(route('sheets.import.mapping'), $payload)->assertOk()->assertJsonPath('mapping.Customer', 1)->assertJsonPath('version', 1);
        $this->postJson(route('sheets.import.mapping'), [...$payload, 'sheet' => 'purchases'])->assertOk()->assertJsonPath('mapping', null);
        $this->postJson(route('sheets.import.mapping'), [...$payload, 'headers' => ['Other']])->assertOk()->assertJsonPath('mapping', null);
        $this->putJson(route('sheets.import.mapping.save'), $payload)->assertConflict();
        [$other, $otherOwner] = $this->book();
        $this->asMember($otherOwner, $other);
        $this->postJson(route('sheets.import.mapping'), $payload)->assertOk()->assertJsonPath('mapping', null);
        $this->assertDatabaseCount('sheet_import_profiles', 1);
    }

    public function test_mapping_writes_validate_indices_and_company_module_permissions(): void
    {
        [$company, $owner] = $this->book();
        $this->asMember($owner, $company);
        $payload = ['sheet' => 'sales', 'headers' => ['Day', 'Name'], 'mapping' => ['Date' => 0, 'Customer' => 0], 'version' => 0];
        $this->putJson(route('sheets.import.mapping.save'), $payload)->assertUnprocessable();
        $this->putJson(route('sheets.import.mapping.save'), [...$payload, 'mapping' => ['Date' => 2]])->assertUnprocessable();
        $this->putJson(route('sheets.import.mapping.save'), [...$payload, 'mapping' => ['Surprise' => 0]])->assertUnprocessable();
        $this->withHeader('X-Company-ID', '99999')->postJson(route('sheets.import.mapping'), $payload)->assertConflict();
        $staff = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($staff, ['role' => 'staff', 'allowed_tabs' => json_encode(['dashboard'])]);
        $this->asMember($staff, $company);
        $this->postJson(route('sheets.import.mapping'), $payload)->assertForbidden();
        $this->asMember(User::factory()->create(), $company);
        $this->postJson(route('sheets.import.mapping'), $payload)->assertForbidden();
        $this->assertDatabaseCount('sheet_import_profiles', 0);
    }

    public function test_excel_reads_values_and_dates_without_posting_transactions(): void
    {
        [$company, $owner] = $this->book();
        $this->asMember($owner, $company);
        $path = $this->workbook('<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="inlineStr"><is><t>Customer</t></is></c></row><row r="2"><c r="A2" s="1"><v>46314</v></c><c r="B2" t="inlineStr"><is><t>Test Customer</t></is></c><c r="C2"><v>112.50</v></c></row>');
        try {
            $upload = new UploadedFile($path, 'sales.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $this->post(route('sheets.import.workbook'), ['file' => $upload], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('rows.0.0', 'Date')->assertJsonPath('rows.1.0', '2026-10-19')->assertJsonPath('rows.1.2', '112.50');
            $this->assertDatabaseCount('journal_entries', 0);
            $this->assertDatabaseCount('sheet_import_batches', 0);
        } finally {
            @unlink($path);
        }
    }

    public function test_excel_rejects_formulas_entities_and_excess_rows_or_columns(): void
    {
        foreach (['<row r="1"><c r="A1"><f>1+1</f><v>2</v></c></row>' => 'formulas', '<row r="1"><c r="CW1"><v>1</v></c></row>' => '100 columns', '<row r="522"><c r="A522"><v>1</v></c></row>' => '500 data rows'] as $xml => $message) {
            $path = $this->workbook($xml);
            try {
                app(SheetImportWorkbookReader::class)->read($path);
                $this->fail('Unsafe workbook was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            } finally {
                @unlink($path);
            }
        }
        $path = $this->workbook('<row r="1"><c r="A1"><v>1</v></c></row>');
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/sharedStrings.xml', '<!DOCTYPE x [<!ENTITY leak SYSTEM "file:///private">]><x>&leak;</x>');
        $zip->close();
        try {
            app(SheetImportWorkbookReader::class)->read($path);
            $this->fail('Entities must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unsupported XML', $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_batch_receipts_are_atomic_idempotent_and_block_reimport_after_void(): void
    {
        [$company, $owner] = $this->book();
        $this->asMember($owner, $company);
        Party::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => 'Import Customer', 'classification' => 'Customer']);
        $state = CompanyLedgerService::emptyState();
        $record = ['id' => 'import-one', 'number' => 'SI-000001', 'date' => '2026-10-19', 'status' => 'posted', 'customer' => 'Import Customer', 'gross' => 11200, 'tax' => '12% VAT', 'account' => '4020 · Sales - Services', 'terms' => 'On Account', 'cwt' => 'None', 'vatCwt' => 'None', 'importBatchKey' => str_repeat('a', 64)];
        $state['sales'] = [[...$record, 'gross' => 0]];
        $data = ['state' => $state, 'payroll' => [], 'revision' => 0, 'request_id' => (string) Str::uuid()];
        $this->postJson(route('sheets.database.save'), $data)->assertUnprocessable();
        $this->assertDatabaseCount('sheet_import_batches', 0);
        $state['sales'] = [$record];
        $data = [...$data, 'state' => $state];
        $this->postJson(route('sheets.database.save'), $data)->assertOk();
        $this->postJson(route('sheets.database.save'), $data)->assertOk();
        $this->assertDatabaseCount('sheet_import_batches', 1);
        $state['sales'][0]['status'] = 'void';
        $state['sales'][0]['voidReason'] = 'Test correction';
        $this->postJson(route('sheets.database.save'), [...$data, 'state' => $state, 'revision' => 1, 'request_id' => (string) Str::uuid()])->assertOk();
        $state['sales'][] = [...$record, 'id' => 'import-two', 'number' => 'SI-000002'];
        $this->postJson(route('sheets.database.save'), [...$data, 'state' => $state, 'revision' => 2, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('sheet_import_batches', 1);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    /** @return array{Company, User} */
    private function book(): array
    {
        $company = Company::factory()->create();
        $owner = User::factory()->firm()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => $company->name, 'full_name' => 'Example', 'vat_type' => 'vat', 'tin' => '000000000', 'branch_code' => '00000']]);
        ChartAccount::seedWorkspace($company->workspaceKey());

        return [$company, $owner];
    }

    private function asMember(User $user, Company $company): void
    {
        $this->actingAs($user)->withSession(['active_company_id' => $company->id])->withHeader('X-Company-ID', (string) $company->id);
    }

    private function workbook(string $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'guided-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="'.$namespace.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sales" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="'.$namespace.'"><sheetData>'.$rows.'</sheetData></worksheet>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="'.$namespace.'"><si><t>Date</t></si></sst>');
        $zip->addFromString('xl/styles.xml', '<styleSheet xmlns="'.$namespace.'"><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>');
        $zip->close();

        return $path;
    }
}
