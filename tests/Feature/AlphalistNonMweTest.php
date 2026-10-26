<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlphalistNonMweTest extends TestCase
{
    use RefreshDatabase;

    public function test_annual_page_loads_scoped_employee_identity_including_archived_employees(): void
    {
        $company = ['company_name' => 'Annual Payroll QA', 'full_name' => 'Owner', 'tin' => '000-000-000-00000'];
        $employee = Employee::factory()->create(['workspace_key' => ChartAccount::workspaceKey($company), 'name' => 'Historical Employee', 'archived' => true]);
        Employee::factory()->create(['name' => 'Other Company Employee']);
        $this->withSession(['preview_access' => true, 'company_setup' => $company])
            ->get('/tax-reports/alphalist-non-mwe?year=2026')
            ->assertOk()->assertSee('alphalist-data.js')->assertSee('Annual compensation totals')
            ->assertSee('form2316-dialog')->assertSee('Use default certificate signatory')->assertSee('form2316-config')->assertSee('Recorded exemptions and EE contributions')
            ->assertViewHas('employees', fn ($employees): bool => $employees->count() === 1 && $employees->first()->id === $employee->id)
            ->assertDontSee('Other Company Employee')->assertDontSee('Bulk Download 2316');
    }
}
