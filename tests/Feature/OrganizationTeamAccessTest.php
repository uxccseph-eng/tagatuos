<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\TeamEvent;
use App\Models\User;
use App\Services\CompanyBookService;
use App\Services\CompanyLedgerService;
use App\Services\OrganizationTeamService;
use App\Services\TeamAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OrganizationTeamAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_can_edit_current_and_future_client_books_without_exposing_another_firm(): void
    {
        $owner = User::factory()->firm()->create();
        $first = $this->book($owner, 'Client One');
        $second = $this->book($owner, 'Client Two');
        $foreign = $this->book(User::factory()->firm()->create(), 'Other Firm Client');
        $member = $this->add($owner, $first);
        $third = $this->book($owner, 'Future Client');
        $this->actingAs($member)->getJson(route('account.companies.index'))->assertOk()->assertJsonCount(3, 'data')->assertDontSee('Other Firm Client');
        foreach ([$first, $second, $third] as $index => $book) {
            $this->post(route('account.companies.open', $book->id))->assertRedirect(route('dashboard', ['company_id' => $book->id]));
            $state = CompanyLedgerService::emptyState();
            $state['journalEntries'] = [['id' => 'entry-'.$index, 'number' => 'GJ-000001', 'date' => '2026-10-01', 'status' => 'posted', 'type' => 'General', 'lines' => [['account' => '1010 · Cash on Hand', 'debit' => 1000, 'credit' => 0], ['account' => '3010 · Owner Capital', 'debit' => 0, 'credit' => 1000]]]];
            $this->withHeader('X-Company-ID', (string) $book->id)->postJson(route('sheets.database.save'), ['state' => $state, 'payroll' => [], 'revision' => 0, 'request_id' => (string) Str::uuid()])->assertOk();
            $this->getJson(route('sheets.database.show'))->assertOk()->assertJsonPath('state.journalEntries.0.id', 'entry-'.$index)->assertJsonCount(1, 'journals');
        }
        $this->assertDatabaseCount('company_ledgers', 3);
        $this->assertDatabaseCount('journal_entries', 3);
        $this->assertDatabaseCount('company_ledger_changes', 3);
        $this->getJson(route('account.companies.show', $foreign->id))->assertNotFound();
        $this->post(route('account.companies.open', $foreign->id))->assertNotFound();
        $this->withSession(['active_company_id' => $foreign->id])->withHeader('X-Company-ID', (string) $foreign->id)->getJson(route('sheets.database.show'))->assertForbidden();
        $this->assertDatabaseMissing('company_ledgers', ['company_id' => $foreign->id]);
        $this->assertSame(3, DB::table('company_ledger_changes')->where('user_id', $member->id)->count());
    }

    public function test_suspension_updates_and_removal_apply_to_the_organization_only(): void
    {
        $owner = User::factory()->firm()->create();
        $first = $this->book($owner, 'First');
        $second = $this->book($owner, 'Second');
        $member = $this->add($owner, $first);
        $otherOwner = User::factory()->firm()->create();
        $foreign = $this->book($otherOwner, 'Other Organization');
        app(TeamAccessService::class)->add($otherOwner, $foreign->id, ['mode' => 'existing', 'email' => $member->email, 'role' => 'staff', 'tabs' => ['dashboard', 'books']]);
        $password = $member->password;
        $this->actingAs($owner)->put(route('team.update', ['companyId' => $second->id, 'memberId' => $member->id]), ['role' => 'accountant', 'tabs' => ['dashboard', 'sheets'], 'is_suspended' => true])->assertRedirect()->assertSessionHasNoErrors();
        foreach ([$first, $second] as $book) {
            $this->assertDatabaseHas('company_user', ['company_id' => $book->id, 'user_id' => $member->id, 'is_suspended' => true]);
            $this->actingAs($member)->withSession(['active_company_id' => $book->id, 'password_hash_web' => $member->getAuthPassword()])->getJson(route('sheets.database.show'))->assertForbidden();
            $this->assertDatabaseHas('team_events', ['company_id' => $book->id, 'subject_id' => $member->id, 'action' => 'Access updated']);
        }
        $future = $this->book($owner, 'Suspended Future');
        $this->assertDatabaseHas('company_user', ['company_id' => $future->id, 'user_id' => $member->id, 'is_suspended' => true]);
        $this->actingAs($member)->getJson(route('account.companies.index'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $foreign->id);
        app(TeamAccessService::class)->update($owner, $first->id, $member->id, ['role' => 'staff', 'tabs' => ['dashboard', 'books']]);
        $this->withSession(['active_company_id' => $second->id])->getJson(route('sheets.database.show'))->assertOk();
        $this->postJson(route('sheets.database.save'), [])->assertForbidden();
        $this->actingAs($owner)->withSession(['password_hash_web' => $owner->getAuthPassword()])->delete(route('team.remove', ['companyId' => $second->id, 'memberId' => $member->id]), ['confirmation' => 1])->assertRedirect()->assertSessionHasNoErrors();
        foreach ([$first, $second, $future] as $book) {
            $this->assertDatabaseMissing('company_user', ['company_id' => $book->id, 'user_id' => $member->id]);
        }
        $newBook = $this->book($owner, 'After Removal');
        $this->assertDatabaseMissing('company_user', ['company_id' => $newBook->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('company_user', ['company_id' => $foreign->id, 'user_id' => $member->id, 'role' => 'staff', 'is_suspended' => false]);
        $this->assertSame($password, $member->fresh()->password);
        $this->assertDatabaseHas('organization_members', ['owner_id' => $otherOwner->id, 'user_id' => $member->id]);
        $this->assertDatabaseMissing('organization_members', ['owner_id' => $owner->id, 'user_id' => $member->id]);
    }

    public function test_multi_book_setup_qr_requires_the_original_provisioner_and_completed_accounts_cannot_be_impersonated(): void
    {
        $owner = User::factory()->firm()->create();
        $first = $this->book($owner, 'First');
        $second = $this->book($owner, 'Second');
        $created = app(TeamAccessService::class)->add($owner, $first->id, $this->data());
        $member = $created['user'];
        $this->get($created['url'])->assertOk();
        $link = app(TeamAccessService::class)->setupLink($owner, $second->id, $member->id);
        $this->get($created['url'])->assertStatus(410);
        $this->get($link)->assertOk();
        $otherOwner = User::factory()->firm()->create();
        $other = $this->book($otherOwner, 'Foreign');
        $other->users()->attach($member, ['role' => 'accountant']);
        $this->actingAs($otherOwner)->postJson(route('team.setup-link', ['companyId' => $other->id, 'memberId' => $member->id]))->assertUnprocessable();
        $this->post(route('logout'));
        $this->get($link)->assertStatus(410);
        $other->users()->detach($member);
        $this->post($link)->assertRedirect(route('account.password.edit'));
        $this->put(route('account.password.update'), ['password' => 'NewStrongPassword123!', 'password_confirmation' => 'NewStrongPassword123!'])->assertRedirect();
        $this->getJson(route('account.companies.index'))->assertOk()->assertJsonCount(2, 'data');
        $this->assertFalse(app(OrganizationTeamService::class)->canSetUp($otherOwner, $member->fresh()));
    }

    public function test_legacy_team_upgrade_preserves_restrictions_suspension_and_unrelated_firm_memberships(): void
    {
        $owner = User::factory()->firm()->create();
        $first = $this->book($owner, 'First');
        $second = $this->book($owner, 'Second');
        $member = User::factory()->accountant()->create(['must_change_password' => false]);
        $first->users()->attach($member, ['role' => 'accountant', 'allowed_tabs' => json_encode(['dashboard', 'sheets', 'books'])]);
        $second->users()->attach($member, ['role' => 'staff', 'allowed_tabs' => json_encode(['dashboard', 'books']), 'is_suspended' => true]);
        $foreign = $this->book(User::factory()->firm()->create(), 'Foreign');
        $foreign->users()->attach($member, ['role' => 'accountant']);
        DB::transaction(fn () => app(OrganizationTeamService::class)->upgradeExistingTeam($owner));
        $future = $this->book($owner, 'Future');
        foreach ([$first, $second, $future] as $book) {
            $membership = $book->users()->whereKey($member->id)->firstOrFail()->pivot;
            $this->assertSame('staff', $membership->role);
            $this->assertEquals(1, $membership->is_suspended);
            $this->assertSame(['dashboard', 'books'], json_decode($membership->allowed_tabs, true));
        }
        $this->assertDatabaseHas('company_user', ['company_id' => $foreign->id, 'user_id' => $member->id, 'role' => 'accountant', 'is_suspended' => false]);
        $this->assertDatabaseCount('organization_members', 1);
        DB::transaction(fn () => app(OrganizationTeamService::class)->upgradeExistingTeam($owner));
        $this->assertDatabaseCount('organization_members', 1);
        $this->assertDatabaseCount('team_events', 2);
    }

    public function test_co_owned_books_do_not_bridge_two_organizations_and_untrusted_scope_inputs_are_ignored(): void
    {
        $owner = User::factory()->firm()->create();
        $foreignOwner = User::factory()->firm()->create();
        $book = $this->book($owner, 'First');
        $foreign = $this->book($foreignOwner, 'Other Firm');
        $shared = Company::factory()->create();
        $shared->users()->attach([$owner->id => ['role' => 'owner'], $foreignOwner->id => ['role' => 'owner']]);
        $this->actingAs($owner)->post(route('team.store', $book->id), [...$this->data(), 'organization_owner_id' => $foreignOwner->id, 'company_ids' => [$foreign->id, $shared->id]])->assertRedirect()->assertSessionHasNoErrors();
        $member = User::query()->where('email', $this->data()['email'])->sole();
        $this->assertDatabaseMissing('company_user', ['company_id' => $foreign->id, 'user_id' => $member->id]);
        $this->assertDatabaseMissing('company_user', ['company_id' => $shared->id, 'user_id' => $member->id]);
        $this->assertNull($shared->fresh()->organization_owner_id);
        $this->assertDatabaseHas('organization_members', ['owner_id' => $owner->id, 'user_id' => $member->id]);
        $this->get(route('team.index', $book->id))->assertOk()->assertSee('All organization books')->assertSee('Organization team');
    }

    public function test_audit_failure_rolls_back_every_organization_membership_and_user(): void
    {
        $owner = User::factory()->firm()->create();
        $first = $this->book($owner, 'First');
        $this->book($owner, 'Second');
        $eventName = 'eloquent.creating: '.TeamEvent::class;
        Event::listen($eventName, function (): void {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            app(TeamAccessService::class)->add($owner, $first->id, $this->data());
            $this->fail('The entire operation must roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('company_user', 2);
        $this->assertDatabaseCount('organization_members', 0);
        $this->assertDatabaseCount('team_login_tokens', 0);
    }

    private function book(User $owner, string $name): Company
    {
        $company = app(CompanyBookService::class)->createBook($owner, $name);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => $company->name, 'full_name' => 'Example Owner', 'vat_type' => 'non_vat', 'tin' => '000000000', 'branch_code' => '00000']]);

        return $company;
    }

    private function add(User $owner, Company $company): User
    {
        $member = app(TeamAccessService::class)->add($owner, $company->id, $this->data())['user'];
        $member->forceFill(['must_change_password' => false])->save();

        return $member;
    }

    /** @return array{mode: string, name: string, email: string, role: string, tabs: list<string>} */
    private function data(): array
    {
        return ['mode' => 'new', 'name' => 'Team Accountant', 'email' => 'team@example.test', 'role' => 'accountant', 'tabs' => ['dashboard', 'sheets', 'books', 'masters']];
    }
}
