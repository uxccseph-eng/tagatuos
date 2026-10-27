<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyLedgerService;
use App\Services\PayrollWithholdingReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PayrollImportReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_keeps_actual_withholding_and_recomputes_review_before_atomic_posting(): void
    {
        [$company, $user, $employee] = $this->book();
        $run = $this->payrollRun($employee->id);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id])->withHeader('X-Company-ID', (string) $company->id);
        $payload = ['state' => CompanyLedgerService::emptyState(), 'payroll' => [$run], 'revision' => 0, 'request_id' => (string) Str::uuid()];
        $this->postJson(route('sheets.database.save'), $payload)->assertUnprocessable();
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('sheet_import_batches', 0);
        $run['employees'][0]['withholdingReview']['acknowledged'] = true;
        $run['employees'][0]['withholdingReview']['note'] = 'Client confirmed actual deduction; annual calculation to be reviewed.';
        $payload['payroll'] = [$run];
        $this->postJson(route('sheets.database.save'), $payload)->assertOk()->assertJsonPath('payroll.0.employees.0.withholding', '1000.00');
        $this->assertDatabaseHas('journal_entry_lines', ['credit' => '1000.0000']);
        $this->assertDatabaseHas('journal_entry_lines', ['credit' => '26550.0000']);
        $this->assertDatabaseHas('sheet_import_batches', ['company_id' => $company->id, 'sheet' => 'payroll']);
        $this->postJson(route('sheets.database.save'), $payload)->assertOk();
        $changed = $run;
        $changed['employees'][0]['withholding'] = '1007.55';
        $this->postJson(route('sheets.database.save'), [...$payload, 'payroll' => [$changed], 'revision' => 1, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_tampered_reference_wrong_employee_and_automatic_mode_are_rejected(): void
    {
        [$company, $user, $employee] = $this->book();
        $this->actingAs($user)->withSession(['active_company_id' => $company->id])->withHeader('X-Company-ID', (string) $company->id);
        foreach (['reference', 'employee', 'automatic'] as $change) {
            $run = $this->payrollRun($employee->id);
            $run['employees'][0]['withholdingReview'] += ['note' => 'Reviewed actual client records.'];
            $run['employees'][0]['withholdingReview']['acknowledged'] = true;
            if ($change === 'reference') {
                $run['employees'][0]['withholdingReview']['reference'] = 100000;
            } elseif ($change === 'employee') {
                $run['employees'][0]['employeeId'] = 999999;
            } else {
                $run['automation'] = true;
            }
            $this->postJson(route('sheets.database.save'), ['state' => CompanyLedgerService::emptyState(), 'payroll' => [$run], 'revision' => 0, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_monthly_semimonthly_and_weekly_reference_tables_use_integer_centavos(): void
    {
        $service = app(PayrollWithholdingReview::class);
        $employee = $this->payrollRun(1)['employees'][0];
        $this->assertSame(100755, $service->compare($employee, 'monthly', '2026-10-30')['reference']);
        $this->assertSame(311410, $service->compare($employee, 'semi_monthly', '2026-10-30')['reference']);
        $this->assertSame(501245, $service->compare($employee, 'weekly', '2026-10-30')['reference']);
        $this->assertTrue($service->compare([...$employee, 'withholding' => '1007.55'], 'monthly', '2026-12-30')['needs_review']);
    }

    public function test_selected_followups_are_atomic_company_scoped_and_idempotent_without_posting_payroll(): void
    {
        [$company, $user] = $this->book();
        $item = ['request_id' => (string) Str::uuid(), 'party' => 'Sample Employee', 'transaction_date' => '2026-10-30', 'reference' => 'Client payroll', 'amount' => '30000.00', 'request_text' => 'Please clarify actual withholding of 1000.00 versus reference 1007.55.'];
        $route = route('follow-ups.batch', $company->id);
        $this->actingAs($user)->postJson($route, ['items' => [$item]])->assertOk()->assertJsonPath('count', 1);
        $this->postJson($route, ['items' => [$item]])->assertOk();
        $this->assertDatabaseCount('client_follow_ups', 1);
        $next = [...$item, 'request_id' => (string) Str::uuid(), 'party' => 'Another Employee'];
        $duplicate = [...$item, 'request_id' => (string) Str::uuid()];
        $this->postJson($route, ['items' => [$next, $duplicate]])->assertUnprocessable();
        $this->assertDatabaseCount('client_follow_ups', 1);
        $this->actingAs(User::factory()->firm()->create())->postJson($route, ['items' => [$next]])->assertNotFound();
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_safe_payroll_import_preview_has_no_database_writes_and_is_hidden_in_production(): void
    {
        $this->get(route('workspace.preview', ['page' => 'payroll-import']))->assertOk()->assertSee('POSTING AND FOLLOW-UP SAVING DISABLED');
        $this->assertDatabaseCount('company_ledgers', 0);
        $this->app->instance('env', 'production');
        $this->get(route('workspace.preview', ['page' => 'payroll-import']))->assertNotFound();
    }

    /** @return array{Company, User, Employee} */
    private function book(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->firm()->create();
        $company->users()->attach($user, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => $company->name, 'full_name' => 'Example', 'vat_type' => 'vat', 'tin' => '000000000', 'branch_code' => '00000']]);
        ChartAccount::seedWorkspace($company->workspaceKey());
        $employee = Employee::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => 'Sample Employee']);

        return [$company, $user, $employee];
    }

    /** @return array<string, mixed> */
    private function payrollRun(int $employeeId): array
    {
        return ['id' => 'import-one', 'number' => 'PYRJ-000001', 'date' => '2026-10-30', 'periodStart' => '2026-10-01', 'periodEnd' => '2026-10-31', 'status' => 'posted', 'automation' => false, 'frequency' => 'monthly', 'clientPayrollImport' => true, 'importReviewVersion' => 1, 'importBatchKey' => str_repeat('b', 64), 'employees' => [[
            'employeeId' => $employeeId, 'name' => 'Sample Employee', 'payType' => 'Taxable', 'basic' => '30000.00', 'taxableAllowance' => '0.00', 'deMinimis' => '0.00', 'benefits' => '0.00', 'sssEe' => '1500.00', 'phicEe' => '750.00', 'hdmfEe' => '200.00', 'withholding' => '1000.00', 'sssEr' => '0.00', 'phicEr' => '0.00', 'hdmfEr' => '0.00', 'taxableBenefits' => '0.00', 'reviewBasis' => 'regular', 'withholdingReview' => ['version' => 'BIR-2023-periodic-v1', 'reference' => 100755, 'difference' => -755, 'acknowledged' => false],
        ]]];

    }
}
