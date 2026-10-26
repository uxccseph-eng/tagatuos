<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Services\Form2316Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Form2316Test extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function company(): array
    {
        return ['company_name' => 'Form 2316 QA', 'full_name' => 'Test Owner', 'tin' => '123456789', 'branch_code' => '00000', 'street' => 'Test Street', 'city' => 'Test City', 'zip_code' => '1000', 'signatory_name' => 'Default Officer', 'signatory_title' => 'Finance Manager', 'signatory_tin' => '123-456-789-00000'];
    }

    private function employee(): Employee
    {
        $this->withSession(['preview_access' => true, 'company_setup' => $this->company()]);

        return Employee::factory()->create(['workspace_key' => ChartAccount::workspaceKey($this->company()), 'name' => 'Employee, Test Sample', 'tin' => '234-567-890-00000', 'address' => 'Second Street, Test City', 'birth_date' => '1990-02-03', 'phone' => '09170000000']);
    }

    /** @return array<string, mixed> */
    private function payload(Employee $employee): array
    {
        $row = ['source_id' => 'payroll-1:0', 'employee_id' => $employee->id, 'status' => 'posted', 'date' => '2026-10-15', 'pay_type' => 'Taxable', 'basic' => '150000.00', 'allowance' => '10000.00', 'de_minimis' => '5000.00', 'benefits' => '50000.00', 'taxable_benefits' => '0.00', 'contributions' => '6000.00', 'withheld' => '5000.00'];

        return ['year' => 2026, 'reviewed' => true, 'use_default_signatory' => true, 'employee_name' => $employee->name, 'employer_type' => 'main', 'has_previous' => false, 'previous_taxable' => '0.00', 'previous_withheld' => '0.00', 'adjusted_withheld' => '10200.00', 'pera_credit' => '0.00', 'records' => [$row, [...$row, 'source_id' => 'payroll-2:0', 'date' => '2026-12-15']]];
    }

    public function test_annual_amounts_reconcile_and_apply_the_benefit_limit_without_changing_records(): void
    {
        $employee = $this->employee();
        $payload = $this->payload($employee);
        $view = app(Form2316Service::class)->prepare($payload, $this->company(), $employee);
        $this->assertSame(43000000, $view['amounts'][19]);
        $this->assertSame(11200000, $view['amounts'][20]);
        $this->assertSame(31800000, $view['amounts'][21]);
        $this->assertSame(9000000, $view['amounts'][34]);
        $this->assertSame(1000000, $view['amounts'][48]);
        $this->assertSame(1020000, $view['amounts'][24]);
        $this->assertSame(1000000, $view['recordedWithheld']);
        $this->assertSame(1020000, $view['amounts'][25]);
        $this->assertSame($this->payload($employee), $payload);
    }

    public function test_non_taxable_basic_uses_the_official_exempt_basic_line_and_employee_contributions_only(): void
    {
        $employee = $this->employee();
        $data = $this->payload($employee);
        $data['records'] = [[...$data['records'][0], 'pay_type' => 'Non-taxable', 'basic' => '20000.00', 'allowance' => '0.00', 'de_minimis' => '0.00', 'benefits' => '0.00', 'taxable_benefits' => '0.00', 'contributions' => '1700.00', 'withheld' => '0.00']];
        $view = app(Form2316Service::class)->prepare($data, $this->company(), $employee);
        $this->assertSame(1830000, $view['amounts'][29]);
        $this->assertSame(170000, $view['amounts'][36]);
        $this->assertSame(2000000, $view['amounts'][38]);
        $this->assertSame(0, $view['amounts'][52]);
    }

    public function test_real_pdf_is_one_official_size_page_and_preview_uses_the_same_details(): void
    {
        $employee = $this->employee();
        $url = '/reports/form-2316/'.$employee->id;
        $response = $this->postJson($url, $this->payload($employee))->assertOk()->assertDownload('BIR-2316-'.$employee->id.'-2026.pdf')->assertHeader('Content-Type', 'application/pdf');
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(1, preg_match_all('~/Type /Page\b~', $pdf));
        $this->assertMatchesRegularExpression('~/MediaBox \[0(?:\.0+)? 0(?:\.0+)? 612(?:\.0+)? 936(?:\.0+)?\]~', $pdf);
        file_put_contents(sys_get_temp_dir().'/tagatuos-form2316-test.pdf', $pdf);
        $preview = $this->postJson($url, [...$this->payload($employee), 'preview' => true, 'reviewed' => false])->assertOk()->assertJsonPath('draft', true)->assertJsonPath('tax_due', 10200);
        $this->assertStringStartsWith('%PDF-', base64_decode($preview->json('pdf'), true));
        $this->assertStringContainsString('DRAFT — REVIEW', $preview->json('html'));
        $this->assertTrue(str_contains($preview->json('html'), 'DEFAULT OFFICER'));
    }

    public function test_previous_employer_and_custom_signatory_are_export_only(): void
    {
        $employee = $this->employee();
        $data = [...$this->payload($employee), 'has_previous' => true, 'previous_name' => 'Prior Employer', 'previous_tin' => '345-678-901-00000', 'previous_address' => 'Prior Street', 'previous_taxable' => '100000.00', 'previous_withheld' => '5000.00', 'use_default_signatory' => false, 'signatory_name' => 'Custom Officer', 'signatory_title' => 'Accountant'];
        $view = app(Form2316Service::class)->prepare($data, $this->company(), $employee);
        $this->assertSame(41800000, $view['amounts'][23]);
        $this->assertSame(2610000, $view['amounts'][24]);
        $this->assertSame(1520000, $view['amounts'][26]);
        $this->postJson('/reports/form-2316/'.$employee->id, [...$data, 'preview' => true])->assertOk();
        $this->assertSame('Default Officer', session('company_setup.signatory_name'));
        $this->assertSame('Employee, Test Sample', $employee->fresh()->name);
    }

    public function test_default_signatory_uses_current_saved_settings(): void
    {
        $employee = $this->employee();
        CompanySetting::query()->create(['workspace_key' => ChartAccount::workspaceKey($this->company()), 'lookup_key' => ChartAccount::workspaceKey($this->company()), 'profile' => [...$this->company(), 'signatory_name' => 'Updated Default'], 'preferences' => []]);
        $response = $this->postJson('/reports/form-2316/'.$employee->id, [...$this->payload($employee), 'preview' => true])->assertOk();
        $this->assertStringContainsString('UPDATED DEFAULT', $response->json('html'));
    }

    public function test_missing_review_and_foreign_or_invalid_payroll_are_rejected(): void
    {
        $employee = $this->employee();
        $payload = $this->payload($employee);
        $url = '/reports/form-2316/'.$employee->id;
        $this->postJson($url, [...$payload, 'reviewed' => false])->assertUnprocessable()->assertJsonValidationErrors('reviewed');
        foreach (['date' => '2025-10-15', 'employee_id' => $employee->id + 1, 'status' => 'draft', 'taxable_benefits' => '60000.00'] as $field => $invalid) {
            $data = $payload;
            $data['records'][0][$field] = $invalid;
            $this->postJson($url, $data)->assertUnprocessable();
        }
        $data = $payload;
        $data['records'][1]['source_id'] = $data['records'][0]['source_id'];
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('records.1.source_id');
        $foreign = Employee::factory()->create();
        $this->postJson('/reports/form-2316/'.$foreign->id, $payload)->assertNotFound();
        $this->withSession(['preview_access' => false])->postJson($url, $payload)->assertForbidden();
    }

    public function test_missing_employee_address_is_not_replaced_with_sample_details(): void
    {
        $employee = $this->employee();
        $employee->update(['address' => '']);
        $this->postJson('/reports/form-2316/'.$employee->id, [...$this->payload($employee), 'preview' => true])->assertUnprocessable()->assertJsonValidationErrors('employee');
    }
}
