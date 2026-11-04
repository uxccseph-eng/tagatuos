<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaxReturnWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Company, User} */
    private function book(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00:00', 'Asia/Manila'));
        Storage::fake('client_files');
        $user = User::factory()->firm()->create(['must_change_password' => false]);
        $company = Company::factory()->create();
        $company->users()->attach($user, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => 'Example Trading', 'full_name' => $user->name, 'entity_type' => 'corporation', 'vat_type' => 'vat', 'tin' => '123456789', 'branch_code' => '00000', 'rdo' => '033']]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);

        return [$company, $user];
    }

    /** @return array{form: string, year: int, period: int} */
    private function selection(): array
    {
        return ['form' => '0619-E', 'year' => 2026, 'period' => 10];
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return ['year' => 2026, 'revision' => 0, 'regime' => 'regular', 'deduction_method' => 'itemized', 'mcit_start_year' => 2030, 'president' => 'Example President', 'treasurer' => 'Example Treasurer', 'reviewed' => true];
    }

    public function test_external_filing_without_preparation_is_idempotent_and_cannot_be_overwritten(): void
    {
        [$company] = $this->book();
        $url = route('tax-returns.period-filed', ['company_id' => $company->id]);
        $input = [...$this->selection(), 'filed_on' => '2026-11-01', 'confirmed' => true];
        $this->postJson($url, $input)->assertOk();
        $this->postJson($url, $input)->assertOk();
        $this->postJson($url, [...$input, 'reference' => 'Different reference'])->assertConflict();
        $this->assertDatabaseCount('tax_return_preparations', 0);
        $this->assertDatabaseCount('tax_return_filing_records', 1);
        $this->assertDatabaseHas('tax_return_filing_records', ['company_id' => $company->id, 'filed_on' => '2026-11-01', 'reference' => null]);
        $this->get(route('tax-returns.index', ['company_id' => $company->id, 'frequency' => 'monthly', 'year' => 2026, 'period' => 10]))->assertOk()->assertSee('External filing recorded');
    }

    public function test_filing_rejects_future_and_preperiod_dates_and_unconfirmed_requests(): void
    {
        [$company] = $this->book();
        $url = route('tax-returns.period-filed', ['company_id' => $company->id]);
        foreach (['2026-10-01', '2026-11-05'] as $date) {
            $this->postJson($url, [...$this->selection(), 'filed_on' => $date, 'confirmed' => true])->assertUnprocessable();
        }
        $this->postJson($url, [...$this->selection(), 'filed_on' => '2026-11-01'])->assertUnprocessable();
        $this->assertDatabaseCount('tax_return_filing_records', 0);
    }

    public function test_proofs_are_private_deduplicated_and_do_not_mark_a_return_filed(): void
    {
        [$company, $owner] = $this->book();
        $url = route('tax-returns.proofs.upload', ['company_id' => $company->id]);
        $bytes = "%PDF-1.4\nExample proof\n%%EOF";
        for ($i = 0; $i < 2; $i++) {
            $this->post($url, [...$this->selection(), 'file' => UploadedFile::fake()->createWithContent('receipt.pdf', $bytes)], ['Accept' => 'application/json'])->assertCreated();
        }
        $this->assertDatabaseCount('tax_return_proofs', 1);
        $proof = DB::table('tax_return_proofs')->sole();
        $this->assertStringStartsWith($company->storageDirectory().'/tax-returns/', $proof->path);
        $this->assertSame(hash('sha256', $bytes), $proof->sha256);
        Storage::disk('client_files')->assertExists($proof->path);
        $this->assertNull(DB::table('tax_return_filing_records')->sole()->filed_on);
        $download = route('tax-returns.proofs.download', ['company_id' => $company->id, 'id' => $proof->id]);
        $this->assertSame($bytes, $this->get($download)->assertOk()->assertDownload('receipt.pdf')->streamedContent());
        $foreign = Company::factory()->create();
        $foreign->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $foreign->workspaceKey(), 'lookup_key' => $foreign->workspaceKey()]);
        $this->getJson(route('tax-returns.proofs.download', ['company_id' => $foreign->id, 'id' => $proof->id]))->assertNotFound();
        $this->getJson($download)->assertOk();
        $this->assertCount(1, Storage::disk('client_files')->allFiles());
    }

    public function test_invalid_uploads_are_rejected_before_creating_files_or_filing_records(): void
    {
        [$company] = $this->book();
        $url = route('tax-returns.proofs.upload', ['company_id' => $company->id]);
        foreach ([UploadedFile::fake()->createWithContent('receipt.pdf', '<?php echo 1;'), UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')] as $file) {
            $this->post($url, [...$this->selection(), 'file' => $file], ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('tax_return_proofs', 0);
        $this->assertDatabaseCount('tax_return_filing_records', 0);
        $this->assertSame([], Storage::disk('client_files')->allFiles());
    }

    public function test_annual_settings_use_revisions_and_stale_preparations_cannot_save(): void
    {
        [$company] = $this->book();
        $selection = ['form' => '1702Q', 'year' => 2026, 'period' => 3];
        $preview = $this->getJson(route('tax-returns.preview', ['company_id' => $company->id, ...$selection]))->assertOk()->json();
        $url = route('tax-returns.year-settings', ['company_id' => $company->id]);
        $this->postJson($url, $this->settings())->assertOk();
        $this->postJson($url, [...$this->settings(), 'regime' => 'exempt'])->assertConflict();
        $input = [...$selection, 'request_id' => (string) Str::uuid(), 'ledger_revision' => 0, 'source_hash' => $preview['source_hash'], 'reviewed_tax' => '0.00', 'additional_tax' => '0.00', 'credits' => '0.00', 'surcharge' => '0.00', 'interest' => '0.00', 'compromise' => '0.00', 'review_note' => 'Reviewed annual settings and computation', 'reviewed' => true];
        $prepare = route('tax-returns.prepare', ['company_id' => $company->id]);
        $this->withHeader('X-Ledger-Revision', '0')->postJson($prepare, $input)->assertConflict();
        $latest = $this->getJson(route('tax-returns.preview', ['company_id' => $company->id, ...$selection]))->assertOk()->assertJsonPath('annual_settings.revision', 1)->assertJsonPath('annual_settings.settings.treasurer', 'Example Treasurer')->json();
        $id = $this->postJson($prepare, [...$input, 'source_hash' => $latest['source_hash']])->assertCreated()->json('id');
        $snapshot = DB::table('tax_return_preparations')->find($id)->snapshot;
        $this->postJson($url, [...$this->settings(), 'revision' => 1, 'deduction_method' => 'osd'])->assertOk();
        $this->assertSame($snapshot, DB::table('tax_return_preparations')->find($id)->snapshot);
        $this->get(route('tax-returns.index', ['company_id' => $company->id, 'frequency' => 'quarterly', 'year' => 2026, 'period' => 3]))->assertOk()->assertSee('Annual settings changed');
    }

    public function test_tax_reports_access_is_required_and_settings_access_is_separate(): void
    {
        [$company] = $this->book();
        $staff = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($staff, ['role' => 'staff', 'allowed_tabs' => json_encode(['tax-reports'])]);
        $this->actingAs($staff)->postJson(route('tax-returns.year-settings', ['company_id' => $company->id]), $this->settings())->assertForbidden();
        $this->get(route('tax-returns.index', ['company_id' => $company->id, 'frequency' => 'annual', 'year' => 2026]))->assertOk()->assertSee('Your role needs Settings access');
        $company->users()->updateExistingPivot($staff->id, ['allowed_tabs' => json_encode(['sheets'])]);
        $this->postJson(route('tax-returns.period-filed', ['company_id' => $company->id]), [...$this->selection(), 'filed_on' => '2026-11-01', 'confirmed' => true])->assertForbidden();
        $this->postJson(route('tax-returns.proofs.upload', ['company_id' => $company->id]), [])->assertForbidden();
    }

    public function test_annual_forms_render_as_reviewed_worksheets_without_claiming_xml_support(): void
    {
        [$company] = $this->book();
        $this->get(route('tax-returns.index', ['company_id' => $company->id, 'frequency' => 'annual', 'year' => 2026]))->assertOk()->assertSee('1702-EX')->assertSee('1702-MX')->assertSee('1604-F')->assertSee('Proof of payment');
        $this->getJson(route('tax-returns.preview', ['company_id' => $company->id, 'form' => '1604-F', 'year' => 2026, 'period' => 1]))->assertOk()->assertJsonPath('information_return', true)->assertJsonPath('tax_before_credits', 0)->assertJsonPath('xml_capable', false);
    }
}
