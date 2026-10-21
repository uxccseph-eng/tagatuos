<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

class QapDatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'QAP Test', 'tin' => '123456789', 'branch_code' => '00001']]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['quarter' => 4, 'year' => 2026, 'records' => [['source_id' => 'wtp-1', 'date' => '2026-10-01', 'status' => 'posted', 'tin' => '234-567-890-01234', 'entity_type' => 'corporation', 'registered_name' => 'Supplier, "Quoted" Inc.', 'atc' => 'WC157', 'tax_rate' => '2.00', 'income_payment' => '446.43', 'amount_withheld' => '8.93']]];
    }

    /** @return array<string, string> */
    private function archiveFiles(TestResponse $response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'qap-test-');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path, ZipArchive::CHECKCONS));
            $files = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $files[$zip->getNameIndex($index)] = $zip->getFromIndex($index);
            }

            return $files;
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_qap_download_has_exact_schema_quotes_crlf_and_filename(): void
    {
        $response = $this->postJson('/reports/generate-qap-dat', $this->payload())->assertOk()->assertDownload('QAP-EWT-Q4-2026.zip')->assertHeader('Content-Type', 'application/zip')->assertHeader('X-QAP-Detail-Count', '1')->assertHeader('X-QAP-File-Count', '1');
        $expected = 'H,1601EQ,"123456789","00001",10/2026'."\r\n"
            .'D,1601EQ,"123456789","00001","234567890","01234","SUPPLIER, ""QUOTED"" INC.","","","","WC157",446.43,2.00,8.93,"10/2026"'."\r\n";
        $files = $this->archiveFiles($response);
        $this->assertSame(['123456789000011601EQ102026.DAT'], array_keys($files));
        $this->assertSame($expected, $files['123456789000011601EQ102026.DAT']);
        $this->assertArrayNotHasKey('123456789000011601EQ112026.DAT', $files);
        $this->assertArrayNotHasKey('123456789000011601EQ122026.DAT', $files);
        foreach ([5, 15] as $index => $count) {
            $this->assertCount($count, str_getcsv(explode("\r\n", $expected)[$index], ',', '"', ''));
        }
    }

    public function test_grouping_preserves_atcs_and_individual_names_with_centavo_totals(): void
    {
        $payload = $this->payload();
        $payload['records'][] = [...$payload['records'][0], 'source_id' => 'wtp-2', 'date' => '2026-12-31'];
        $payload['records'][] = [...$payload['records'][0], 'source_id' => 'wtp-3', 'entity_type' => 'individual', 'tin' => '34567890100000', 'last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => 'Santos'];
        $payload['records'][] = [...$payload['records'][0], 'source_id' => 'same-month', 'date' => '2026-10-31', 'income_payment' => '100.00', 'amount_withheld' => '2.00'];
        $files = $this->archiveFiles($this->postJson('/reports/generate-qap-dat', $payload)->assertOk()->assertHeader('X-QAP-Detail-Count', '3'));
        $this->assertCount(2, $files);
        $this->assertArrayNotHasKey('123456789000011601EQ112026.DAT', $files);
        $october = $files['123456789000011601EQ102026.DAT'];
        $december = $files['123456789000011601EQ122026.DAT'];
        $this->assertStringContainsString('546.43,2.00,10.93', $october);
        $this->assertStringContainsString('"345678901","00000","","DELA CRUZ","JUAN","SANTOS"', $october);
        $this->assertStringContainsString('446.43,2.00,8.93', $december);
        $this->assertStringNotContainsString('345678901', $december);
        $this->assertStringNotContainsString('546.43', $december);
    }

    public function test_all_quarter_ends_and_boundaries_are_resolved_on_server(): void
    {
        foreach ([1 => ['2026-01-01', '03'], 2 => ['2026-04-01', '06'], 3 => ['2026-07-01', '09'], 4 => ['2026-10-01', '12']] as $quarter => [$date, $month]) {
            $payload = $this->payload();
            $payload['quarter'] = $quarter;
            $payload['records'][0]['date'] = $date;
            $files = $this->archiveFiles($this->postJson('/reports/generate-qap-dat', $payload)->assertOk()->assertDownload('QAP-EWT-Q'.$quarter.'-2026.zip'));
            $this->assertCount(1, $files);
            foreach ($files as $name => $content) {
                $this->assertStringEndsWith("\r\n", $content);
                $this->assertStringNotContainsString("\n", str_replace("\r\n", '', $content));
                $lines = explode("\r\n", rtrim($content, "\r\n"));
                $header = str_getcsv($lines[0], ',', '"', '');
                $this->assertStringEndsWith(str_replace('/', '', $header[4]).'.DAT', $name);
                foreach (array_slice($lines, 1) as $line) {
                    $this->assertSame($header[4], str_getcsv($line, ',', '"', '')[14]);
                }
            }
        }
        $payload['records'][0]['date'] = '2027-01-01';
        $this->postJson('/reports/generate-qap-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors('records.0.date');
    }

    public function test_annual_1604e_consolidates_all_four_quarters_and_streams_requested_layout(): void
    {
        $payload = $this->payload();
        unset($payload['quarter']);
        $record = [...$payload['records'][0], 'payee_id' => 12];
        $payload['records'] = [];
        foreach (['2026-01-01', '2026-04-01', '2026-07-01', '2026-12-31'] as $index => $date) {
            $payload['records'][] = [...$record, 'source_id' => 'quarter-'.$index, 'date' => $date];
        }
        $response = $this->postJson('/reports/generate-1604e-dat', $payload)->assertOk()->assertDownload('123456789000011604E122026.DAT')->assertHeader('Content-Type', 'text/plain; charset=utf-8')->assertHeader('X-QAP-Detail-Count', '1');
        $expected = 'H,1604E,"123456789","00001",12/2026'."\r\n"
            .'D,1604E,"123456789","00001","234567890","01234","SUPPLIER, ""QUOTED"" INC.","","","","WC157",1785.72,2.00,35.72,"12/2026"'."\r\n";
        $this->assertSame($expected, $response->streamedContent());
    }

    public function test_annual_grouping_separates_payees_and_atcs_and_preserves_individual_names(): void
    {
        $payload = $this->payload();
        unset($payload['quarter']);
        $payload['records'][0]['payee_id'] = 1;
        $payload['records'][] = [...$payload['records'][0], 'source_id' => 'other-atc', 'atc' => 'WC160'];
        $payload['records'][] = [...$payload['records'][0], 'source_id' => 'individual', 'payee_id' => 2, 'tin' => '34567890100000', 'entity_type' => 'individual', 'last_name' => 'Cruz', 'first_name' => 'Juan', 'middle_name' => 'Santos'];
        $text = $this->postJson('/reports/generate-1604e-dat', $payload)->assertOk()->assertHeader('X-QAP-Detail-Count', '3')->streamedContent();
        $lines = explode("\r\n", rtrim($text, "\r\n"));
        $this->assertCount(4, $lines);
        foreach (array_slice($lines, 1) as $line) {
            $fields = str_getcsv($line, ',', '"', '');
            $this->assertCount(15, $fields);
            $this->assertSame('1604E', $fields[1]);
            $this->assertSame('12/2026', $fields[14]);
        }
        $this->assertStringContainsString('"345678901","00000","","CRUZ","JUAN","SANTOS"', $text);
    }

    public function test_annual_export_rejects_out_of_year_and_unposted_records_before_download(): void
    {
        foreach ([['date', '2025-12-31'], ['date', '2027-01-01'], ['status', 'void'], ['atc', 'WC999']] as [$field, $value]) {
            $payload = $this->payload();
            unset($payload['quarter']);
            $payload['records'][0][$field] = $value;
            $this->postJson('/reports/generate-1604e-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors('records.0.'.$field);
        }
        $payload = $this->payload();
        $payload['records'][] = [...$payload['records'][0], 'source_id' => 'conflict', 'date' => '2026-01-01', 'tax_rate' => '1.00', 'amount_withheld' => '4.46'];
        $this->postJson('/reports/generate-1604e-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors('records.1.atc');
        $this->withSession(['preview_access' => false])->postJson('/reports/generate-1604e-dat', $this->payload())->assertForbidden();
    }

    public function test_export_rejects_invalid_sources_rates_identities_and_conflicts_before_streaming(): void
    {
        foreach ([['status', 'draft'], ['atc', 'WC999'], ['tin', '00000000000000'], ['amount_withheld', '99.00'], ['income_payment', '1.001'], ['registered_name', '   ']] as [$field, $value]) {
            $payload = $this->payload();
            $payload['records'][0][$field] = $value;
            $this->postJson('/reports/generate-qap-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors('records.0.'.$field);
        }
        $payload = $this->payload();
        $payload['records'][] = [...$payload['records'][0], 'source_id' => 'wtp-2', 'tax_rate' => '1.00', 'amount_withheld' => '4.46'];
        $this->postJson('/reports/generate-qap-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors('records.1.atc');
        $payload['records'][1] = $payload['records'][0];
        $this->postJson('/reports/generate-qap-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors('records.0.source_id');
        $payload['records'] = [];
        $this->postJson('/reports/generate-qap-dat', $payload)->assertUnprocessable()->assertJsonValidationErrors('records');
        $this->withSession(['preview_access' => false])->postJson('/reports/generate-qap-dat', $this->payload())->assertForbidden();
    }
}
