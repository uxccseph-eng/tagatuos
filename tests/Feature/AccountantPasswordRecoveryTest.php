<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TeamEvent;
use App\Models\TeamLoginToken;
use App\Models\User;
use App\Services\OrganizationTeamService;
use App\Services\TeamAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountantPasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_reauthenticates_and_issues_a_private_single_use_recovery_link(): void
    {
        [$owner, $company, $member] = $this->team();
        $oldPassword = $member->password;
        $oldToken = TeamLoginToken::factory()->create(['company_id' => $company->id, 'user_id' => $member->id, 'issued_by' => $owner->id]);
        $memberships = $member->companies()->get()->map(fn (Company $book): array => $book->pivot->toArray())->all();
        $this->actingAs($owner)->get(route('team.index', $company->id))->assertOk()->assertSee('Reset password');
        $this->post($this->endpoint($company, $member), $this->confirmation())->assertRedirect(route('team.index', $company->id))->assertSessionHasNoErrors();
        $url = session('team_credential.url');
        $this->assertTrue($member->fresh()->must_change_password);
        $this->assertNotSame($oldPassword, $member->fresh()->password);
        $this->assertNotNull($oldToken->fresh()->used_at);
        $this->assertSame($memberships, $member->fresh()->companies()->get()->map(fn (Company $book): array => $book->pivot->toArray())->all());
        $event = TeamEvent::query()->where('action', 'Password recovery issued')->sole();
        $this->assertStringNotContainsString('signature', json_encode($event->details));
        $this->post(route('logout'));
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->post($url)->assertRedirect(route('account.password.edit'));
        $this->get(route('sheets.sales'))->assertRedirect(route('account.password.edit'));
        $this->put(route('account.password.update'), ['password' => 'RecoveredSecret123!', 'password_confirmation' => 'RecoveredSecret123!'])->assertRedirect(route('account.dashboard'));
        $this->assertTrue(Hash::check('RecoveredSecret123!', $member->fresh()->password));
        $this->assertFalse($member->fresh()->must_change_password);
        $this->post(route('logout'));
        $this->get($url)->assertStatus(410);
    }

    public function test_owner_password_and_explicit_confirmation_are_required(): void
    {
        [$owner, $company, $member] = $this->team();
        $old = $member->password;
        $this->actingAs($owner)->postJson($this->endpoint($company, $member), ['current_password' => 'wrong', 'confirmation' => 1])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson($this->endpoint($company, $member), ['current_password' => 'OwnerSecret123!'])->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->assertSame($old, $member->fresh()->password);
        $this->assertDatabaseCount('team_login_tokens', 0);
    }

    public function test_other_owners_managers_and_foreign_members_are_rejected(): void
    {
        [$owner, $company, $member] = $this->team();
        $outsider = User::factory()->firm()->create(['password' => 'OwnerSecret123!']);
        $this->actingAs($outsider)->postJson($this->endpoint($company, $member), $this->confirmation())->assertNotFound();
        $company->users()->attach($outsider, ['role' => 'manager']);
        $this->postJson($this->endpoint($company, $member), $this->confirmation())->assertNotFound();
        $foreign = User::factory()->accountant()->create(['must_change_password' => false]);
        $this->post(route('logout'));
        $this->actingAs($owner)->postJson($this->endpoint($company, $foreign), $this->confirmation())->assertNotFound();
        $this->assertDatabaseCount('team_login_tokens', 0);
    }

    public function test_suspended_protected_and_accounts_with_outside_books_cannot_be_reset(): void
    {
        [$owner, $company, $member] = $this->team();
        $this->actingAs($owner);
        $this->postJson($this->endpoint($company, $owner), $this->confirmation())->assertUnprocessable();
        $company->users()->updateExistingPivot($member->id, ['is_suspended' => true]);
        $this->postJson($this->endpoint($company, $member), $this->confirmation())->assertUnprocessable();
        $company->users()->updateExistingPivot($member->id, ['is_suspended' => false]);
        $other = Company::factory()->create();
        $other->users()->attach($member, ['role' => 'accountant']);
        $this->postJson($this->endpoint($company, $member), $this->confirmation())->assertUnprocessable();
        $this->assertFalse($member->fresh()->must_change_password);
        $this->assertDatabaseCount('team_login_tokens', 0);
    }

    public function test_reissued_expired_and_tampered_links_are_rejected(): void
    {
        [$owner, $company, $member] = $this->team();
        $service = app(TeamAccessService::class);
        $old = $service->resetPassword($owner, $company->id, $member->id);
        $url = $service->setupLink($owner, $company->id, $member->id);
        $this->get($old)->assertStatus(410);
        $this->get($url.'x')->assertForbidden();
        $this->travel(16)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_previous_authenticated_sessions_are_invalidated_by_recovery(): void
    {
        [$owner, $company, $member] = $this->team();
        $oldHash = $member->password;
        app(TeamAccessService::class)->resetPassword($owner, $company->id, $member->id);

        $this->actingAs($member->fresh())->withSession(['password_hash_web' => $oldHash])
            ->getJson(route('account.dashboard'))->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_reset_is_allowed_across_books_of_the_same_provisioning_organization(): void
    {
        [$owner, $company, $member] = $this->team();
        $other = Company::factory()->create();
        $other->users()->attach($owner, ['role' => 'owner']);
        $organization = app(OrganizationTeamService::class);
        $organization->claimBooks($owner);
        $organization->save($owner, $member, ['role' => 'accountant', 'tabs' => ['dashboard', 'sheets']]);

        $this->actingAs($owner)->post($this->endpoint($company, $member), $this->confirmation())->assertSessionHasNoErrors();
        $this->assertTrue($member->fresh()->must_change_password);
        $this->assertSame(2, $member->companies()->count());
        $this->assertSame(2, TeamEvent::query()->where('action', 'Password recovery issued')->count());
    }

    /** @return array{User, Company, User} */
    private function team(): array
    {
        $owner = User::factory()->firm()->create(['password' => 'OwnerSecret123!']);
        $company = Company::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        $member = User::factory()->accountant()->create(['must_change_password' => false, 'provisioned_by' => $owner->id]);
        $company->users()->attach($member, ['role' => 'accountant']);

        return [$owner, $company, $member];
    }

    private function endpoint(Company $company, User $member): string
    {
        return route('team.password-recovery', ['companyId' => $company->id, 'memberId' => $member->id]);
    }

    /** @return array{current_password: string, confirmation: int} */
    private function confirmation(): array
    {
        return ['current_password' => 'OwnerSecret123!', 'confirmation' => 1];
    }
}
