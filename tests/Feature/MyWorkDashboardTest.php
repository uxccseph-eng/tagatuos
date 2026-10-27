<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\CompanyLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MyWorkDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_pending_users_and_unfinished_password_setup_cannot_see_work(): void
    {
        $this->get(route('my-work'))->assertRedirect(route('login'));
        $pending = User::factory()->pending()->create();
        $this->actingAs($pending)->getJson(route('my-work'))->assertForbidden();
        $accountant = User::factory()->accountant()->create();
        $this->actingAs($accountant)->get(route('my-work'))->assertRedirect(route('account.password.edit'));
    }

    public function test_dashboard_summarizes_only_accessible_books_and_database_records(): void
    {
        $user = User::factory()->firm()->create();
        $first = $this->book($user, 'First Client');
        $second = $this->book($user, 'Second Client', false);
        $foreign = $this->book(User::factory()->firm()->create(), 'Foreign Secret Client');
        $suspended = $this->book($user, 'Suspended Secret Client');
        $suspended->users()->updateExistingPivot($user->id, ['is_suspended' => true]);
        $this->drafts($first, 2);
        $this->drafts($foreign, 7);
        $this->drafts($suspended, 5);
        $this->posting($first, $user, 'GJ-OWN');
        $this->posting($foreign, $user, 'GJ-FOREIGN');
        $this->posting($suspended, $user, 'GJ-SUSPENDED');
        $this->actingAs($user)->get(route('my-work'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertViewHas('summary', ['books' => 2, 'ready' => 1, 'drafts' => 2, 'posted' => 1])
            ->assertViewHas('setupNeeded', 1)->assertSee('First Client')->assertSee('Second Client')->assertSee('2 drafts to review')->assertSee('GJ-OWN')
            ->assertDontSee('Foreign Secret Client')->assertDontSee('Suspended Secret Client')->assertDontSee('GJ-FOREIGN')->assertDontSee('GJ-SUSPENDED');
        $this->assertDatabaseCount('company_ledgers', 3);
        $this->assertDatabaseCount('journal_entries', 3);
        $this->assertDatabaseCount('company_ledger_changes', 0);
    }

    public function test_module_permissions_hide_drafts_and_activity_but_keep_assigned_books_visible(): void
    {
        $user = User::factory()->accountant()->create(['must_change_password' => false]);
        $book = $this->book($user, 'Restricted Client');
        $book->users()->updateExistingPivot($user->id, ['role' => 'staff', 'allowed_tabs' => json_encode(['dashboard', 'masters'])]);
        $this->drafts($book, 3);
        $this->posting($book, $user, 'RESTRICTED-JOURNAL');
        $this->actingAs($user)->get(route('my-work'))->assertOk()->assertSee('Restricted Client')->assertDontSee('RESTRICTED-JOURNAL')->assertDontSee('Your organization team')
            ->assertViewHas('summary', ['books' => 1, 'ready' => 1, 'drafts' => 0, 'posted' => 0]);
        $book->users()->updateExistingPivot($user->id, ['allowed_tabs' => json_encode(['dashboard', 'books'])]);
        $this->get(route('my-work'))->assertOk()->assertSee('RESTRICTED-JOURNAL')->assertViewHas('summary', ['books' => 1, 'ready' => 1, 'drafts' => 0, 'posted' => 1]);
    }

    public function test_summary_covers_all_books_while_search_and_pagination_only_filter_the_table(): void
    {
        $user = User::factory()->firm()->create();
        foreach (range(1, 13) as $index) {
            $this->book($user, 'Client '.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
        }
        $this->actingAs($user)->get(route('my-work'))->assertOk()->assertSee('Client 01')->assertDontSee('Client 13')->assertViewHas('summary.books', 13);
        $this->get(route('my-work', ['page' => 2]))->assertOk()->assertSee('Client 13')->assertDontSee('Client 01');
        $this->get(route('my-work', ['q' => 'Client 13']))->assertOk()->assertSee('Client 13')->assertDontSee('Client 01')->assertViewHas('summary.books', 13);
        $this->get(route('my-work', ['q' => 'No Such Client']))->assertOk()->assertSee('No matching client books');
        $this->getJson(route('my-work', ['q' => ['invalid']]))->assertUnprocessable();
    }

    public function test_new_accounts_have_a_useful_empty_state_and_untrusted_names_are_escaped(): void
    {
        $user = User::factory()->firm()->create();
        $this->actingAs($user)->get(route('my-work'))->assertOk()->assertSee('Your workspace is ready to begin')->assertSee('Create your first company book');
        $this->book($user, '<script>alert(1)</script>');
        $this->get(route('my-work'))->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_search_treats_percent_and_underscore_as_literal_client_name_characters(): void
    {
        $user = User::factory()->firm()->create();
        $this->book($user, 'Client_100%');
        $this->book($user, 'ClientX1000');
        $this->actingAs($user)->get(route('my-work', ['q' => '_100%']))->assertOk()->assertSee('Client_100%')->assertDontSee('ClientX1000');
    }

    public function test_sidebar_and_workspace_search_connect_to_the_new_work_page(): void
    {
        $user = User::factory()->firm()->create();
        $book = $this->book($user, 'Connected Client');
        $this->actingAs($user)->withSession(['active_company_id' => $book->id])->get(route('sheets.sales'))->assertOk()->assertSee('data-path="my-work" href="'.route('my-work').'"', false)->assertDontSee('data-sales-info="task_alt My work"', false);
        $this->get(route('dashboard'))->assertOk()->assertViewHas('dashboardRoutes', fn (array $routes): bool => $routes['work'] === route('my-work'));
    }

    private function book(User $user, string $name, bool $configured = true): Company
    {
        $book = Company::factory()->create(['name' => $name]);
        $book->users()->attach($user, ['role' => 'owner']);
        if ($configured) {
            CompanySetting::factory()->create(['workspace_key' => $book->workspaceKey(), 'lookup_key' => $book->workspaceKey(), 'profile' => ['company_name' => $name, 'full_name' => 'Example', 'tin' => '000000000', 'branch_code' => '00000', 'vat_type' => 'non_vat', 'accounting_period' => 'calendar']]);
        }

        return $book;
    }

    private function drafts(Company $book, int $count): void
    {
        $state = CompanyLedgerService::emptyState();
        $sheets = ['sales', 'purchases', 'collections', 'payments', 'cwtCertificates', 'wtpCertificates', 'journalEntries'];
        foreach (array_slice($sheets, 0, $count) as $sheet) {
            $state[$sheet] = [['id' => 1, 'status' => 'draft']];
        }
        CompanyLedger::factory()->create(['company_id' => $book->id, 'state' => $state]);
    }

    private function posting(Company $book, User $actor, string $number): void
    {
        DB::table('journal_entries')->insert(['company_id' => $book->id, 'source_key' => 'journalEntries:1', 'sheet' => 'journalEntries', 'number' => $number, 'transaction_date' => '2026-10-26', 'status' => 'posted', 'reference' => '', 'posted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
    }
}
