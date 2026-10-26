<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\RegistrationReview;
use App\Models\User;
use App\Services\RegistrationApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class RegistrationApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_owner_is_not_system_owner_and_cannot_review_registrations(): void
    {
        $owner = User::factory()->firm()->create();
        $company = Company::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        $applicant = User::factory()->pending()->create();
        $this->actingAs($owner)->get(route('system.dashboard'))->assertForbidden();
        $this->postJson(route('system.registrations.approve', $applicant->id), ['username' => 'some.client'])->assertForbidden();
        $this->postJson(route('system.registrations.reject', $applicant->id), ['reason' => 'Declined'])->assertForbidden();
        $this->assertSame('pending', $applicant->fresh()->registration_status);
        $this->assertDatabaseCount('registration_reviews', 0);
    }

    public function test_pending_accounts_cannot_bypass_approval_with_preview_or_company_sessions(): void
    {
        $user = User::factory()->pending()->create(['must_change_password' => true]);
        $company = Company::factory()->create();
        $company->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user)->withSession(['preview_access' => true, 'active_company_id' => $company->id]);
        foreach (['/account', '/company/setup', '/sheets/sales', '/masters/parties', '/reports/trial-balance', '/tax-reports/sawt', '/preview/team', '/system'] as $path) {
            $this->get($path)->assertRedirect(route('account.registration-status'));
        }
        $this->postJson(route('reports.sawt.dat'), [])->assertForbidden()->assertJsonPath('registration_status', 'pending');
        $this->get(route('account.registration-status'))->assertOk()->assertSee('Awaiting owner approval');
        $this->get(route('account.password.edit'))->assertOk();
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_owner_dashboard_lists_applicants_and_does_not_expose_financial_books(): void
    {
        $owner = User::factory()->systemOwner()->create();
        $applicant = User::factory()->pending()->firm()->create(['name' => 'Review This Firm']);
        Company::factory()->create(['name' => 'Private Foreign Financial Book']);
        $this->actingAs($owner)->get(route('system.dashboard'))->assertOk()->assertSee($applicant->name)->assertSee('Registration approvals')->assertDontSee('Private Foreign Financial Book');
        $this->get(route('system.dashboard', ['status' => 'approved']))->assertOk()->assertDontSee($applicant->name);
        $this->assertDatabaseCount('company_user', 0);
    }

    public function test_approval_assigns_username_preserves_password_and_memberships_and_records_audit(): void
    {
        $owner = User::factory()->systemOwner()->create();
        $applicant = User::factory()->pending()->create(['password' => 'StrongPassword123!']);
        $hash = $applicant->password;
        $company = Company::factory()->create();
        $company->users()->attach($applicant, ['role' => 'owner']);
        $this->actingAs($owner)->post(route('system.registrations.approve', $applicant->id), ['username' => '  My.Client  '])->assertRedirect(route('system.dashboard'));
        $updated = $applicant->fresh();
        $this->assertSame('my.client', $updated->username);
        $this->assertSame('approved', $updated->registration_status);
        $this->assertSame($hash, $updated->password);
        $this->assertFalse($updated->is_system_owner);
        $this->assertSame($owner->id, $updated->reviewed_by);
        $this->assertDatabaseHas('registration_reviews', ['actor_id' => $owner->id, 'applicant_id' => $applicant->id, 'action' => 'approved']);
        $this->assertDatabaseCount('company_user', 1);
        $this->post(route('account.companies.open', $company->id))->assertNotFound();
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => 'MY.CLIENT', 'password' => 'StrongPassword123!'])->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($updated);
    }

    public function test_rejection_requires_reason_and_is_visible_only_to_the_applicant(): void
    {
        $owner = User::factory()->systemOwner()->create();
        $applicant = User::factory()->pending()->create();
        $other = User::factory()->pending()->create();
        $this->actingAs($owner)->postJson(route('system.registrations.reject', $applicant->id), ['reason' => ' '])->assertUnprocessable();
        $this->post(route('system.registrations.reject', $applicant->id), ['reason' => '<script>Review registration details</script>'])->assertRedirect(route('system.dashboard'));
        $this->assertSame('rejected', $applicant->fresh()->registration_status);
        $this->actingAs($applicant->fresh())->get(route('account.registration-status'))->assertOk()->assertSee('Review registration details')->assertDontSee('<script>Review registration details</script>', false);
        $this->actingAs($other)->get(route('account.registration-status'))->assertOk()->assertDontSee('Review registration details');
        $this->actingAs($owner)->post(route('system.registrations.approve', $applicant->id), ['username' => 'approved.later'])->assertRedirect(route('system.dashboard'));
        $this->assertNull($applicant->fresh()->rejection_reason);
        $this->assertDatabaseCount('registration_reviews', 2);
    }

    public function test_stale_rejection_cannot_disable_an_approved_account(): void
    {
        $owner = User::factory()->systemOwner()->create();
        $applicant = User::factory()->create();
        $this->actingAs($owner)->postJson(route('system.registrations.reject', $applicant->id), ['reason' => 'Stale review'])->assertUnprocessable();
        $this->assertSame('approved', $applicant->fresh()->registration_status);
        $this->assertDatabaseCount('registration_reviews', 0);
    }

    public function test_invalid_reserved_and_duplicate_usernames_do_not_approve_accounts(): void
    {
        $owner = User::factory()->systemOwner()->create();
        $applicant = User::factory()->pending()->create();
        User::factory()->create(['username' => 'existing.client']);
        $this->actingAs($owner);
        foreach (['', 'ab', 'admin', 'has spaces', '123name', 'existing.client'] as $username) {
            $this->postJson(route('system.registrations.approve', $applicant->id), ['username' => $username])->assertUnprocessable()->assertJsonValidationErrors('username');
        }
        $this->assertSame('pending', $applicant->fresh()->registration_status);
        $this->assertNull($applicant->fresh()->username);
        $this->assertDatabaseCount('registration_reviews', 0);
    }

    public function test_direct_service_duplicate_username_rolls_back_approval(): void
    {
        $owner = User::factory()->systemOwner()->create();
        $applicant = User::factory()->pending()->create();
        User::factory()->create(['username' => 'taken.name']);
        try {
            app(RegistrationApprovalService::class)->approve($owner, $applicant->id, 'taken.name');
            $this->fail('Duplicate username should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('username', $exception->errors());
        }
        $this->assertSame('pending', $applicant->fresh()->registration_status);
        $this->assertDatabaseCount('registration_reviews', 0);
    }

    public function test_failed_audit_rolls_back_the_whole_review(): void
    {
        $owner = User::factory()->systemOwner()->create();
        $applicant = User::factory()->pending()->create();
        Event::listen('eloquent.creating: '.RegistrationReview::class, function (): void {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            app(RegistrationApprovalService::class)->approve($owner, $applicant->id, 'valid.client');
            $this->fail('Audit failure should abort approval.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame('pending', $applicant->fresh()->registration_status);
        $this->assertNull($applicant->fresh()->username);
        $this->assertDatabaseCount('registration_reviews', 0);
    }

    public function test_username_change_preserves_account_password_and_audits_the_change(): void
    {
        $owner = User::factory()->systemOwner()->create();
        $applicant = User::factory()->create(['username' => 'old.name']);
        $hash = $applicant->password;
        $this->actingAs($owner)->put(route('system.registrations.username', $applicant->id), ['username' => 'new.name'])->assertRedirect(route('system.dashboard', ['status' => 'approved']));
        $this->assertSame('new.name', $applicant->fresh()->username);
        $this->assertSame($hash, $applicant->fresh()->password);
        $this->assertDatabaseHas('registration_reviews', ['action' => 'username_changed', 'reason' => 'Previous username: old.name']);
    }

    public function test_system_owner_and_provisioned_staff_cannot_be_reviewed_as_applicants(): void
    {
        $owner = User::factory()->systemOwner()->create();
        $staff = User::factory()->accountant()->create();
        $this->actingAs($owner);
        foreach ([$owner, $staff] as $target) {
            $this->postJson(route('system.registrations.approve', $target->id), ['username' => 'some.name'])->assertNotFound();
        }
    }

    public function test_owner_command_uses_exact_email_and_preserves_existing_credentials_and_books(): void
    {
        $first = User::factory()->create();
        $target = User::factory()->firm()->create(['email' => 'confirmed@example.test']);
        $hash = $target->password;
        $company = Company::factory()->create();
        $company->users()->attach($target, ['role' => 'owner']);
        $this->artisan('system:appoint-owner', ['email' => ' CONFIRMED@EXAMPLE.TEST '])->assertSuccessful();
        $this->assertTrue($target->fresh()->is_system_owner);
        $this->assertFalse($first->fresh()->is_system_owner);
        $this->assertSame($hash, $target->fresh()->password);
        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $target->id, 'role' => 'owner']);
        $this->artisan('system:appoint-owner', ['email' => $target->email])->assertSuccessful();
        $this->assertDatabaseCount('registration_reviews', 1);
        $this->artisan('system:appoint-owner', ['email' => $first->email])->assertFailed();
        $this->artisan('system:appoint-owner', ['email' => 'missing@example.test'])->assertFailed();
        $this->assertSame(1, User::query()->where('is_system_owner', true)->count());
    }

    public function test_signup_cannot_assign_privileged_fields(): void
    {
        $this->postJson(route('register.store'), ['name' => 'Applicant', 'email' => 'new@example.test', 'type' => 'firm', 'password' => 'StrongPassword123!', 'password_confirmation' => 'StrongPassword123!', 'username' => 'self.approved', 'is_system_owner' => true, 'registration_status' => 'approved', 'reviewed_by' => 1])->assertUnprocessable()->assertJsonValidationErrors(['username', 'is_system_owner', 'registration_status', 'reviewed_by']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_service_rechecks_owner_authorization_against_current_database_state(): void
    {
        $owner = User::factory()->systemOwner()->create();
        $applicant = User::factory()->pending()->create();
        User::query()->whereKey($owner->id)->update(['is_system_owner' => false]);
        $this->expectException(AuthorizationException::class);
        app(RegistrationApprovalService::class)->approve($owner, $applicant->id, 'valid.client');
    }
}
