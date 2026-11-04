<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeMastersTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function workspace(string $name = 'Employees QA'): array
    {
        return ['preview_access' => true, 'company_setup' => ['company_name' => $name, 'full_name' => 'Sample Owner', 'tin' => '']];
    }

    public function test_page_requires_access_and_does_not_seed_sample_employees(): void
    {
        $this->get('/masters/employees')->assertRedirect('/signin');
        $this->withSession(['preview_access' => true])->get('/masters/employees')->assertRedirect('/company/setup');
        $this->withSession($this->workspace())->get('/masters/employees')->assertOk()->assertSee('New employee')->assertSee('Alphalist')->assertSee('Employee name')->assertSee('000-000-000-00000');
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_create_edit_archive_restore_and_payroll_choices(): void
    {
        $this->withSession($this->workspace());
        $record = $this->postJson('/masters/employees', ['name' => 'Sample Employee', 'tin' => '000000000', 'address' => 'Sample City'])->assertCreated()->assertJsonPath('record.code', 'E-0001')->assertJsonPath('record.tin', '000-000-000-00000')->json('record');
        $this->get('/sheets/payroll')->assertOk()->assertSee('Sample Employee');
        $this->putJson('/masters/employees/'.$record['id'], ['code' => $record['code'], 'name' => 'Updated Employee', 'classification' => 'Probationary'])->assertOk()->assertJsonPath('record.name', 'Updated Employee');
        $this->postJson('/masters/employees/'.$record['id'].'/archive')->assertOk()->assertJsonPath('record.archived', true);
        $this->get('/sheets/payroll')->assertOk()->assertDontSee('Updated Employee');
        $this->postJson('/masters/employees/'.$record['id'].'/restore')->assertOk()->assertJsonPath('record.archived', false);
        $this->get('/sheets/payroll')->assertOk()->assertSee('Updated Employee');
    }

    public function test_duplicates_invalid_tins_and_date_ranges_are_rejected(): void
    {
        $this->withSession($this->workspace());
        $first = $this->postJson('/masters/employees', ['code' => 'STAFF-1', 'name' => 'Sample Employee', 'tin' => '123456789'])->assertCreated()->json('record');
        $this->postJson('/masters/employees', ['name' => ' sample   EMPLOYEE '])->assertUnprocessable()->assertJsonValidationErrors('name_key');
        $this->postJson('/masters/employees', ['name' => 'Another Employee', 'tin' => '123-456-789-00000'])->assertUnprocessable()->assertJsonValidationErrors('tin_key');
        $this->postJson('/masters/employees', ['code' => 'staff-1', 'name' => 'Another Employee'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/masters/employees', ['name' => 'Another Employee', 'tin' => 'bad', 'hire_date' => '2026-10-20', 'end_date' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors(['tin', 'end_date']);
        $this->postJson('/masters/employees/'.$first['id'].'/archive')->assertOk();
        $this->postJson('/masters/employees', ['name' => 'Sample Employee'])->assertUnprocessable();
        $this->postJson('/masters/employees', ['name' => 'Zero TIN One', 'tin' => '000000000'])->assertCreated();
        $this->postJson('/masters/employees', ['name' => 'Zero TIN Two', 'tin' => '000000000'])->assertCreated();
    }

    public function test_mutations_are_scoped_to_the_current_company(): void
    {
        $workspace = $this->workspace();
        $record = Employee::factory()->create(['workspace_key' => ChartAccount::workspaceKey($workspace['company_setup'])]);
        $this->withSession($this->workspace('Other Company'));
        $this->putJson('/masters/employees/'.$record->id, ['code' => 'E-0001', 'name' => 'Changed'])->assertNotFound();
        $this->postJson('/masters/employees/'.$record->id.'/archive')->assertNotFound();
        $this->get('/masters/employees')->assertOk()->assertDontSee($record->name);
        $this->postJson('/masters/employees', ['code' => $record->code, 'name' => $record->name])->assertCreated();
    }

    public function test_import_requires_preview_checks_duplicates_and_generates_codes(): void
    {
        $this->withSession($this->workspace());
        $rows = [['name' => 'Import Employee One'], ['name' => 'Import Employee Two', 'classification' => 'Part-time']];
        $this->postJson('/masters/employees/import', ['records' => $rows])->assertUnprocessable();
        $preview = $this->postJson('/masters/employees/import/preview', ['records' => $rows])->assertOk()->assertJsonPath('errors', [])->json();
        $this->assertDatabaseCount('employees', 0);
        $this->postJson('/masters/employees/import', ['records' => $rows, 'preview_token' => $preview['preview_token']])->assertCreated()->assertJsonPath('count', 2);
        $this->assertDatabaseHas('employees', ['code' => 'E-0001', 'name' => 'Import Employee One']);
        $this->assertDatabaseHas('employees', ['code' => 'E-0002', 'name' => 'Import Employee Two']);
        $duplicates = $this->postJson('/masters/employees/import/preview', ['records' => [['name' => 'import employee one'], ['name' => 'Repeated Name'], ['name' => ' repeated   name ']]])->assertOk()->json();
        $this->assertCount(2, $duplicates['errors']);
        $this->assertNull($duplicates['preview_token']);
        $this->assertDatabaseCount('employees', 2);
    }

    public function test_employee_dates_accept_day_first_input_and_keep_iso_storage(): void
    {
        $this->withSession($this->workspace());
        $record = $this->postJson('/masters/employees', ['name' => 'Day First Employee', 'birth_date' => '29/02/2000', 'hire_date' => '04/11/2026', 'end_date' => '30/11/2026'])->assertCreated()->assertJsonPath('record.birth_date', '2000-02-29')->assertJsonPath('record.hire_date', '2026-11-04')->assertJsonPath('record.end_date', '2026-11-30')->json('record');
        $this->putJson('/masters/employees/'.$record['id'], ['code' => $record['code'], 'name' => $record['name'], 'hire_date' => '2026-01-02', 'end_date' => '03/01/2026'])->assertOk()->assertJsonPath('record.hire_date', '2026-01-02')->assertJsonPath('record.end_date', '2026-01-03');
    }

    public function test_employee_dates_reject_impossible_or_month_first_values_and_reversed_ranges(): void
    {
        $this->withSession($this->workspace());
        foreach (['31/02/2026', '29/02/2025', '12/31/2026', '4/11/2026'] as $date) {
            $this->postJson('/masters/employees', ['name' => 'Invalid Date Employee', 'hire_date' => $date])->assertUnprocessable()->assertJsonValidationErrors('hire_date')->assertJsonPath('errors.hire_date.0', 'Enter the hire date as DD/MM/YYYY, for example 04/11/2026.');
        }
        $this->postJson('/masters/employees', ['name' => 'Invalid Range Employee', 'hire_date' => '10/11/2026', 'end_date' => '09/11/2026'])->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_employee_import_previews_and_saves_day_first_dates_without_swapping_month_and_day(): void
    {
        $this->withSession($this->workspace());
        $rows = [['name' => 'Import Date Employee', 'birth_date' => '15/01/1995', 'hire_date' => '04/11/2026', 'end_date' => '30/11/2026'], ['name' => 'Excel Date Employee', 'hire_date' => '2026-10-01']];
        $preview = $this->postJson('/masters/employees/import/preview', ['records' => $rows])->assertOk()->assertJsonPath('errors', [])->assertJsonPath('records.0.hire_date', '2026-11-04')->json();
        $this->postJson('/masters/employees/import', ['records' => $rows, 'preview_token' => $preview['preview_token']])->assertCreated()->assertJsonPath('count', 2);
        $this->assertDatabaseHas('employees', ['name' => 'Import Date Employee', 'birth_date' => '1995-01-15', 'hire_date' => '2026-11-04', 'end_date' => '2026-11-30']);
    }

    public function test_invalid_import_dates_return_day_first_guidance_and_save_nothing(): void
    {
        $this->withSession($this->workspace());
        $result = $this->postJson('/masters/employees/import/preview', ['records' => [['name' => 'Invalid Import Date', 'hire_date' => '31/02/2026']]])->assertOk()->assertJsonPath('preview_token', null)->json();
        $this->assertSame('hire_date', $result['errors'][0]['field']);
        $this->assertStringContainsString('DD/MM/YYYY', $result['errors'][0]['message']);
        $this->assertDatabaseCount('employees', 0);
    }
}
