<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SawtDatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Taxpayer Company', 'registered_name' => 'Taxpayer Company', 'tin' => '123456789', 'branch_code' => '00000', 'entity_type' => 'corporation']]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['form_type' => '1702Q', 'tax_kind' => 'income', 'from' => '2026-10-01', 'to' => '2026-12-31', 'taxpayer' => ['tin' => '123-456-789-00000', 'entity_type' => 'corporation', 'registered_name' => 'Taxpayer Company', 'rdo' => '033'], 'records' => [['source_id' => 'certificate-1', 'date' => '2026-10-20', 'status' => 'posted', 'tin' => '234-567-890-01234', 'entity_type' => 'corporation', 'registered_name' => 'Withholding Agent Inc.', 'nature_income' => 'Services', 'atc' => 'WC157', 'tax_rate' => '2.00', 'income_payment' => '446.43', 'amount_withheld' => '8.93']]];
    }

    public function test_annual_forms_preserve_certificates_and_use_alphalist_sample_layout(): void
    {
        foreach (['1702', '1701'] as $form) {
            $payload = $this->payload();
            $payload['form_type'] = $form;
            $payload['from'] = '2026-01-01';
            if ($form === '1701') {
                $payload['taxpayer'] = [...$payload['taxpayer'], 'entity_type' => 'individual', 'last_name' => 'Dela Cruz', 'first_name' => 'Maria'];
            }
            $payload['records'][] = [...$payload['records'][0], 'source_id' => 'january', 'date' => '2026-01-01'];
            $payload['records'][] = [...$payload['records'][0], 'source_id' => 'december', 'date' => '2026-12-31', 'entity_type' => 'individual', 'last_name' => 'Santos', 'first_name' => 'Juan'];
            $response = $this->postJson('/reports/generate-sawt-dat', $payload)->assertOk()->assertDownload('1234567890000122026'.$form.'.DAT')->assertHeader('X-SAWT-Detail-Count', '3');
            $text = $response->streamedContent();
            $lines = explode("\r\n", rtrim($text, "\r\n"));
            $this->assertCount(5, $lines);

            $header = str_getcsv($lines[0], ',', '"', '');
            $this->assertCount(10, $header);
            $this->assertSame(['HSAWT', 'H'.$form, '123456789', '0000'], array_slice($header, 0, 4));
            $this->assertSame(['12/2026', '033'], array_slice($header, 8));
            $this->assertSame('DSAWT,D'.$form.',1,234567890,1234,"WITHHOLDING AGENT INC.",,,,12/2026,"SERVICES",WC157,2.00,446.43,8.93', $lines[1]);
            $this->assertSame('DSAWT,D'.$form.',3,234567890,1234,,"SANTOS","JUAN","",12/2026,"SERVICES",WC157,2.00,446.43,8.93', $lines[3]);
            foreach (array_slice($lines, 1, 3) as $line) {
                $this->assertCount(15, str_getcsv($line, ',', '"', ''));
                $this->assertFalse(str_ends_with($line, ','));
            }
            $this->assertStringEndsWith("\r\n", $text);
            $this->assertSame('CSAWT,C'.$form.',123456789,0000,12/2026,1339.29,26.79', $lines[4]);
        }
    }

    public function test_annual_download_matches_uploaded_alphalist_structure_with_alphanumeric_rdo(): void
    {
        $payload = $this->payload();
        $payload['form_type'] = '1702';
        $payload['from'] = '2026-01-01';
        $payload['taxpayer']['rdo'] = '53B';
        $payload['records'][0] = [...$payload['records'][0], 'tin' => '12345678900000', 'registered_name' => 'Various Customers', 'nature_income' => ''];
        $expected = 'HSAWT,H1702,123456789,0000,"TAXPAYER COMPANY","","","",12/2026,53B'."\r\n"
            .'DSAWT,D1702,1,123456789,0000,"VARIOUS CUSTOMERS",,,,12/2026,,WC157,2.00,446.43,8.93'."\r\n"
            .'CSAWT,C1702,123456789,0000,12/2026,446.43,8.93'."\r\n";
        $this->assertSame($expected, $this->postJson('/reports/generate-sawt-dat', $payload)->assertOk()->assertDownload('12345678900001220261702.DAT')->streamedContent());
    }

    public function test_annual_export_rejects_partial_year_and_wrong_taxpayer_type(): void
    {
        $payload = $this->payload();
        $payload['form_type'] = '1702';
        $this->postJson('/reports/generate-sawt-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors('from');
        $payload['from'] = '2026-01-01';
        $payload['form_type'] = '1701';
        $this->postJson('/reports/generate-sawt-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors('taxpayer.entity_type');
    }

    public function test_annual_export_uses_company_fiscal_year_end(): void
    {
        $company = session('company_setup');
        $this->withSession(['company_setup' => [...$company, 'accounting_period' => 'fiscal', 'fiscal_end_month' => 6]]);
        $payload = $this->payload();
        $payload['form_type'] = '1702';
        $payload['from'] = '2025-07-01';
        $payload['to'] = '2026-06-30';
        $payload['records'][0]['date'] = '2025-07-01';
        $text = $this->postJson('/reports/generate-sawt-dat', $payload)->assertOk()->assertDownload('12345678900000620261702.DAT')->streamedContent();
        $this->assertStringStartsWith('HSAWT,H1702,123456789,0000,', $text);
        $this->assertStringContainsString('06/2026,033', $text);
        $payload['from'] = '2026-01-01';
        $payload['to'] = '2026-12-31';
        $payload['records'][0]['date'] = '2026-07-01';
        $this->postJson('/reports/generate-sawt-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_download_stream_has_exact_header_detail_control_order_and_decimal_totals(): void
    {
        $response = $this->postJson('/reports/generate-sawt-dat', $this->payload())->assertOk()->assertDownload('12345678900001220261702Q.DAT')->assertHeader('Content-Type', 'text/plain; charset=us-ascii');
        $text = $response->streamedContent();
        $lines = explode("\r\n", rtrim($text, "\r\n"));
        $this->assertCount(3, $lines);
        $this->assertSame('HSAWT,H1702Q,123456789,0000,"TAXPAYER COMPANY","","","",12/2026,033', $lines[0]);
        $this->assertSame('DSAWT,D1702Q,1,234567890,1234,"WITHHOLDING AGENT INC.","","","",12/2026,"SERVICES",WC157,2.00,446.43,8.93', $lines[1]);
        $this->assertSame('CSAWT,C1702Q,123456789,0000,12/2026,446.43,8.93', $lines[2]);
        foreach ([10, 15, 7] as $index => $count) {
            $this->assertCount($count, str_getcsv($lines[$index], ',', '"', ''));
        }
        $this->assertStringEndsWith("\r\n", $text);
        $this->assertStringNotContainsString("\xef\xbb\xbf", $text);
    }

    public function test_alphalist_sample_layout_preserves_unquoted_codes_and_blank_individual_entity_slot(): void
    {
        $payload = $this->payload();
        $payload['to'] = '2026-10-31';
        $payload['taxpayer']['rdo'] = '088';
        $payload['records'][0] = [...$payload['records'][0], 'tin' => '001-002-003-00000', 'entity_type' => 'individual', 'last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => 'Santos', 'nature_income' => '', 'income_payment' => '3.93', 'amount_withheld' => '0.08'];
        $expected = 'HSAWT,H1702Q,123456789,0000,"TAXPAYER COMPANY","","","",10/2026,088'."\r\n"
            .'DSAWT,D1702Q,1,001002003,0000,,"DELA CRUZ","JUAN","SANTOS",10/2026,,WC157,2.00,3.93,0.08'."\r\n"
            .'CSAWT,C1702Q,123456789,0000,10/2026,3.93,0.08'."\r\n";
        $this->assertSame($expected, $this->postJson('/reports/generate-sawt-dat', $payload)->assertOk()->assertDownload('12345678900001020261702Q.DAT')->streamedContent());
        unset($payload['records'][0]['nature_income']);
        $this->assertSame($expected, $this->postJson('/reports/generate-sawt-dat', $payload)->assertOk()->streamedContent());
    }

    public function test_matching_certificates_group_with_integer_centavo_totals_and_distinct_agents_get_sequences(): void
    {
        $payload = $this->payload();
        $payload['records'][] = [...$payload['records'][0], 'source_id' => 'certificate-2', 'date' => '2026-11-20', 'income_payment' => '100.00', 'amount_withheld' => '2.00'];
        $response = $this->postJson('/reports/generate-sawt-dat', $payload)->assertOk()->assertHeader('X-SAWT-Detail-Count', '1');
        $this->assertStringContainsString('2.00,546.43,10.93', $response->streamedContent());
        $payload['records'][1]['tin'] = '345-678-901-00001';
        $text = $this->postJson('/reports/generate-sawt-dat', $payload)->assertOk()->assertHeader('X-SAWT-Detail-Count', '2')->streamedContent();
        $this->assertStringContainsString('DSAWT,D1702Q,2,345678901,0001', $text);
        $this->assertStringContainsString('CSAWT,C1702Q,123456789,0000,12/2026,546.43,10.93', $text);
    }

    public function test_individual_name_slots_and_csv_quotes_preserve_field_alignment(): void
    {
        $payload = $this->payload();
        $payload['taxpayer'] = [...$payload['taxpayer'], 'entity_type' => 'individual', 'last_name' => 'Dela Cruz', 'first_name' => 'Maria', 'middle_name' => 'Santos'];
        $payload['form_type'] = '1701Q';
        $payload['records'][0]['registered_name'] = 'Agent, "Quoted" Inc.';
        $text = $this->postJson('/reports/generate-sawt-dat', $payload)->assertOk()->streamedContent();
        $lines = explode("\r\n", trim($text));
        $header = str_getcsv($lines[0], ',', '"', '');
        $this->assertSame(['', 'DELA CRUZ', 'MARIA', 'SANTOS'], array_slice($header, 4, 4));
        $this->assertSame('AGENT, "QUOTED" INC.', str_getcsv($lines[1], ',', '"', '')[5]);
    }

    public function test_validation_stops_bad_branches_amounts_duplicates_unknown_atc_and_wrong_period_before_streaming(): void
    {
        foreach ([['tin', '23456789010000'], ['tin', '000-000-000-00000'], ['tax_rate', '5.00'], ['income_payment', '1.001'], ['registered_name', str_repeat('A', 51)], ['atc', 'WC999'], ['date', '2027-01-01'], ['status', 'draft']] as [$field, $value]) {
            $payload = $this->payload();
            $payload['records'][0][$field] = $value;
            $this->postJson('/reports/generate-sawt-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors('records.0.'.$field)->assertHeader('Content-Type', 'application/json');
        }
        $payload = $this->payload();
        $payload['records'][] = $payload['records'][0];
        $this->postJson('/reports/generate-sawt-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors('records.0.source_id');
        $payload['records'] = [];
        $this->postJson('/reports/generate-sawt-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors('records');
    }

    public function test_vat_schedule_streams_saved_vat_certificate_amounts_for_the_selected_form(): void
    {
        $payload = $this->payload();
        $payload['form_type'] = '2550Q';
        $payload['tax_kind'] = 'vat';
        $payload['records'][0] = [...$payload['records'][0], 'atc' => 'WV020', 'tax_rate' => '5.00', 'amount_withheld' => '22.32'];
        $text = $this->postJson('/reports/generate-sawt-dat', $payload)->assertOk()->streamedContent();
        $this->assertStringContainsString('HSAWT,H2550Q', $text);
        $this->assertStringContainsString('WV020,5.00,446.43,22.32', $text);
    }

    public function test_totals_overflow_is_rejected_without_a_partial_download(): void
    {
        $payload = $this->payload();
        $payload['records'][0] = [...$payload['records'][0], 'tax_rate' => '1.00', 'income_payment' => '60000000000.00', 'amount_withheld' => '600000000.00'];
        $payload['records'][] = [...$payload['records'][0], 'source_id' => 'certificate-2'];
        $this->postJson('/reports/generate-sawt-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors('records');
    }

    public function test_workspace_identity_tax_type_and_csrf_are_enforced(): void
    {
        $payload = $this->payload();
        $payload['taxpayer']['tin'] = '34567890100000';
        $this->postJson('/reports/generate-sawt-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors('taxpayer.tin');
        $payload = $this->payload();
        $payload['tax_kind'] = 'vat';
        $this->postJson('/reports/generate-sawt-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors('form_type');
        $this->withSession(['preview_access' => false])->postJson('/reports/generate-sawt-dat', $this->payload())->assertForbidden();
        $this->withSession(['preview_access' => true]);
        $this->app->instance('env', 'production');
        $this->postJson('/reports/generate-sawt-dat', $this->payload())->assertStatus(419);
    }
}
