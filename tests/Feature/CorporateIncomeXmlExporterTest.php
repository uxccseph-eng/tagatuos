<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\Tax\BirExporterFactory;
use App\Services\Tax\CorporateIncomeWorksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CorporateIncomeXmlExporterTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $form): array
    {
        $inputs = [];
        foreach (app(CorporateIncomeWorksheet::class)->inputs($form) as $key => $definition) {
            $inputs[$key] = ($definition['type'] ?? '') === 'text' ? '' : '0.00';
        }
        $inputs = [...$inputs, 'rate' => '25', 'mcit' => 'not_applicable', 'method' => 'itemized', 'small_eligible' => false, 'sales' => '1000000', 'cost' => '400000', 'credit_cwt_current' => '20000'];
        if ($form === '1702Q') {
            $inputs['deductions'] = '100000';
        } else {
            $inputs['expense_13'] = '100000';
            $inputs['book_net'] = '500000';
            $inputs['incorporation_date'] = '2020-10-20';
        }

        return ['taxpayer' => ['company_name' => 'DEMO CORPORATE TAX INC.', 'tin' => '001002003', 'branch_code' => '00001', 'rdo' => '033', 'street' => '12 TEST STREET', 'city' => 'Makati', 'zip_code' => '1209', 'contact_number' => '09171234567', 'contact_email' => 'corporate@example.test', 'entity_type' => 'corporation', 'accounting_period' => 'calendar', 'vat_type' => 'vat'], 'period' => ['from' => '2026-01-01', 'to' => $form === '1702Q' ? '2026-03-31' : '2026-12-31', 'year' => 2026, 'quarter' => 1], 'annual_settings' => ['settings' => ['regime' => 'regular', 'deduction_method' => 'itemized', 'mcit_start_year' => null]], 'corporate_input' => $inputs, 'options' => ['corporate_reviewed' => true, 'overpayment_action' => 'carry_over'], 'calculation' => ['tax' => 12500000, 'credits' => 2000000, 'additional_tax' => 0, 'surcharge' => 0, 'interest' => 0, 'compromise' => 0]];
    }

    public function test_quarterly_layout_preserves_the_duplicate_field_and_reconciles_regular_tax(): void
    {
        $exporter = app(BirExporterFactory::class)->make('1702Q');
        $prepared = $exporter->prepare($this->context('1702Q'));
        $this->assertSame('00100200300001-1702Qv2018C-2026Q1.xml', $prepared['filename']);
        $this->assertCount(112, $prepared['fields']);
        foreach (['txtTax14' => '125,000.00', 'txtTax19' => '20,000.00', 'txtTax25' => '105,000.00', 'Sched2:txtTax9' => '500,000.00', 'Sched2:txtTax10' => '25.00', 'Sched4:txtTax5' => '20,000.00', 'txtTaxpayerName1' => 'DEMO%20CORPORATE%20TAX%20INC.'] as $key => $expected) {
            $this->assertSame($expected, $prepared['fields']['frm1702q:'.$key]);
        }
        $text = implode('', iterator_to_array($exporter->stream($prepared)));
        $this->assertStringStartsWith("<?xml version='1.0'?>        <div>frm1702q:rbForClndrFscl_1=truefrm1702q:rbForClndrFscl_1=</div>", $text);
        $this->assertSame(2, substr_count($text, '<div>frm1702q:txtTelNum='));
        $this->assertStringEndsWith('All Rights Reserved BIR 2012.', $text);
        $this->assertStringNotContainsString('731214454', $text);
        $this->assertSame('1', $prepared['fields']['txtFinalFlag']);
        $this->assertSame('Y', $prepared['fields']['txtEnroll']);
    }

    public function test_annual_layout_reconciles_all_four_pages_and_keeps_payment_and_signatures_unfilled(): void
    {
        $exporter = app(BirExporterFactory::class)->make('1702-RT');
        $prepared = $exporter->prepare($this->context('1702-RT'));
        $this->assertSame('00100200300001-1702RTv2018C-122026.xml', $prepared['filename']);
        $this->assertCount(258, $prepared['fields']);
        foreach (['txtPg1Pt2I14IncomeTax' => '125,000', 'txtPg1Pt2I21TotalAmount' => '105,000', 'txtPg2Pt4I39NetTaxable' => '500,000', 'txtPg2Pt4I34OrdinaryAllowable' => '100,000', 'txtPg3Sc1I13Salaries' => '100,000', 'txtPg4Sc5I10NetTaxableIncome' => '500,000', 'txtPg1Pt1I10' => '10/20/2020', 'txtPg2RegisteredName' => 'DEMO CORPORATE TAX INC.', 'txtSignaturePresident' => ''] as $key => $expected) {
            $this->assertSame($expected, $prepared['fields']['frm1702RT:'.$key]);
        }
        $text = implode('', iterator_to_array($exporter->stream($prepared)));
        $this->assertStringEndsWith('All Rights Reserved BIR 2014.', $text);
        $this->assertStringNotContainsString('731214454', $text);
        $this->assertSame('00001', $prepared['fields']['txtBranchMaskP4']);
    }

    public function test_osd_mcit_losses_and_fiscal_periods_have_reconciled_mappings(): void
    {
        foreach (['1702Q', '1702-RT'] as $form) {
            $context = $this->context($form);
            $context['annual_settings']['settings']['deduction_method'] = 'osd';
            $context['corporate_input']['method'] = 'osd';
            $context['corporate_input'][$form === '1702Q' ? 'deductions' : 'expense_13'] = '0';
            if ($form === '1702-RT') {
                $context['corporate_input']['book_net'] = '360000';
            }
            $context['calculation']['tax'] = 9000000;
            app(BirExporterFactory::class)->make($form)->prepare($context);
            $this->addToAssertionCount(1);
            $context = $this->context($form);
            $context['corporate_input']['sales'] = '1000';
            $context['corporate_input']['cost'] = '0';
            $context['corporate_input'][$form === '1702Q' ? 'deductions' : 'expense_13'] = '2000';
            $context['corporate_input']['mcit'] = 'applicable';
            $context['corporate_input']['credit_cwt_current'] = '0';
            if ($form === '1702-RT') {
                $context['corporate_input']['book_net'] = '-1000';
            }
            $context['calculation']['tax'] = 2000;
            $context['calculation']['credits'] = 0;
            $prepared = app(BirExporterFactory::class)->make($form)->prepare($context);
            $this->assertSame('true', $prepared['fields'][$form === '1702Q' ? 'frm1702q:rbATC_1' : 'frm1702RT:rdoPg1I5Atc']);
            $context = $this->context($form);
            $context['taxpayer']['accounting_period'] = 'fiscal';
            $context['taxpayer']['fiscal_end_month'] = 6;
            $context['period']['from'] = '2025-07-01';
            $context['period']['to'] = $form === '1702Q' ? '2025-09-30' : '2026-06-30';
            $prepared = app(BirExporterFactory::class)->make($form)->prepare($context);
            $this->assertStringContainsString($form === '1702Q' ? '-2026Q1.xml' : '-062026.xml', $prepared['filename']);
        }
    }

    public function test_small_corporation_rate_and_annual_overpayments_need_explicit_reviewed_choices(): void
    {
        foreach (['1702Q', '1702-RT'] as $form) {
            $context = $this->context($form);
            $context['corporate_input']['rate'] = '20';
            $context['corporate_input']['small_eligible'] = true;
            $context['calculation']['tax'] = 10000000;
            $prepared = app(BirExporterFactory::class)->make($form)->prepare($context);
            $this->assertSame($form === '1702Q' ? 'IC010_20%' : '20', $prepared['fields'][$form === '1702Q' ? 'frm1702q:cbATC_2' : 'frm1702RT:Pg2Pt4I40IncomeTaxRate']);
        }
        $context = $this->context('1702-RT');
        $context['corporate_input']['credit_cwt_current'] = '150000';
        $context['calculation']['credits'] = 15000000;
        $prepared = app(BirExporterFactory::class)->make('1702-RT')->prepare($context);
        $this->assertSame('-25,000', $prepared['fields']['frm1702RT:txtPg1Pt2I21TotalAmount']);
        $this->assertSame('true', $prepared['fields']['frm1702RT:rdoPg1Pt2I21OverpaymentCarried']);
        $context['options']['overpayment_action'] = '';
        $this->expectException(ValidationException::class);
        app(BirExporterFactory::class)->make('1702-RT')->prepare($context);
    }

    public function test_later_quarters_use_cumulative_taxable_income_and_cumulative_mcit_gross_income(): void
    {
        $context = $this->context('1702Q');
        $context['period'] = ['from' => '2026-04-01', 'to' => '2026-06-30', 'year' => 2026, 'quarter' => 2];
        $context['corporate_input']['previous_taxable'] = '500000';
        $context['corporate_input']['gross_q1'] = '600000';
        $context['corporate_input']['credit_regular_previous'] = '125000';
        $context['corporate_input']['mcit'] = 'applicable';
        $context['calculation']['tax'] = 25000000;
        $context['calculation']['credits'] = 14500000;
        $prepared = app(BirExporterFactory::class)->make('1702Q')->prepare($context);
        $this->assertSame('1,000,000.00', $prepared['fields']['frm1702q:Sched2:txtTax9']);
        $this->assertSame('1,200,000.00', $prepared['fields']['frm1702q:Sched3:txtTax4']);
        $this->assertSame('24,000.00', $prepared['fields']['frm1702q:Sched3:txtTax6']);
        $this->assertSame('105,000.00', $prepared['fields']['frm1702q:txtTax25']);
        $context['corporate_input']['gross_q2'] = '1';
        $this->expectException(ValidationException::class);
        app(BirExporterFactory::class)->make('1702Q')->prepare($context);
    }

    public function test_invalid_or_unsupported_corporate_worksheets_cannot_be_exported(): void
    {
        foreach (['missing_review', 'wrong_tax', 'wrong_credits', 'reconciliation', 'fraction', 'unknown_claim', 'wrong_method', 'unconfirmed_rate', 'invalid_date', 'wrong_period', 'wrong_entity'] as $case) {
            $context = $this->context('1702-RT');
            match ($case) {
                'missing_review' => $context['options']['corporate_reviewed'] = false,
                'wrong_tax' => $context['calculation']['tax']++,
                'wrong_credits' => $context['calculation']['credits']++,
                'reconciliation' => $context['corporate_input']['book_net'] = '1',
                'fraction' => $context['corporate_input']['sales'] = '1.25',
                'unknown_claim' => $context['corporate_input']['nolco'] = '100',
                'wrong_method' => $context['corporate_input']['method'] = 'osd',
                'unconfirmed_rate' => $context['corporate_input']['rate'] = '20',
                'invalid_date' => $context['corporate_input']['incorporation_date'] = '2020-02-30',
                'wrong_period' => $context['period']['from'] = '2026-02-01',
                'wrong_entity' => $context['taxpayer']['entity_type'] = 'partnership',
            };
            try {
                app(BirExporterFactory::class)->make('1702-RT')->prepare($context);
                $this->fail('Accepted invalid case: '.$case);
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_preparation_endpoints_archive_and_stream_corporate_xml_without_posting_or_filing(): void
    {
        $context = $this->context('1702Q');
        $owner = User::factory()->firm()->create(['must_change_password' => false]);
        $company = Company::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => [...$context['taxpayer'], 'full_name' => $owner->name]]);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->withHeader('X-Ledger-Revision', '0');
        foreach (['1702Q', '1702-RT'] as $form) {
            $context = $this->context($form);
            $selection = ['company_id' => $company->id, 'form' => $form, 'year' => 2026, 'period' => 1];
            $preview = $this->getJson(route('tax-returns.preview', $selection))->assertOk()->assertJsonPath('xml_capable', true)->json();
            $input = [...$selection, 'request_id' => (string) Str::uuid(), 'ledger_revision' => 0, 'source_hash' => $preview['source_hash'], 'review_note' => 'Reviewed regular corporate tax working papers.', 'reviewed' => true, 'reviewed_tax' => '125000.00', 'credits' => '20000.00', 'additional_tax' => '0.00', 'surcharge' => '0.00', 'interest' => '0.00', 'compromise' => '0.00', 'xml_requested' => true, 'xml_corporate' => $context['corporate_input'], 'xml_corporate_reviewed' => true, 'xml_overpayment_action' => 'carry_over'];
            $response = $this->postJson(route('tax-returns.prepare', ['company_id' => $company->id]), $input);
            $this->assertSame(201, $response->status(), $response->getContent());
            $id = $response->json('id');
            $xml = $this->get(route('tax-returns.xml', ['company_id' => $company->id, 'id' => $id]))->assertOk()->streamedContent();
            $this->assertStringContainsString($form === '1702Q' ? '125,000.00' : '125,000', $xml);
            $this->get(route('tax-returns.show', ['company_id' => $company->id, 'id' => $id]))->assertOk()->assertSee('Reviewed corporate income tax worksheet');
            $csv = $this->get(route('tax-returns.download', ['company_id' => $company->id, 'id' => $id]))->assertOk()->streamedContent();
            $this->assertStringContainsString('Corporate worksheet:', $csv);
            $this->assertDatabaseHas('tax_return_preparations', ['id' => $id, 'status' => 'prepared']);
            $this->getJson(route('tax-returns.preview', $selection))->assertOk()->assertJsonPath('corporate_saved.sales', '1000000');
        }
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('tax_return_preparations', 2);
    }
}
