<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\TaxCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class Form2307Test extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function company(): array
    {
        return ['company_name' => 'Sample Payor', 'registered_name' => 'Sample Payor Incorporated', 'full_name' => 'Demo User', 'tin' => '123456789', 'branch_code' => '00000', 'street' => 'First Street', 'city' => 'Sample City', 'province' => 'Sample Province', 'zip_code' => '1000', 'signatory_name' => 'Default Officer', 'signatory_title' => 'Finance Manager', 'signatory_tin' => '123-456-789'];
    }

    private function supplier(): Party
    {
        $this->withSession(['preview_access' => true, 'company_setup' => $this->company()]);

        return Party::factory()->create(['workspace_key' => ChartAccount::workspaceKey($this->company()), 'name' => 'Sample Supplier', 'classification' => 'Supplier', 'tin' => '234-567-890-00000', 'address' => 'Second Street, Sample City', 'zip_code' => '1000']);
    }

    /** @return array<string, mixed> */
    private function payload(Party $supplier): array
    {
        return ['use_default_signatory' => true, 'records' => [['source_id' => 'posted-wtp-1', 'supplier_id' => $supplier->id, 'status' => 'posted', 'transaction_date' => '2026-10-15', 'atc_code' => 'WC157', 'income_payment' => '100.01', 'tax_withheld' => '2.00']]];
    }

    public function test_quarter_groups_atc_month_buckets_and_preserves_actual_withheld_values(): void
    {
        $supplier = $this->supplier();
        $data = $this->payload($supplier);
        $row = $data['records'][0];
        $data['records'][] = [...$row, 'source_id' => 'posted-wtp-2', 'transaction_date' => '2026-11-30', 'income_payment' => '200.02', 'tax_withheld' => '4.01'];
        $data['records'][] = [...$row, 'source_id' => 'posted-wtp-3', 'transaction_date' => '2026-12-31', 'income_payment' => '300.03', 'tax_withheld' => '6.00'];
        $data['records'][] = [...$row, 'source_id' => 'posted-wtp-4', 'atc_code' => 'WC160', 'income_payment' => '40.00', 'tax_withheld' => '0.80'];
        Pdf::shouldReceive('loadView')->once()->with('form2307', Mockery::on(function (array $view): bool {
            $this->assertSame('2026-10-01', $view['startDate']->format('Y-m-d'));
            $this->assertSame('2026-12-31', $view['endDate']->format('Y-m-d'));
            $this->assertSame([10001, 20002, 30003], $view['pages'][0][0]['months']);
            $this->assertSame(60006, $view['pages'][0][0]['total']);
            $this->assertSame(1201, $view['pages'][0][0]['withheld']);
            $this->assertSame('WC160', $view['pages'][0][1]['code']);
            $this->assertSame('Default Officer', $view['signatory']['name']);
            $this->assertSame('Sample Supplier', $view['payee']['name']);

            return true;
        }))->andReturnSelf();
        Pdf::shouldReceive('setPaper')->once()->with([0, 0, 612, 936])->andReturnSelf();
        Pdf::shouldReceive('setOptions')->once()->andReturnSelf();
        Pdf::shouldReceive('download')->once()->with('BIR-2307-23456789000000-Q4-2026.pdf')->andReturn(response('PDF'));
        $this->postJson('/reports/form-2307/'.$supplier->id.'?quarter=4&year=2026', $data)->assertOk();
    }

    public function test_real_dompdf_download_is_a_single_8_5_by_13_page_pdf(): void
    {
        $supplier = $this->supplier();
        $response = $this->postJson('/reports/form-2307/'.$supplier->id.'?quarter=4&year=2026', $this->payload($supplier))->assertOk()->assertDownload('BIR-2307-23456789000000-Q4-2026.pdf')->assertHeader('Content-Type', 'application/pdf');
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(1, preg_match_all('~/Type /Page\b~', $pdf));
        $this->assertMatchesRegularExpression('~/MediaBox \[0(?:\.0+)? 0(?:\.0+)? 612(?:\.0+)? 936(?:\.0+)?\]~', $pdf);
    }

    public function test_preview_returns_the_same_pdf_with_matching_embeddable_form_details(): void
    {
        $supplier = $this->supplier();
        $response = $this->postJson('/reports/form-2307/'.$supplier->id.'?quarter=4&year=2026', [...$this->payload($supplier), 'preview' => true])->assertOk()->assertJsonPath('filename', 'BIR-2307-23456789000000-Q4-2026.pdf');
        $this->assertStringStartsWith('%PDF-', base64_decode($response->json('pdf'), true));
        $this->assertTrue(str_contains($response->json('html'), 'SAMPLE SUPPLIER'));
        $this->assertTrue(str_contains($response->json('html'), 'DEFAULT OFFICER'));
        $this->assertStringContainsString('100.01', $response->json('html'));
        $this->assertStringContainsString('WC157', $response->json('html'));
        $this->assertStringContainsString('window.addEventListener', $response->json('html'));
    }

    public function test_more_than_ten_atcs_print_on_additional_forms_without_omitting_rows(): void
    {
        $supplier = $this->supplier();
        $data = $this->payload($supplier);
        $row = $data['records'][0];
        $codes = collect(TaxCode::atcCatalog(ChartAccount::workspaceKey($this->company())))->pluck('code')->filter(fn (string $code): bool => (bool) preg_match('/^W[IC]/', $code))->take(11)->values();
        $data['records'] = $codes->map(fn (string $code, int $index): array => [...$row, 'source_id' => 'posted-'.$index, 'atc_code' => $code])->all();
        $pdf = $this->postJson('/reports/form-2307/'.$supplier->id.'?quarter=4&year=2026', $data)->assertOk()->getContent();
        $this->assertSame(2, preg_match_all('~/Type /Page\b~', $pdf));
    }

    public function test_invalid_period_draft_void_duplicate_and_foreign_supplier_rows_are_rejected(): void
    {
        $supplier = $this->supplier();
        foreach ([['transaction_date', '2027-01-01'], ['status', 'draft'], ['status', 'void'], ['supplier_id', $supplier->id + 1], ['atc_code', 'WC999'], ['income_payment', '1.001'], ['tax_withheld', '200.00']] as [$key, $value]) {
            $data = $this->payload($supplier);
            $data['records'][0][$key] = $value;
            $this->postJson('/reports/form-2307/'.$supplier->id.'?quarter=4&year=2026', $data)->assertUnprocessable()->assertJsonValidationErrors('records.0.'.$key);
        }
        $data = $this->payload($supplier);
        $data['records'][] = $data['records'][0];
        $this->postJson('/reports/form-2307/'.$supplier->id.'?quarter=4&year=2026', $data)->assertUnprocessable()->assertJsonValidationErrors('records.0.source_id');
        $this->postJson('/reports/form-2307/'.$supplier->id.'?quarter=5&year=2026', $data)->assertUnprocessable()->assertJsonValidationErrors('quarter');
        $foreign = Party::factory()->create(['classification' => 'Supplier']);
        $this->postJson('/reports/form-2307/'.$foreign->id.'?quarter=4&year=2026', $this->payload($supplier))->assertNotFound();
        $this->withSession(['preview_access' => false])->postJson('/reports/form-2307/'.$supplier->id.'?quarter=4&year=2026', $this->payload($supplier))->assertForbidden();
    }

    public function test_default_signatory_comes_from_saved_settings_and_custom_name_is_download_only(): void
    {
        $supplier = $this->supplier();
        CompanySetting::query()->create(['workspace_key' => ChartAccount::workspaceKey($this->company()), 'lookup_key' => ChartAccount::workspaceKey($this->company()), 'profile' => [...$this->company(), 'signatory_name' => 'Updated Officer'], 'preferences' => []]);
        foreach ([true => 'Updated Officer', false => 'Custom Officer'] as $default => $name) {
            Pdf::shouldReceive('loadView')->once()->with('form2307', Mockery::on(fn (array $view): bool => $view['signatory']['name'] === $name))->andReturnSelf();
            Pdf::shouldReceive('setPaper')->once()->andReturnSelf();
            Pdf::shouldReceive('setOptions')->once()->andReturnSelf();
            Pdf::shouldReceive('download')->once()->andReturn(response('PDF'));
            $this->postJson('/reports/form-2307/'.$supplier->id.'?quarter=4&year=2026', [...$this->payload($supplier), 'use_default_signatory' => (bool) $default, 'signatory_name' => 'Custom Officer', 'signatory_title' => 'Director'])->assertOk();
        }
        $this->assertSame('Updated Officer', CompanySetting::query()->first()->profile['signatory_name']);
    }

    public function test_missing_default_signatory_has_a_useful_validation_message(): void
    {
        $supplier = $this->supplier();
        $this->withSession(['company_setup' => [...$this->company(), 'signatory_name' => '']]);
        $this->postJson('/reports/form-2307/'.$supplier->id.'?quarter=4&year=2026', $this->payload($supplier))->assertUnprocessable()->assertJsonValidationErrors('signatory_name');
        $this->get('/sheets/wtp')->assertOk()->assertSee('Use default certificate signatory')->assertSee('Print Form 2307 PDF')->assertSee('form2307.js')->assertSee('BIR Form 2307 print preview')->assertSee('Update preview')->assertSee('form2307-preview-frame');
    }
}
