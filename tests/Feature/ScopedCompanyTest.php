<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\User;
use App\Services\CompanyLedgerService;
use App\Services\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class ScopedCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabs_keep_their_own_company_after_another_company_is_opened(): void
    {
        [$first, $owner] = $this->book();
        [$second] = $this->book($owner);
        $this->actingAs($owner)->withSession(['active_company_id' => $second->id, '_token' => 'stable-token']);
        $state = CompanyLedgerService::emptyState();
        $state['purchases'] = [['id' => 1, 'status' => 'draft', 'number' => '', 'date' => '2026-10-01']];
        $payload = ['state' => $state, 'payroll' => [], 'revision' => 0, 'request_id' => (string) Str::uuid()];
        $this->withHeader('X-Company-ID', (string) $first->id)->postJson(route('sheets.database.save', ['company_id' => $first->id]), $payload)->assertOk()->assertJsonPath('company_id', $first->id)->assertSessionHas('active_company_id', $second->id)->assertSessionHas('_token', 'stable-token');
        $this->getJson(route('sheets.database.show', ['company_id' => $first->id]))->assertOk()->assertJsonCount(1, 'state.purchases');
        $this->withHeader('X-Company-ID', (string) $second->id)->getJson(route('sheets.database.show', ['company_id' => $second->id]))->assertOk()->assertJsonCount(0, 'state.purchases');
        $this->assertSame('sqlite', DB::getDefaultConnection());
        $this->assertCount(1, DB::getConnections());
    }

    public function test_switching_books_does_not_rotate_the_csrf_token(): void
    {
        [$company, $owner] = $this->book();
        $this->actingAs($owner)->withSession(['_token' => 'stable-token']);
        $this->post(route('account.companies.open', $company->id))->assertRedirect(route('dashboard', ['company_id' => $company->id]))->assertSessionHas('_token', 'stable-token');
    }

    public function test_conflicting_or_unassigned_company_identifiers_cannot_save(): void
    {
        [$first, $owner] = $this->book();
        [$second] = $this->book($owner);
        [$foreign] = $this->book();
        $this->actingAs($owner)->withSession(['active_company_id' => $first->id]);
        $this->withHeader('X-Company-ID', (string) $first->id)->postJson(route('sheets.database.save', ['company_id' => $second->id]), [])->assertConflict();
        $this->withHeader('X-Company-ID', (string) $foreign->id)->getJson(route('sheets.database.show'))->assertConflict();
        $this->assertDatabaseCount('company_ledgers', 0);
    }

    public function test_global_scope_fails_closed_and_automatically_sets_ownership(): void
    {
        [$first] = $this->book();
        [$second] = $this->book();
        app(TenantContext::class)->restore([null, null, false]);
        $this->assertSame(0, ChartAccount::query()->count());
        $first->run(function () use ($first, $second): void {
            $record = Party::factory()->create(['workspace_key' => $first->workspaceKey(), 'name' => 'Client supplier']);
            $this->assertSame($first->id, $record->company_id);
            $this->assertSame(1, Party::query()->count());
            $this->assertSame('company:'.$first->id.':report', app(TenantContext::class)->cacheKey('report'));
            try {
                Party::factory()->create(['workspace_key' => $second->workspaceKey(), 'company_id' => $second->id]);
                $this->fail('Cross-company creation must be rejected.');
            } catch (LogicException) {
                $this->assertSame(1, Party::query()->count());
            }
            try {
                $record->forceFill(['company_id' => $second->id])->save();
                $this->fail('Ownership changes must be rejected.');
            } catch (LogicException) {
                $this->assertSame($first->id, $record->fresh()->company_id);
            }
        });
        $second->run(fn () => $this->assertSame(0, Party::query()->count()));
        $this->assertSame(0, Party::query()->count());
    }

    public function test_missing_context_cannot_create_financial_records(): void
    {
        app(TenantContext::class)->restore([null, null, false]);
        $this->expectException(LogicException::class);
        CompanyLedger::query()->create(['company_id' => 1, 'revision' => 0, 'state' => [], 'payroll' => []]);
    }

    public function test_database_rejects_a_journal_line_pointing_to_another_company_account(): void
    {
        [$first, $owner] = $this->book();
        [$second] = $this->book();
        $entry = DB::table('journal_entries')->insertGetId(['company_id' => $first->id, 'source_key' => 'test', 'sheet' => 'journalEntries', 'number' => 'GJ-1', 'transaction_date' => '2026-10-01', 'status' => 'posted', 'posted_by' => $owner->id]);
        $account = $second->run(fn () => ChartAccount::query()->firstOrFail());
        $this->expectException(QueryException::class);
        DB::table('journal_entry_lines')->insert(['company_id' => $first->id, 'journal_entry_id' => $entry, 'chart_account_id' => $account->id, 'account_name' => 'Foreign', 'debit' => 100, 'credit' => 0]);
    }

    public function test_database_switching_is_disabled(): void
    {
        [$company] = $this->book();
        $this->artisan('clients:separate', ['company' => $company->id, 'connection' => 'client_test'])->expectsOutputToContain('Separate database activation is disabled')->assertFailed();
        $this->assertNull($company->fresh()->tenancy_activated_at);
    }

    public function test_ownership_backfill_preserves_existing_ids_and_can_resume_safely(): void
    {
        [$company] = $this->book();
        $party = Party::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => 'Preserved supplier']);
        DB::table('parties')->where('id', $party->id)->update(['company_id' => null]);
        $migration = require database_path('migrations/2026_10_28_035510_scope_accounting_records_to_companies.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('parties', ['id' => $party->id, 'company_id' => $company->id, 'name' => 'Preserved supplier']);
        $company->run(fn () => $this->assertSame($party->id, Party::query()->sole()->id));
    }

    public function test_redirects_and_catalog_scripts_keep_the_explicit_tab_company(): void
    {
        [$first, $owner] = $this->book();
        [$second] = $this->book($owner);
        $this->actingAs($owner)->withSession(['active_company_id' => $second->id]);
        $this->get(route('sheets.purchases', ['company_id' => $first->id]))->assertOk()->assertSee('name="tenant-company" content="'.$first->id.'"', false)->assertSee('_company_id='.$first->id, false);
        $this->get(route('masters.accounts.catalog', ['_company_id' => $first->id]))->assertOk()->assertSee('window.CompanyLedgerDatabase')->assertSessionHas('active_company_id', $second->id);
    }

    public function test_route_model_binding_is_scoped_before_the_record_is_resolved(): void
    {
        [$first, $owner] = $this->book();
        [$second] = $this->book($owner);
        $own = Party::factory()->create(['workspace_key' => $first->workspaceKey()]);
        $foreign = Party::factory()->create(['workspace_key' => $second->workspaceKey()]);
        Route::middleware('web')->get('/masters/scoped-test/{party}', fn (Party $party) => response()->json(['id' => $party->id]));
        $this->actingAs($owner)->withSession(['active_company_id' => $second->id]);
        $this->getJson('/masters/scoped-test/'.$own->id.'?company_id='.$first->id)->assertOk()->assertJsonPath('id', $own->id);
        $this->getJson('/masters/scoped-test/'.$foreign->id.'?company_id='.$first->id)->assertNotFound();
    }

    public function test_a_preloaded_foreign_record_cannot_be_deleted_from_another_company_context(): void
    {
        [$first] = $this->book();
        [$second] = $this->book();
        $foreign = Party::factory()->create(['workspace_key' => $second->workspaceKey()]);
        try {
            $first->run(fn () => $foreign->delete());
            $this->fail('Deleting a foreign record must be rejected.');
        } catch (LogicException) {
            $this->assertDatabaseHas('parties', ['id' => $foreign->id, 'company_id' => $second->id]);
        }
    }

    /** @return array{Company, User} */
    private function book(?User $owner = null): array
    {
        $owner ??= User::factory()->firm()->create();
        $company = Company::factory()->create(['organization_owner_id' => $owner->id]);
        $company->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => $company->name, 'full_name' => 'Example', 'vat_type' => 'vat', 'tin' => '000000000', 'branch_code' => '00000']]);
        ChartAccount::seedWorkspace($company->workspaceKey());

        return [$company, $owner];
    }
}
