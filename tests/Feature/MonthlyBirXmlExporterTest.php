<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\CompanyLedgerService;
use App\Services\Tax\Bir1601CPrintData;
use App\Services\Tax\BirExporterFactory;
use App\Services\Tax\BirPayrollLedgerMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MonthlyBirXmlExporterTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function profile(): array
    {
        return ['company_name' => 'DEMO MONTHLY CORP.', 'tin' => '001002003', 'branch_code' => '00001', 'rdo' => '033', 'street' => '12 TEST STREET', 'city' => 'Makati', 'zip_code' => '1209', 'contact_number' => '09171234567', 'contact_email' => 'monthly@example.test', 'agent_category' => 'private', 'line_of_business' => 'CONSULTING SERVICES', 'entity_type' => 'corporation', 'vat_type' => 'vat'];
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return ['taxpayer' => $this->profile(), 'period' => ['from' => '2026-08-01', 'to' => '2026-08-31', 'year' => 2026, 'month' => 8], 'calculation' => ['tax' => 500000, 'additional_tax' => 0, 'credits' => 0, 'surcharge' => 10000, 'interest' => 5000, 'compromise' => 2500], 'withholding' => [['atc' => 'WC100', 'base' => 10000000, 'rate' => 50000, 'withheld' => 500000]]];
    }

    /** @return array<string, int> */
    private function payroll(): array
    {
        return ['total_compensation' => 4000000, 'minimum_wage' => 0, 'mwe_premium' => 0, 'exempt_benefits' => 0, 'de_minimis' => 0, 'mandatory_contributions' => 300000, 'other_exempt' => 0, 'no_withholding_compensation' => 0, 'tax_withheld' => 260840];
    }

    public function test_0619e_preserves_the_offline_layout_and_reconciles_original_remittance_and_penalties(): void
    {
        $exporter = app(BirExporterFactory::class)->make('0619E');
        $prepared = $exporter->prepare($this->context());
        $this->assertSame('00100200300001-0619E-082026.xml', $prepared['filename']);
        $fields = $prepared['fields'];
        $this->assertCount(58, $fields);
        foreach (['txtMonth' => '08', 'txtDueMonth' => '09', 'txtDueDay' => '10', 'txtDueYear' => '2026', 'txtAtc' => 'WME10', 'txtTIN1' => '001', 'txtBranchCode' => '00001', 'txtLineBus' => 'CONSULTING%2520SERVICES', 'txtTax14' => '5,000.00', 'txtTax15' => '0.00', 'txtTax16' => '5,000.00', 'txtTax17D' => '175.00', 'txtTax18' => '5,175.00', 'optWithheld:Y' => 'true'] as $key => $expected) {
            $this->assertSame($expected, $fields['frm0619E:'.$key]);
        }
        $text = implode('', iterator_to_array($exporter->stream($prepared)));
        $this->assertStringStartsWith("<?xml version='1.0'?>            <div>frm0619E:txtMonth=08frm0619E:txtMonth=</div>            ", $text);
        $this->assertStringNotContainsString("\n", $text);
        $this->assertStringEndsWith('                        All Rights Reserved BIR 2012.0', $text);
        $this->assertSame('1', $fields['txtFinalFlag']);
        $this->assertSame('Y', $fields['txtEnroll']);
        $this->assertSame('', $fields['ebirOnlineSecret']);
        $this->assertDuplicateKeys($text, array_keys($fields));
    }

    public function test_1601c_uses_actual_withholding_and_explicitly_reviewed_compensation_buckets(): void
    {
        $exporter = app(BirExporterFactory::class)->make('1601-C');
        $context = [...$this->context(), 'payroll' => $this->payroll(), 'options' => ['payroll_reviewed' => true], 'calculation' => ['tax' => 260840]];
        $prepared = $exporter->prepare($context);
        $this->assertSame('00100200300001-1601Cv2018-082026.xml', $prepared['filename']);
        $this->assertCount(100, $prepared['fields']);
        foreach (['txtATC' => 'WW010', 'txtTax14' => '40,000.00', 'txtTax19' => '3,000.00', 'txtTax21' => '3,000.00', 'txtTax22' => '37,000.00', 'txtTax24' => '37,000.00', 'txtTax25' => '2,608.40', 'txtTax26' => '0.00', 'txtTax27' => '2,608.40', 'txtTax36' => '2,608.40', 'TaxWithheld_1' => 'true', 'txtPg2TIN1' => '001', 'txtPg2TaxpayerName' => 'DEMO MONTHLY CORP.', 'txtLineBus' => 'CONSULTING%20SERVICES'] as $key => $expected) {
            $this->assertSame($expected, $prepared['fields']['frm1601c:'.$key]);
        }
        $this->assertArrayNotHasKey('txtFinalFlag', $prepared['fields']);
        $text = implode('', iterator_to_array($exporter->stream($prepared)));
        $this->assertStringStartsWith("<?xml version='1.0'?>\t\r\n            <div>frm1601c:txtMonth=08frm1601c:txtMonth=</div>\t\r\n            ", $text);
        $this->assertStringEndsWith("\t\r\n            \t\r\n            All Rights Reserved BIR 2012.0", $text);
        $this->assertDuplicateKeys($text, array_keys($prepared['fields']));
        $nil = $exporter->prepare([...$context, 'payroll' => array_fill_keys(array_keys($this->payroll()), 0), 'calculation' => ['tax' => 0]]);
        $this->assertSame('true', $nil['fields']['frm1601c:TaxWithheld_2']);
    }

    public function test_monthly_exports_reject_partial_periods_wrong_months_and_unmapped_adjustments(): void
    {
        $exporter = app(BirExporterFactory::class)->make('0619-E');
        foreach ([['period' => [...$this->context()['period'], 'to' => '2026-08-30']], ['period' => ['from' => '2026-09-01', 'to' => '2026-09-30', 'year' => 2026, 'month' => 9]], ['calculation' => ['tax' => 500000, 'credits' => 1]], ['calculation' => ['tax' => 500000, 'additional_tax' => 1]], ['calculation' => ['tax' => 1]]] as $override) {
            try {
                $exporter->prepare([...$this->context(), ...$override]);
                $this->fail('An invalid monthly export was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_month_boundaries_and_nil_withholding_flags_are_explicit(): void
    {
        $factory = app(BirExporterFactory::class);
        $february = $factory->make('0619-E')->prepare([...$this->context(), 'period' => ['from' => '2024-02-01', 'to' => '2024-02-29', 'year' => 2024, 'month' => 2], 'withholding' => [], 'calculation' => ['tax' => 0]]);
        $this->assertSame('00100200300001-0619E-022024.xml', $february['filename']);
        $this->assertSame('false', $february['fields']['frm0619E:optWithheld:Y']);
        $this->assertSame('true', $february['fields']['frm0619E:optWithheld:N']);
        $this->assertSame('0.00', $february['fields']['frm0619E:txtTax18']);
        $december = $factory->make('1601-C')->prepare([...$this->context(), 'period' => ['from' => '2026-12-01', 'to' => '2026-12-31', 'year' => 2026, 'month' => 12], 'payroll' => $this->payroll(), 'options' => ['payroll_reviewed' => true], 'calculation' => ['tax' => 260840]]);
        $this->assertSame('00100200300001-1601Cv2018-122026.xml', $december['filename']);
    }

    public function test_payroll_review_is_required_and_compensation_cannot_be_overallocated(): void
    {
        $exporter = app(BirExporterFactory::class)->make('1601-C');
        $context = [...$this->context(), 'payroll' => $this->payroll(), 'calculation' => ['tax' => 260840]];
        foreach ([['options' => []], ['options' => ['payroll_reviewed' => true], 'payroll' => [...$this->payroll(), 'minimum_wage' => 4000000]], ['options' => ['payroll_reviewed' => true], 'payroll' => [...$this->payroll(), 'no_withholding_compensation' => 3700000]], ['options' => ['payroll_reviewed' => true], 'payroll' => [...$this->payroll(), 'other_exempt' => 1]]] as $override) {
            try {
                $exporter->prepare([...$context, ...$override]);
                $this->fail('An invalid compensation classification was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_payroll_mapper_excludes_unposted_rows_preserves_actual_deductions_and_does_not_infer_item_23(): void
    {
        $employee = ['payType' => 'Taxable', 'basic' => '40000.00', 'taxableAllowance' => '0.00', 'deMinimis' => '1000.00', 'benefits' => '10000.00', 'taxableBenefits' => '2000.00', 'sssEe' => '2000.00', 'phicEe' => '900.00', 'hdmfEe' => '100.00', 'withholding' => '3008.40'];
        $run = ['id' => 'run-1', 'status' => 'posted', 'date' => '2026-08-15', 'employees' => [$employee]];
        $mapped = app(BirPayrollLedgerMapper::class)->map([$run, [...$run, 'id' => 'draft', 'status' => 'draft'], [...$run, 'id' => 'void', 'status' => 'void'], [...$run, 'id' => 'outside', 'date' => '2026-09-01']], $this->context()['period']);
        $this->assertSame(5100000, $mapped['values']['total_compensation']);
        $this->assertSame(800000, $mapped['values']['exempt_benefits']);
        $this->assertSame(300000, $mapped['values']['mandatory_contributions']);
        $this->assertSame(300840, $mapped['values']['tax_withheld']);
        $this->assertSame(0, $mapped['values']['no_withholding_compensation']);
        $this->assertSame([], $mapped['issues']);
        $this->assertNotEmpty(app(BirPayrollLedgerMapper::class)->map([$run, $run], $this->context()['period'])['issues']);
        $this->assertNotEmpty(app(BirPayrollLedgerMapper::class)->map([[...$run, 'employees' => [['basic' => '1.00']]]], $this->context()['period'])['issues']);
    }

    public function test_monthly_endpoint_freezes_exports_and_never_marks_them_filed(): void
    {
        $owner = User::factory()->firm()->create(['must_change_password' => false]);
        $company = Company::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => [...$this->profile(), 'full_name' => $owner->name]]);
        $state = CompanyLedgerService::emptyState();
        $state['wtpCertificates'] = [['id' => 1, 'date' => '2026-08-15', 'status' => 'posted', 'number' => 'WTP-1', 'ewtCode' => 'WC100', 'ewt' => '5.00', 'taxBase' => 10000000]];
        $employee = ['payType' => 'Taxable', 'basic' => '40000.00', 'taxableAllowance' => '0.00', 'deMinimis' => '0.00', 'benefits' => '0.00', 'sssEe' => '2000.00', 'phicEe' => '900.00', 'hdmfEe' => '100.00', 'withholding' => '2608.40'];
        $ledger = CompanyLedger::factory()->create(['company_id' => $company->id, 'revision' => 1, 'state' => $state, 'payroll' => [['id' => 'run-1', 'number' => 'PYRJ-1', 'status' => 'posted', 'date' => '2026-08-15', 'employees' => [$employee]]]]);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->withHeader('X-Ledger-Revision', '1');
        foreach (['0619-E', '1601-C'] as $form) {
            $selection = ['form' => $form, 'year' => 2026, 'period' => 8];
            $preview = $this->getJson(route('tax-returns.preview', ['company_id' => $company->id, ...$selection]))->assertOk()->assertJsonPath('xml_capable', true)->json();
            $input = [...$selection, 'request_id' => (string) Str::uuid(), 'ledger_revision' => 1, 'source_hash' => $preview['source_hash'], 'additional_tax' => '0.00', 'credits' => '0.00', 'surcharge' => '0.00', 'interest' => '0.00', 'compromise' => '0.00', 'reviewed' => true, 'review_note' => 'Reconciled to posted monthly sources.', 'xml_requested' => true, 'xml_line_of_business' => 'CONSULTING SERVICES', 'xml_payroll_reviewed' => true];
            foreach ($this->payroll() as $key => $value) {
                if (! in_array($key, ['total_compensation', 'tax_withheld'], true)) {
                    $input['xml_payroll_'.$key] = number_format($value / 100, 2, '.', '');
                }
            }
            $url = route('tax-returns.prepare', ['company_id' => $company->id]);
            $this->postJson($url, [...$input, 'amended' => true])->assertUnprocessable();
            if ($form === '1601-C') {
                $this->postJson($url, [...$input, 'xml_payroll_reviewed' => false])->assertUnprocessable();
                $this->postJson($url, [...$input, 'xml_payroll_mandatory_contributions' => '999999.00'])->assertUnprocessable();
            }
            $id = $this->postJson($url, $input)->assertCreated()->json('id');
            if ($form === '1601-C') {
                $print = $this->get(route('tax-returns.show', ['company_id' => $company->id, 'id' => $id]))->assertOk()
                    ->assertViewIs('tax-return-1601c-print')
                    ->assertSee('bir-1601c-jan2018-page1.png')->assertSee('bir-1601c-jan2018-page2.png')
                    ->assertSee('data-bir-amount="14" data-cents="4000000"', false)
                    ->assertSee('data-bir-amount="19" data-cents="300000"', false)
                    ->assertSee('data-bir-amount="24" data-cents="3700000"', false)
                    ->assertSee('data-bir-amount="36" data-cents="260840"', false);
                $this->assertCount(23, $print->viewData('printData')['amounts']);
                $record = DB::table('tax_return_preparations')->find($id);
                $snapshot = json_decode($record->snapshot, true, 512, JSON_THROW_ON_ERROR);
                $unmapped = $snapshot;
                unset($unmapped['bir_xml']);
                $this->assertFalse(app(Bir1601CPrintData::class)->build($record, $unmapped)['mapped']);
                $unmapped = $snapshot;
                $unmapped['bir_xml']['fields']['frm1601c:txtTax21'] = '0.00';
                $this->assertFalse(app(Bir1601CPrintData::class)->build($record, $unmapped)['mapped']);
                $unmapped = $snapshot;
                $unmapped['calculation']['credits'] = 100;
                $this->assertFalse(app(Bir1601CPrintData::class)->build($record, $unmapped)['mapped']);
                CompanySetting::query()->where('company_id', $company->id)->update(['profile' => [...$this->profile(), 'company_name' => 'CHANGED LIVE COMPANY']]);
                $this->get(route('tax-returns.show', ['company_id' => $company->id, 'id' => $id]))->assertOk()->assertSee('DEMO MONTHLY CORP.')->assertDontSee('CHANGED LIVE COMPANY');
            }
            $this->postJson($url, $input)->assertOk()->assertJsonPath('replayed', true);
            $xmlUrl = route('tax-returns.xml', ['company_id' => $company->id, 'id' => $id]);
            $text = $this->get($xmlUrl)->assertOk()->assertHeader('X-BIR-Validation', 'not-verified')->streamedContent();
            $ledger->update(['revision' => 2]);
            $this->assertSame($text, $this->get($xmlUrl)->assertOk()->streamedContent());
            $ledger->update(['revision' => 1]);
            $this->assertDatabaseHas('tax_return_preparations', ['id' => $id, 'status' => 'prepared']);
        }
    }

    /** @param list<string> $keys */
    private function assertDuplicateKeys(string $text, array $keys): void
    {
        preg_match_all('/<div>(.*?)<\/div>/s', $text, $matches);
        $this->assertCount(count($keys), $matches[1]);
        foreach ($matches[1] as $index => $row) {
            $this->assertStringStartsWith($keys[$index].'=', $row);
            $this->assertStringEndsWith($keys[$index].'=', $row);
        }
    }
}
