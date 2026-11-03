<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\CompanyLedgerService;
use App\Services\Tax\BirExporterFactory;
use App\Services\Tax\BirVatLedgerMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BirXmlExporterTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function profile(): array
    {
        return ['tin' => '123456789', 'branch_code' => '00000', 'registered_name' => 'DEMO CONSULTING CORP.', 'company_name' => 'Demo Consulting', 'street' => '12 TEST STREET', 'city' => 'MAKATI CITY', 'province' => 'METRO MANILA', 'zip_code' => '1209', 'rdo' => '033', 'contact_number' => '09171234567', 'contact_email' => 'demo@example.test', 'taxpayer_size' => 'small', 'agent_category' => 'private', 'entity_type' => 'corporation', 'vat_type' => 'vat', 'full_name' => 'Demo Firm'];
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return ['taxpayer' => $this->profile(), 'period' => ['from' => '2026-07-01', 'to' => '2026-09-30', 'year' => 2026, 'quarter' => 3], 'options' => ['vat_reviewed' => true], 'prepared_at' => '2026-10-26'];
    }

    public function test_vat_export_has_the_sample_field_order_encoding_numbers_and_unrooted_framing(): void
    {
        $exporter = app(BirExporterFactory::class)->make('2550Q');
        $prepared = $exporter->prepare([...$this->context(), 'vat' => ['vatableSales' => 21000000, 'outputVatSales' => 2520000, 'domesticPurchase' => 17003559, 'domesticInputTax' => 2040427]]);
        $this->assertSame('12345678900000-2550Qv2024-122026Q3.xml', $prepared['filename']);
        $fields = $prepared['fields'];
        $this->assertCount(160, $fields);
        $this->assertSame('DEMO%20CONSULTING%20CORP.', $fields['frm2550qv2024:taxpayerName']);
        $this->assertSame('DEMO CONSULTING CORP.', $fields['frm2550qv2024:Pg2TaxPayer']);
        $this->assertSame('demo@example.test', $fields['frm2550qv2024:taxpayerEmailAddress']);
        $this->assertSame('170,035.59', $fields['frm2550qv2024:domesticPurchase']);
        $this->assertSame('20,404.27', $fields['frm2550qv2024:domesticInputTax']);
        $this->assertSame('4,795.73', $fields['frm2550qv2024:totalPayable']);
        $this->assertSame('true', $fields['frm2550qv2024:taxPayerClassification2']);
        $text = implode('', iterator_to_array($exporter->stream($prepared)));
        $this->assertStringStartsWith("<?xml version='1.0'?>        <div>frm2550qv2024:calendarNo1=truefrm2550qv2024:calendarNo1=</div>\n", $text);
        $this->assertStringContainsString('<div>frm2550qv2024:vatableSales=210,000.00frm2550qv2024:vatableSales=</div>', $text);
        $this->assertStringEndsWith("\n        All Rights Reserved BIR 2012.0", $text);
        $this->assertSame('1', $fields['txtFinalFlag']);
        $this->assertSame('Y', $fields['txtEnroll']);
        $this->assertSame('', $fields['ebirOnlineSecret']);
        $this->assertDuplicateKeyStructure($text, array_keys($fields));
    }

    public function test_withholding_matrix_matches_the_sample_offline_control_ids_and_picker_flags(): void
    {
        $exporter = app(BirExporterFactory::class)->make('1601-EQ');
        $prepared = $exporter->prepare([...$this->context(), 'withholding' => [['atc' => 'WC100', 'base' => 8734066, 'rate' => 50000, 'withheld' => 436703]], 'options' => ['remittance_month1' => 145568, 'remittance_month2' => 145568]]);
        $this->assertCount(201, $prepared['fields']);
        $this->assertSame('12345678900000-1601EQ-2026Q3.xml', $prepared['filename']);
        foreach (['txtAtcCd1' => 'WC100', 'txtTaxBase1' => '87,340.66', 'txtTaxRate1' => '5.0', 'txtTaxbeWithHeld1' => '4,367.03', 'txtTax19' => '4,367.03', 'txtTax24' => '2,911.36', 'txtTax25' => '1,455.67', 'txtTax30' => '1,455.67'] as $key => $value) {
            $this->assertSame($value, $prepared['fields']['frm1601EQ:'.$key]);
        }
        $this->assertSame('true', $prepared['fields']['AtcCode68']);
        $this->assertSame('false', $prepared['fields']['AtcCode1']);
        $text = implode('', iterator_to_array($exporter->stream($prepared)));
        $this->assertStringNotContainsString("\n", $text);
        $this->assertStringEndsWith('                        All Rights Reserved BIR 2012.0', $text);
        $this->assertDuplicateKeyStructure($text, array_keys($prepared['fields']));
    }

    public function test_mixed_rates_for_one_atc_remain_separate_and_excess_rows_are_not_truncated(): void
    {
        $exporter = app(BirExporterFactory::class)->make('1601-EQ');
        $rows = [['atc' => 'WC100', 'base' => 100000, 'rate' => 50000, 'withheld' => 5000], ['atc' => 'WC100', 'base' => 100000, 'rate' => 100000, 'withheld' => 10000]];
        $prepared = $exporter->prepare([...$this->context(), 'withholding' => $rows]);
        $this->assertSame('WC100', $prepared['fields']['frm1601EQ:txtAtcCd2']);
        $this->assertNotSame($prepared['fields']['frm1601EQ:txtTaxRate1'], $prepared['fields']['frm1601EQ:txtTaxRate2']);
        $rows = array_map(fn (int $rate): array => ['atc' => 'WC100', 'base' => 1000000, 'rate' => $rate, 'withheld' => $rate], range(10000, 70000, 10000));
        $this->expectException(ValidationException::class);
        $exporter->prepare([...$this->context(), 'withholding' => $rows]);
    }

    public function test_vat_mapper_reads_only_posted_period_sources_and_keeps_domestic_services_under_domestic_purchases(): void
    {
        $sale = ['id' => 1, 'number' => 'SI-1', 'status' => 'posted', 'date' => '2026-07-10', 'gross' => 23520000, 'tax' => '12% VAT'];
        $bill = ['id' => 1, 'number' => 'BILL-1', 'status' => 'posted', 'date' => '2026-07-10', 'gross' => 19043986, 'tax' => '12% VAT', 'account' => '7110 · Rent Expense'];
        $mapped = app(BirVatLedgerMapper::class)->map([$sale, [...$sale, 'id' => 2, 'status' => 'draft']], [$bill, [...$bill, 'id' => 2, 'status' => 'void'], [...$bill, 'id' => 3, 'tax' => 'TAX|NR|Exempt|0', 'gross' => 10000]], $this->context()['period']);
        $this->assertSame(21000000, $mapped['values']['vatableSales']);
        $this->assertSame(17003559, $mapped['values']['domesticPurchase']);
        $this->assertSame(0, $mapped['values']['servicesPurchase']);
        $this->assertSame(2040427, $mapped['values']['domesticInputTax']);
        $this->assertSame([], $mapped['issues']);
    }

    public function test_fiscal_filename_leading_zero_tins_and_negative_excess_input_are_preserved(): void
    {
        $context = $this->context();
        $context['taxpayer'] = [...$context['taxpayer'], 'tin' => '001002003', 'accounting_period' => 'fiscal', 'fiscal_end_month' => 6];
        $context['period'] = ['from' => '2025-07-01', 'to' => '2025-09-30', 'year' => 2026, 'quarter' => 1];
        $context['vat'] = ['domesticInputTax' => 10000];
        $prepared = app(BirExporterFactory::class)->make('2550Q')->prepare($context);
        $this->assertSame('00100200300000-2550Qv2024-062026Q1.xml', $prepared['filename']);
        $this->assertSame('001', $prepared['fields']['frm2550qv2024:txtTIN1']);
        $this->assertSame('-100.00', $prepared['fields']['frm2550qv2024:netVatPayable']);
    }

    public function test_invalid_profile_period_injection_and_overremittance_are_rejected(): void
    {
        $factory = app(BirExporterFactory::class);
        foreach ([['taxpayer' => [...$this->profile(), 'branch_code' => '100000']], ['taxpayer' => [...$this->profile(), 'registered_name' => 'FAKE=frm2550qv2024:taxpayerName=']], ['period' => [...$this->context()['period'], 'to' => '2026-08-31']]] as $override) {
            try {
                $factory->make('2550Q')->prepare([...$this->context(), ...$override]);
                $this->fail('Invalid context was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->expectException(ValidationException::class);
        $factory->make('1601-EQ')->prepare([...$this->context(), 'options' => ['remittance_month1' => 10000]]);
    }

    public function test_company_endpoint_exports_only_the_frozen_preparation_and_does_not_mark_it_filed(): void
    {
        $owner = User::factory()->firm()->create(['must_change_password' => false]);
        $company = Company::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => $this->profile()]);
        $state = CompanyLedgerService::emptyState();
        $state['wtpCertificates'] = [['id' => 1, 'number' => 'WTP-1', 'date' => '2026-07-01', 'status' => 'posted', 'ewtCode' => 'WC100', 'taxBase' => 8734066, 'ewt' => '5.00']];
        $ledger = CompanyLedger::factory()->create(['company_id' => $company->id, 'revision' => 1, 'state' => $state]);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->withHeader('X-Ledger-Revision', '1');
        $selection = ['form' => '1601-EQ', 'year' => 2026, 'period' => 3];
        $preview = $this->getJson(route('tax-returns.preview', ['company_id' => $company->id, ...$selection]))->assertOk()->json();
        $input = [...$selection, 'request_id' => (string) Str::uuid(), 'source_hash' => $preview['source_hash'], 'ledger_revision' => 1, 'additional_tax' => '0.00', 'credits' => '2911.36', 'surcharge' => '0.00', 'interest' => '0.00', 'compromise' => '0.00', 'reviewed' => true, 'review_note' => 'Reconciled to the quarter.', 'xml_requested' => true, 'xml_remittance_month1' => '1455.68', 'xml_remittance_month2' => '1455.68'];
        $id = $this->postJson(route('tax-returns.prepare', ['company_id' => $company->id]), $input)->assertCreated()->json('id');
        $url = route('tax-returns.xml', ['id' => $id, 'company_id' => $company->id]);
        $response = $this->get($url)->assertOk()->assertDownload('12345678900000-1601EQ-2026Q3.xml')->assertHeader('X-BIR-Validation', 'not-verified');
        $original = $response->streamedContent();
        $worksheetId = $this->postJson(route('tax-returns.prepare', ['company_id' => $company->id]), [...$input, 'request_id' => (string) Str::uuid(), 'xml_requested' => false])->assertCreated()->json('id');
        $this->getJson(route('tax-returns.xml', ['id' => $worksheetId, 'company_id' => $company->id]))->assertUnprocessable();
        $company->run(function () use ($ledger, $company): void {
            $ledger->update(['state' => CompanyLedgerService::emptyState(), 'revision' => 2]);
            CompanySetting::query()->where('company_id', $company->id)->firstOrFail()->update(['profile' => ['company_name' => 'Changed company']]);
        });
        $this->assertSame($original, $this->get($url)->assertOk()->streamedContent());
        $this->assertSame('prepared', DB::table('tax_return_preparations')->where('id', $id)->value('status'));
        $stranger = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($stranger, ['role' => 'staff', 'allowed_tabs' => json_encode(['dashboard', 'sheets'])]);
        $this->actingAs($stranger)->getJson($url)->assertForbidden();
    }

    public function test_vat_endpoint_reconciles_carryover_and_freezes_negative_excess_input(): void
    {
        $owner = User::factory()->firm()->create(['must_change_password' => false]);
        $company = Company::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => $this->profile()]);
        $state = CompanyLedgerService::emptyState();
        $state['sales'] = [['id' => 1, 'number' => 'SI-1', 'date' => '2026-07-10', 'status' => 'posted', 'gross' => 23520000, 'tax' => '12% VAT']];
        $state['purchases'] = [['id' => 1, 'number' => 'BILL-1', 'date' => '2026-07-10', 'status' => 'posted', 'gross' => 19043986, 'tax' => '12% VAT']];
        CompanyLedger::factory()->create(['company_id' => $company->id, 'revision' => 1, 'state' => $state]);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->withHeader('X-Ledger-Revision', '1');
        $selection = ['form' => '2550Q', 'year' => 2026, 'period' => 3];
        $preview = $this->getJson(route('tax-returns.preview', ['company_id' => $company->id, ...$selection]))->assertOk()->assertJsonPath('xml_vat_ledger.values.domesticInputTax', 2040427)->json();
        $input = [...$selection, 'request_id' => (string) Str::uuid(), 'source_hash' => $preview['source_hash'], 'ledger_revision' => 1, 'reviewed_tax' => '-204.27', 'additional_tax' => '0.00', 'credits' => '0.00', 'surcharge' => '4.27', 'interest' => '0.00', 'compromise' => '0.00', 'reviewed' => true, 'review_note' => 'Reviewed carryover and VAT sources.', 'xml_requested' => true, 'xml_vat_reviewed' => true, 'xml_carryover_input' => '5000.00'];
        $this->postJson(route('tax-returns.prepare', ['company_id' => $company->id]), [...$input, 'reviewed_tax' => '0.00'])->assertUnprocessable()->assertJsonValidationErrors('bir_xml.reviewed_tax');
        $id = $this->postJson(route('tax-returns.prepare', ['company_id' => $company->id]), $input)->assertCreated()->json('id');
        $response = $this->get(route('tax-returns.xml', ['company_id' => $company->id, 'id' => $id]))->assertOk()->assertDownload('12345678900000-2550Qv2024-122026Q3.xml');
        $this->assertStringContainsString('netVatPayable=-204.27frm2550qv2024:netVatPayable=', $response->streamedContent());
        $this->assertStringContainsString('totalPayable=-200.00frm2550qv2024:totalPayable=', $response->streamedContent());
        $snapshot = json_decode(DB::table('tax_return_preparations')->find($id)->snapshot, true);
        $this->assertSame(0, $snapshot['calculation']['payable']);
        $this->assertSame(20000, $snapshot['calculation']['credit_balance']);
        $this->assertDatabaseCount('tax_return_preparations', 1);
    }

    public function test_imports_and_special_rates_require_review_instead_of_guessed_domestic_vat(): void
    {
        $row = ['id' => 1, 'number' => 'BILL-1', 'date' => '2026-07-10', 'status' => 'posted', 'gross' => 11200, 'tax' => 'TAX|VAT-IMPORT|VAT|12'];
        $mapper = app(BirVatLedgerMapper::class);
        $this->assertNotEmpty($mapper->map([], [$row], $this->context()['period'])['issues']);
        $this->assertNotEmpty($mapper->map([[...$row, 'tax' => 'TAX|SPECIAL|VAT|5']], [], $this->context()['period'])['issues']);
    }

    public function test_large_values_do_not_overflow_fixed_point_percentage_arithmetic(): void
    {
        $prepared = app(BirExporterFactory::class)->make('1601EQ')->prepare([...$this->context(), 'withholding' => [['atc' => 'WC100', 'base' => 9999999999999, 'rate' => 1000000, 'withheld' => 9999999999999]]]);
        $this->assertSame('99,999,999,999.99', $prepared['fields']['frm1601EQ:txtTax19']);
    }

    public function test_corrupted_archived_values_are_rejected_before_the_first_stream_chunk(): void
    {
        $exporter = app(BirExporterFactory::class)->make('2550Q');
        $prepared = $exporter->prepare($this->context());
        $prepared['fields']['dateFiled'] = "BROKEN\r\n<div>INJECTED</div>";
        $this->expectException(\LogicException::class);
        $exporter->stream($prepared)->current();
    }

    /** @param list<string> $keys */
    private function assertDuplicateKeyStructure(string $text, array $keys): void
    {
        preg_match_all('/<div>(.*?)<\/div>/s', $text, $matches);
        $actual = [];
        foreach ($matches[1] as $row) {
            $this->assertSame(1, preg_match('/^([^=]+)=(.*)\1=$/s', $row, $fields));
            $actual[] = $fields[1];
        }
        $this->assertSame($keys, $actual);
    }
}
