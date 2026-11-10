<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\User;
use App\PayrollColumns;
use App\Services\CompanyLedgerService;
use App\Services\Tax\BirPayrollLedgerMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PayrollColumnsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Company, User, Employee} */
    private function book(): array
    {
        $owner = User::factory()->firm()->create(['must_change_password' => false]);
        $company = Company::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => $company->name, 'full_name' => 'Example', 'tin' => '123456789', 'branch_code' => '00000', 'rdo' => '033'], 'preferences' => array_fill_keys(array_map(fn (string $group): string => 'payroll_columns_'.$group, array_keys(PayrollColumns::groups())), false)]);
        ChartAccount::seedWorkspace($company->workspaceKey());
        $employee = Employee::factory()->create(['workspace_key' => $company->workspaceKey()]);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->withHeader('X-Company-ID', (string) $company->id);

        return [$company, $owner, $employee];
    }

    public function test_settings_save_each_column_group_per_company_and_preserve_omitted_choices(): void
    {
        [$company, $owner] = $this->book();
        $base = ['payroll' => 1, 'cwt' => 1, 'wtp' => 1, 'ppe' => 1];
        $groups = array_fill_keys(array_map(fn (string $group): string => 'payroll_columns_'.$group, array_keys(PayrollColumns::groups())), 1);
        $url = route('settings.section', ['section' => 'sheets', 'company_id' => $company->id]);
        $this->put($url, [...$base, ...$groups])->assertSessionHasNoErrors();
        $preferences = CompanySetting::query()->where('company_id', $company->id)->sole()->preferences;
        foreach ($groups as $key => $_) {
            $this->assertTrue($preferences[$key]);
        }
        $this->put($url, $base)->assertSessionHasNoErrors();
        $this->assertTrue(CompanySetting::query()->where('company_id', $company->id)->sole()->preferences['payroll_columns_premium']);
        $this->putJson($url, [...$base, 'payroll_columns_premium' => 'bad'])->assertUnprocessable();
        $other = Company::factory()->create();
        $other->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $other->workspaceKey(), 'lookup_key' => $other->workspaceKey(), 'preferences' => []]);
        $this->withHeader('X-Company-ID', (string) $other->id)->get(route('settings.section', ['section' => 'sheets', 'company_id' => $other->id]))->assertOk()->assertSee('Payroll columns')->assertSee('GSIS, Union Dues, PERA');
        $this->assertSame([], CompanySetting::query()->where('company_id', $other->id)->sole()->preferences);
    }

    public function test_hidden_columns_still_post_earnings_gsis_and_union_dues_in_a_balanced_journal(): void
    {
        [$company, , $employee] = $this->book();
        $amounts = array_fill_keys([...PayrollColumns::CORE, ...array_keys(PayrollColumns::extras())], '0.00');
        $employeeRow = [...$amounts, 'name' => $employee->name, 'payType' => 'Taxable', 'basic' => '1000.00', 'holidayPay' => '100.00', 'otherNonTaxable' => '50.00', 'pera' => '25.00', 'gsisEe' => '20.00', 'gsisEr' => '30.00', 'unionDues' => '5.00', 'withholding' => '10.00'];
        $run = ['id' => 'payroll-columns-test', 'number' => '', 'date' => '2026-11-04', 'periodStart' => '2026-11-01', 'periodEnd' => '2026-11-30', 'status' => 'posted', 'employees' => [$employeeRow], 'automation' => false];
        $this->postJson(route('sheets.database.save', ['company_id' => $company->id]), ['state' => CompanyLedgerService::emptyState(), 'payroll' => [$run], 'revision' => 0, 'request_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('payroll.0.employees.0.holidayPay', '100.00');
        $this->assertEquals(1205, DB::table('journal_entry_lines')->sum('debit'));
        $this->assertEquals(DB::table('journal_entry_lines')->sum('debit'), DB::table('journal_entry_lines')->sum('credit'));
        foreach (['7210' => ['1100.0000', '0.0000'], '7230' => ['75.0000', '0.0000'], '7270' => ['30.0000', '0.0000'], '2360' => ['0.0000', '50.0000'], '2370' => ['0.0000', '5.0000'], '2340' => ['0.0000', '1140.0000']] as $code => [$debit, $credit]) {
            $account = ChartAccount::query()->where('workspace_key', $company->workspaceKey())->where('code', $code)->sole();
            $this->assertDatabaseHas('journal_entry_lines', ['chart_account_id' => $account->id, 'debit' => $debit, 'credit' => $credit]);
        }
        $this->assertFalse(CompanySetting::query()->where('company_id', $company->id)->sole()->preferences['payroll_columns_premium']);
    }

    public function test_monthly_payroll_mapping_reads_detailed_mwe_premiums_and_exempt_compensation(): void
    {
        $row = [...array_fill_keys([...PayrollColumns::CORE, ...array_keys(PayrollColumns::extras())], '0.00'), 'payType' => 'Minimum Wage Earner', 'basic' => '20000.00', 'holidayPay' => '1000.00', 'overtimePay' => '500.00', 'pera' => '100.00', 'otherNonTaxable' => '200.00'];
        $mapped = app(BirPayrollLedgerMapper::class)->map([['id' => 'run', 'status' => 'posted', 'date' => '2026-11-04', 'employees' => [$row]]], ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertSame([], $mapped['issues']);
        $this->assertSame(2180000, $mapped['values']['total_compensation']);
        $this->assertSame(150000, $mapped['values']['mwe_premium']);
        $this->assertSame(30000, $mapped['values']['other_exempt']);
    }

    public function test_kpi_and_commission_are_preserved_separately_and_post_once_to_the_books_and_monthly_tax_totals(): void
    {
        [$company, , $employee] = $this->book();
        $row = [...array_fill_keys([...PayrollColumns::CORE, ...array_keys(PayrollColumns::extras())], '0.00'), 'name' => $employee->name, 'payType' => 'Taxable', 'basic' => '1000.00', 'kpiIncentive' => '100.00', 'commission' => '200.00'];
        $run = ['id' => 'payroll-incentives-test', 'number' => '', 'date' => '2026-11-04', 'periodStart' => '2026-11-01', 'periodEnd' => '2026-11-30', 'status' => 'posted', 'employees' => [$row], 'automation' => false];
        $this->postJson(route('sheets.database.save', ['company_id' => $company->id]), ['state' => CompanyLedgerService::emptyState(), 'payroll' => [$run], 'revision' => 0, 'request_id' => (string) Str::uuid()])->assertOk()
            ->assertJsonPath('payroll.0.employees.0.basic', '1000.00')
            ->assertJsonPath('payroll.0.employees.0.kpiIncentive', '100.00')
            ->assertJsonPath('payroll.0.employees.0.commission', '200.00');
        $this->assertEquals(1300, DB::table('journal_entry_lines')->sum('debit'));
        $this->assertEquals(1300, DB::table('journal_entry_lines')->sum('credit'));
        $mapped = app(BirPayrollLedgerMapper::class)->map([$run], ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertSame([], $mapped['issues']);
        $this->assertSame(130000, $mapped['values']['total_compensation']);
    }
}
