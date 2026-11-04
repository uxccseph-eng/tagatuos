<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaxReturnArchiveTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Company, User} */
    private function book(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00:00', 'Asia/Manila'));
        $owner = User::factory()->firm()->create(['must_change_password' => false]);
        $company = Company::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => 'Archive Demo', 'full_name' => $owner->name, 'tin' => '123456789', 'branch_code' => '00000', 'rdo' => '033', 'entity_type' => 'corporation']]);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->withHeader('X-Ledger-Revision', '0');

        return [$company, $owner];
    }

    /** @return array<string, mixed> */
    private function input(Company $company): array
    {
        $selection = ['form' => '0619-E', 'year' => 2026, 'period' => 10];
        $preview = $this->getJson(route('tax-returns.preview', ['company_id' => $company->id, ...$selection]))->assertOk()->json();

        return [...$selection, 'request_id' => (string) Str::uuid(), 'ledger_revision' => 0, 'source_hash' => $preview['source_hash'], 'original_id' => $preview['original_id'], 'original_revision' => $preview['original_revision'], 'additional_tax' => '0.00', 'credits' => '0.00', 'surcharge' => '0.00', 'interest' => '0.00', 'compromise' => '0.00', 'review_note' => 'Reviewed nil month and source records.', 'reviewed' => true];
    }

    public function test_generating_again_updates_one_original_and_old_retries_do_not_overwrite_it(): void
    {
        [$company] = $this->book();
        $url = route('tax-returns.prepare', ['company_id' => $company->id]);
        $first = $this->input($company);
        $id = $this->postJson($url, $first)->assertCreated()->json('id');
        $next = [...$this->input($company), 'surcharge' => '25.00'];
        $this->postJson($url, $next)->assertOk()->assertJsonPath('id', $id)->assertJsonPath('updated', true);
        $this->assertDatabaseCount('tax_return_preparations', 1);
        $this->assertDatabaseHas('tax_return_preparations', ['id' => $id, 'revision' => 2, 'original_slot' => 1]);
        $snapshot = DB::table('tax_return_preparations')->find($id)->snapshot;
        $this->postJson($url, $first)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('id', $id);
        $this->assertSame($snapshot, DB::table('tax_return_preparations')->find($id)->snapshot);
        $this->assertDatabaseCount('tax_return_save_requests', 2);
    }

    public function test_two_accountants_cannot_create_duplicate_originals_or_silently_replace_a_new_revision(): void
    {
        [$company] = $this->book();
        $url = route('tax-returns.prepare', ['company_id' => $company->id]);
        $first = $this->input($company);
        $second = $this->input($company);
        $this->postJson($url, $first)->assertCreated();
        $this->postJson($url, $second)->assertConflict();
        $firstUpdate = $this->input($company);
        $secondUpdate = $this->input($company);
        $this->postJson($url, $firstUpdate)->assertOk();
        $this->postJson($url, $secondUpdate)->assertConflict();
        $this->assertDatabaseCount('tax_return_preparations', 1);
    }

    public function test_only_checking_amended_creates_a_separate_entry(): void
    {
        [$company] = $this->book();
        $url = route('tax-returns.prepare', ['company_id' => $company->id]);
        $this->postJson($url, $this->input($company))->assertCreated();
        $amended = [...$this->input($company), 'amended' => true];
        $id = $this->postJson($url, $amended)->assertCreated()->json('id');
        $this->postJson($url, $amended)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('tax_return_preparations', 2);
        $this->assertDatabaseHas('tax_return_preparations', ['id' => $id, 'amended' => true, 'original_slot' => null]);
    }

    public function test_delete_hides_the_unfiled_preparation_and_all_its_downloads_and_allows_a_fresh_original(): void
    {
        [$company] = $this->book();
        $input = $this->input($company);
        $prepare = route('tax-returns.prepare', ['company_id' => $company->id]);
        $id = $this->postJson($prepare, $input)->assertCreated()->json('id');
        $delete = route('tax-returns.destroy', ['company_id' => $company->id, 'id' => $id]);
        $this->postJson($delete, ['confirmed' => true, 'revision' => 2])->assertConflict();
        $this->postJson($delete, ['confirmed' => true, 'revision' => 1])->assertOk();
        $this->assertNotNull(DB::table('tax_return_preparations')->find($id)->deleted_at);
        foreach (['show', 'download', 'xml'] as $route) {
            $this->getJson(route('tax-returns.'.$route, ['company_id' => $company->id, 'id' => $id]))->assertNotFound();
        }
        $this->postJson($prepare, $input)->assertConflict();
        $this->get(route('tax-returns.index', ['company_id' => $company->id]))->assertOk()->assertDontSee('data-archive-id="'.$id.'"', false);
        $this->postJson($prepare, $this->input($company))->assertCreated();
        $this->assertSame(1, DB::table('tax_return_preparations')->where('original_slot', 1)->whereNull('deleted_at')->count());
    }

    public function test_mark_as_filed_updates_badges_and_protects_the_original_but_allows_an_amendment(): void
    {
        [$company] = $this->book();
        $prepare = route('tax-returns.prepare', ['company_id' => $company->id]);
        $id = $this->postJson($prepare, $this->input($company))->assertCreated()->json('id');
        $filing = ['form' => '0619-E', 'year' => 2026, 'period' => 10, 'original_id' => $id, 'original_revision' => 1, 'reference' => 'DEMO-REF', 'filed_on' => '2026-11-01', 'confirmed' => true];
        $this->postJson(route('tax-returns.period-filed', ['company_id' => $company->id]), $filing)->assertOk();
        $this->assertDatabaseHas('tax_return_preparations', ['id' => $id, 'status' => 'filed', 'revision' => 2]);
        $page = $this->get(route('tax-returns.index', ['company_id' => $company->id, 'frequency' => 'monthly', 'year' => 2026, 'period' => 10]))->assertOk()->assertSee('return-filed-badge')->assertSee('DEMO-REF');
        $page->assertDontSee(route('tax-returns.destroy', ['company_id' => $company->id, 'id' => $id]), false);
        $this->postJson(route('tax-returns.destroy', ['company_id' => $company->id, 'id' => $id]), ['confirmed' => true, 'revision' => 2])->assertUnprocessable();
        $this->postJson($prepare, $this->input($company))->assertUnprocessable()->assertJsonValidationErrors('amended');
        $amendment = $this->postJson($prepare, [...$this->input($company), 'amended' => true])->assertCreated()->json('id');
        $this->postJson(route('tax-returns.filed', ['company_id' => $company->id, 'id' => $amendment]), ['reference' => 'DEMO-AMENDED', 'filed_on' => '2026-11-02', 'confirmed' => true, 'revision' => 1])->assertOk();
        $this->assertSame('DEMO-REF', DB::table('tax_return_filing_records')->sole()->reference);
    }

    public function test_filing_refuses_to_mark_a_preparation_changed_since_the_page_loaded(): void
    {
        [$company] = $this->book();
        $url = route('tax-returns.prepare', ['company_id' => $company->id]);
        $id = $this->postJson($url, $this->input($company))->assertCreated()->json('id');
        $this->postJson($url, $this->input($company))->assertOk();
        $this->postJson(route('tax-returns.filed', ['company_id' => $company->id, 'id' => $id]), ['reference' => 'STALE', 'filed_on' => '2026-11-01', 'confirmed' => true, 'revision' => 1])->assertConflict();
        $this->assertDatabaseHas('tax_return_preparations', ['id' => $id, 'status' => 'prepared']);
    }

    public function test_legacy_duplicates_are_consolidated_without_removing_stored_copies(): void
    {
        $previous = DB::getDefaultConnection();
        config(['database.connections.archive_migration_test' => [...config('database.connections.sqlite'), 'database' => ':memory:', 'url' => null]]);
        DB::setDefaultConnection('archive_migration_test');
        try {
            $base = require database_path('migrations/2026_11_03_185833_create_tax_return_preparations_table.php');
            $base->up();
            foreach (range(1, 5) as $id) {
                DB::table('tax_return_preparations')->insert(['id' => $id, 'company_id' => $id === 5 ? 2 : 1, 'request_id' => (string) Str::uuid(), 'prepared_by' => 1, 'form' => '0619-E', 'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'ledger_revision' => 0, 'source_hash' => str_repeat('a', 64), 'payload_hash' => str_repeat('b', 64), 'snapshot' => json_encode(['amended' => $id === 4]), 'status' => $id === 2 ? 'filed' : 'prepared', 'created_at' => now(), 'updated_at' => now()]);
            }
            $migration = require database_path('migrations/2026_11_04_023417_add_original_identity_to_tax_return_preparations.php');
            $migration->up();
            $this->assertSame(5, DB::table('tax_return_preparations')->count());
            $this->assertSame([2, 5], DB::table('tax_return_preparations')->where('original_slot', 1)->orderBy('id')->pluck('id')->all());
            $this->assertSame(1, (int) DB::table('tax_return_preparations')->where('id', 4)->value('amended'));
            $this->assertSame(0, DB::table('tax_return_preparations')->whereNotNull('deleted_at')->count());
            $this->assertSame(5, DB::table('tax_return_save_requests')->count());
            $migration->down();
            $this->assertSame(5, DB::table('tax_return_preparations')->count());
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge('archive_migration_test');
        }
    }

    public function test_delete_enforces_company_scope_and_roles(): void
    {
        [$company, $owner] = $this->book();
        $id = $this->postJson(route('tax-returns.prepare', ['company_id' => $company->id]), $this->input($company))->assertCreated()->json('id');
        $other = Company::factory()->create();
        $other->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $other->workspaceKey(), 'lookup_key' => $other->workspaceKey()]);
        $this->postJson(route('tax-returns.destroy', ['company_id' => $other->id, 'id' => $id]), ['confirmed' => true, 'revision' => 1])->assertNotFound();
        $staff = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($staff, ['role' => 'staff', 'allowed_tabs' => json_encode(['sheets'])]);
        $this->actingAs($staff)->postJson(route('tax-returns.destroy', ['company_id' => $company->id, 'id' => $id]), ['confirmed' => true, 'revision' => 1])->assertForbidden();
        $this->assertNull(DB::table('tax_return_preparations')->find($id)->deleted_at);
    }
}
