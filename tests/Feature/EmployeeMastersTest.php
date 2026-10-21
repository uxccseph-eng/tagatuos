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
}
