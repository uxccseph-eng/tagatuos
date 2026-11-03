<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\User;
use App\Services\CompanyLedgerService;
use App\Services\Tax\SawtFileGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SawtFileGeneratorTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return ['tin' => '123456789', 'branch_code' => '00001', 'company_name' => 'Peña & Company, Inc.|', 'entity_type' => 'corporation', 'rdo' => '033', ...$this->selection()];
    }

    /** @return array<string, string> */
    private function selection(): array
    {
        return ['from' => '2026-10-01', 'to' => '2026-12-31', 'form_type' => '1702Q', 'tax_kind' => 'income'];
    }

    /** @return array<string, mixed> */
    private function certificate(): array
    {
        return ['id' => 1, 'payor_tin' => '001002003', 'payor_branch_code' => '00000', 'payor_name' => "Payor, Inc.|\r\nCompany", 'atc_code' => 'WC157', 'income_payment' => '446.43', 'tax_rate' => '2.00', 'tax_withheld' => '8.93'];
    }

    public function test_array_contract_streams_exact_ascii_crlf_layout_and_preserves_zeroes(): void
    {
        $service = app(SawtFileGeneratorService::class);
        $prepared = $service->prepare([$this->certificate()], $this->settings());
        $text = implode('', iterator_to_array($service->lines($prepared)));
        $this->assertSame('12345678900011220261702Q.DAT', $prepared['filename']);
        $this->assertSame('HSAWT,H1702Q,123456789,0001,"PENA AND COMPANY INC","","","",12/2026,033'."\r\n"
            .'DSAWT,D1702Q,1,001002003,0000,"PAYOR INC COMPANY","","","",12/2026,,WC157,2.00,446.43,8.93'."\r\n"
            .'CSAWT,C1702Q,123456789,0001,12/2026,446.43,8.93'."\r\n", $text);
        $this->assertDoesNotMatchRegularExpression('/[^\x00-\x7F]/', $text);
        $this->assertStringNotContainsString("\n", str_replace("\r\n", '', $text));
    }

    public function test_model_collections_group_payors_and_handle_individual_name_components(): void
    {
        $row = new Party;
        $row->forceFill([...$this->certificate(), 'payor_entity_type' => 'individual', 'payor_last_name' => 'Dela Cruz', 'payor_first_name' => 'Juan', 'payor_middle_name' => 'Peña']);
        $second = clone $row;
        $second->id = 2;
        $service = app(SawtFileGeneratorService::class);
        $prepared = $service->prepare(collect([$row, $second]), $this->settings());
        $this->assertSame(1, $prepared['count']);
        $text = implode('', iterator_to_array($service->lines($prepared)));
        $this->assertStringContainsString(',,"DELA CRUZ","JUAN","PENA",12/2026,,WC157,2.00,892.86,17.86', $text);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, string}> */
    public static function invalidInputs(): iterable
    {
        yield 'missing payor branch' => [['payor_branch_code' => null], [], 'records.0.tin'];
        yield 'branch overflow' => [['payor_branch_code' => '10000'], [], 'records.0.tin'];
        yield 'conflicting embedded branch' => [['payor_tin' => '0010020030001'], [], 'records.0.tin'];
        yield 'invalid TIN' => [['payor_tin' => '000000000'], [], 'records.0.tin'];
        yield 'individual components missing' => [['payor_entity_type' => 'individual'], [], 'records.0.last_name'];
        yield 'wrong tax type' => [[], ['tax_kind' => 'vat'], 'form_type'];
        yield 'mismatched tax' => [['tax_withheld' => '99.00'], [], 'records.0.tax_rate'];
        yield 'scientific notation' => [['income_payment' => '4.4643e2'], [], 'records.0.income_payment'];
        yield 'partial annual period' => [[], ['form_type' => '1702'], 'from'];
        yield 'injected numeric field' => [['tax_withheld' => "8.93\r\nOTHER"], [], 'records.0.amount_withheld'];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_is_rejected_before_streaming(array $row, array $settings, string $field): void
    {
        try {
            app(SawtFileGeneratorService::class)->prepare([[...$this->certificate(), ...$row]], [...$this->settings(), ...$settings]);
            $this->fail('Invalid export was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_drafts_voids_and_outside_period_are_excluded_and_duplicates_rejected(): void
    {
        $service = app(SawtFileGeneratorService::class);
        $record = $this->certificate();
        $prepared = $service->prepare([$record, [...$record, 'status' => 'draft'], [...$record, 'status' => 'void'], [...$record, 'date' => '2026-01-01']], $this->settings());
        $this->assertSame(1, $prepared['count']);
        $this->expectException(ValidationException::class);
        $service->prepare([$record, $record], $this->settings());
    }

    /** @return array{Company, User, CompanyLedger} */
    private function book(): array
    {
        $company = Company::factory()->create();
        $owner = User::factory()->firm()->create(['must_change_password' => false]);
        $company->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => [...$this->settings(), 'full_name' => $owner->name]]);
        Party::factory()->create(['workspace_key' => $company->workspaceKey(), 'classification' => 'Customer', 'name' => 'Payor Inc', 'tin' => '001-002-003-00000', 'entity_type' => 'corporation']);
        $record = ['id' => 1, 'number' => 'CWT-000001', 'customer' => 'Payor Inc', 'date' => '2026-10-20', 'status' => 'posted', 'atc' => 'WC157 · 2%', 'taxBase' => 44643, 'amount' => 893];
        $state = [...CompanyLedgerService::emptyState(), 'cwtCertificates' => [$record, [...$record, 'id' => 2, 'status' => 'draft'], [...$record, 'id' => 3, 'status' => 'void'], [...$record, 'id' => 4, 'date' => '2027-01-01']]];
        $ledger = CompanyLedger::factory()->create(['company_id' => $company->id, 'revision' => 3, 'state' => $state]);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->withHeader('X-Ledger-Revision', '3');

        return [$company, $owner, $ledger];
    }

    public function test_endpoint_reads_saved_certificates_and_masters_without_mutating_books(): void
    {
        [$company, , $ledger] = $this->book();
        $before = $ledger->getRawOriginal('state');
        $this->get(route('tax-reports.show', ['schedule' => 'sawt', 'company_id' => $company->id]))->assertOk()->assertSee('DAT from saved 2307')->assertSee(route('reports.sawt.saved', ['company_id' => $company->id]));
        $response = $this->postJson(route('reports.sawt.saved', ['company_id' => $company->id]), $this->selection());
        $response->assertOk()->assertDownload('12345678900011220261702Q.DAT')->assertHeader('X-SAWT-Detail-Count', '1')->assertHeader('Content-Type', 'text/plain; charset=us-ascii');
        $this->assertStringContainsString('WC157,2.00,446.43,8.93', $response->streamedContent());
        $this->assertSame($before, $company->run(fn (): string => $ledger->fresh()->getRawOriginal('state')));
        $this->assertDatabaseHas('tax_preflight_reviews', ['company_id' => $company->id, 'action' => 'export_generated', 'filename' => '12345678900011220261702Q.DAT', 'file_hash' => hash('sha256', $response->streamedContent())]);
    }

    public function test_endpoint_rejects_spoofed_payload_and_stale_revision(): void
    {
        [$company] = $this->book();
        $url = route('reports.sawt.saved', ['company_id' => $company->id]);
        $this->postJson($url, [...$this->selection(), 'records' => [$this->certificate()], 'taxpayer' => $this->settings()])->assertUnprocessable()->assertJsonValidationErrors(['records', 'taxpayer']);
        $this->withHeader('X-Ledger-Revision', '2')->postJson($url, $this->selection())->assertConflict();
        $this->assertDatabaseCount('tax_preflight_reviews', 0);
    }

    public function test_saved_export_requires_tax_reports_access_and_cannot_read_other_clients(): void
    {
        [$company] = $this->book();
        $staff = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($staff, ['role' => 'staff', 'allowed_tabs' => json_encode(['dashboard', 'reports'])]);
        $this->actingAs($staff)->postJson(route('reports.sawt.saved', ['company_id' => $company->id]), $this->selection())->assertForbidden();
        $foreign = Company::factory()->create();
        $this->postJson(route('reports.sawt.saved', ['company_id' => $foreign->id]), $this->selection())->assertForbidden();
        $this->assertDatabaseCount('tax_preflight_reviews', 0);
    }

    public function test_unknown_saved_atc_and_ambiguous_master_identity_do_not_silently_export(): void
    {
        [$company, , $ledger] = $this->book();
        $state = $ledger->state;
        $state['cwtCertificates'][0]['atc'] = 'WC999';
        $company->run(fn () => $ledger->update(['state' => $state]));
        $this->postJson(route('reports.sawt.saved', ['company_id' => $company->id]), $this->selection())->assertUnprocessable()->assertJsonValidationErrors('certificates.0.atc');
        $state['cwtCertificates'][0]['atc'] = 'WC157';
        $company->run(function () use ($ledger, $state, $company): void {
            $ledger->update(['state' => $state]);
            Party::factory()->create(['workspace_key' => $company->workspaceKey(), 'classification' => 'Customer', 'name' => 'Payor Inc', 'tin' => '98765432100000']);
        });
        $this->postJson(route('reports.sawt.saved', ['company_id' => $company->id]), $this->selection())->assertUnprocessable()->assertJsonValidationErrors('records.0.tin');
    }

    public function test_vat_form_infers_schedule_and_preserves_large_decimal_amounts(): void
    {
        $settings = [...$this->settings(), 'form_type' => '2550Q', 'registered_name' => ''];
        unset($settings['tax_kind']);
        $service = app(SawtFileGeneratorService::class);
        $prepared = $service->prepare([[...$this->certificate(), 'atc_code' => 'WV010', 'income_payment' => '99999999999.99', 'tax_rate' => '5.00', 'tax_withheld' => '5000000000.00']], $settings);
        $this->assertSame('12345678900011220262550Q.DAT', $prepared['filename']);
        $this->assertStringContainsString('WV010,5.00,99999999999.99,5000000000.00', implode('', iterator_to_array($service->lines($prepared))));
    }

    public function test_annual_fiscal_export_keeps_certificates_separate(): void
    {
        $service = app(SawtFileGeneratorService::class);
        $record = $this->certificate();
        $settings = [...$this->settings(), 'form_type' => '1702', 'accounting_period' => 'fiscal', 'fiscal_end_month' => 6, 'from' => '2025-07-01', 'to' => '2026-06-30'];
        $prepared = $service->prepare([$record, [...$record, 'id' => 2]], $settings);
        $this->assertSame(2, $prepared['count']);
        $this->assertSame('12345678900010620261702.DAT', $prepared['filename']);
    }

    public function test_empty_selection_and_invalid_last_record_are_rejected_before_download(): void
    {
        $service = app(SawtFileGeneratorService::class);
        foreach ([[], [$this->certificate(), [...$this->certificate(), 'id' => 2, 'payor_name' => '|||']]] as $records) {
            try {
                $service->prepare($records, $this->settings());
                $this->fail('Invalid or empty export accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_saved_individual_identity_and_implied_rate_use_same_client_masters(): void
    {
        [$company, , $ledger] = $this->book();
        $company->run(function () use ($ledger): void {
            Party::query()->where('name', 'Payor Inc')->firstOrFail()->update(['entity_type' => 'individual', 'last_name' => 'Santos', 'first_name' => 'Ana']);
            $state = $ledger->state;
            $state['cwtCertificates'][0]['atc'] = 'WC157';
            $ledger->update(['state' => $state]);
        });
        $text = $this->postJson(route('reports.sawt.saved', ['company_id' => $company->id]), $this->selection())->assertOk()->streamedContent();
        $this->assertStringContainsString(',,"SANTOS","ANA","",12/2026,,WC157,2.00,446.43,8.93', $text);
    }

    public function test_saved_export_requires_sign_in(): void
    {
        $this->postJson(route('reports.sawt.saved'), $this->selection())->assertUnauthorized();
    }
}
