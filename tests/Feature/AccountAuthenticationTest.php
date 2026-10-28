<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_signin_and_signup_show_real_forms_and_a_separate_demo_link(): void
    {
        $this->get(route('login'))->assertOk()->assertViewIs('account-auth')
            ->assertSee('name="password"', false)->assertSee('Explore the existing demo');
        $this->get(route('register'))->assertOk()->assertSee('name="password_confirmation"', false)
            ->assertSee('Individual — my own company book')->assertSee('Firm — multiple client books');
        $this->get(route('preview.login'))->assertOk()->assertViewIs('auth');
        $this->get(route('account.dashboard'))->assertRedirect(route('login'));
    }

    public function test_registration_hashes_passwords_authenticates_and_clears_preview_company_context(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Old Demo'], 'active_company_id' => 55])
            ->post(route('register.store'), $this->registrationData())
            ->assertRedirect(route('account.registration-status'))
            ->assertSessionMissing('company_setup')->assertSessionMissing('preview_access')->assertSessionMissing('active_company_id');

        $user = User::query()->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('individual', $user->type);
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('StrongPassword123!', $user->password));
        $this->assertNotSame('StrongPassword123!', $user->password);
        $this->assertSame('pending', $user->registration_status);
        $this->assertFalse($user->is_system_owner);
        $this->assertNull($user->username);
        $this->get(route('account.dashboard'))->assertRedirect(route('account.registration-status'));
        $this->get(route('account.registration-status'))->assertOk()->assertSee('Awaiting owner approval');
    }

    public function test_firm_registration_and_normalized_email_are_supported(): void
    {
        $this->post(route('register.store'), [...$this->registrationData(), 'email' => '  FIRM@EXAMPLE.TEST ', 'type' => 'firm'])
            ->assertRedirect(route('account.registration-status'));
        $this->assertDatabaseHas('users', ['email' => 'firm@example.test', 'type' => 'firm']);
    }

    public function test_registration_rejects_accountant_signup_forged_roles_and_weak_passwords(): void
    {
        $this->postJson(route('register.store'), [
            ...$this->registrationData(), 'type' => 'accountant', 'role' => 'owner',
            'must_change_password' => false, 'company_id' => 8,
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['type', 'role', 'must_change_password', 'company_id', 'password']);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_duplicate_email_and_password_confirmation_are_validated(): void
    {
        User::factory()->create(['email' => 'owner@example.test']);
        $this->postJson(route('register.store'), [...$this->registrationData(), 'password_confirmation' => 'DifferentPassword123!'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_verifies_credentials_rotates_session_and_clears_preview_context(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test', 'password' => 'StrongPassword123!']);
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Old Demo'], 'active_company_id' => 5])
            ->post(route('login.store'), ['email' => ' OWNER@EXAMPLE.TEST ', 'password' => 'StrongPassword123!'])
            ->assertRedirect(route('account.dashboard'))->assertSessionMissing('company_setup')
            ->assertSessionMissing('preview_access')->assertSessionMissing('active_company_id');
        $this->assertAuthenticatedAs($user);
        $this->get(route('login'))->assertRedirect(route('account.dashboard'));
    }

    public function test_invalid_passwords_do_not_authenticate_and_repeated_attempts_are_throttled(): void
    {
        $user = User::factory()->create(['password' => 'StrongPassword123!']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'WrongPassword123!'])
                ->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'StrongPassword123!'])
            ->assertUnprocessable()->assertSee('Too many sign-in attempts');
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'StrongPassword123!'])
            ->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_temporary_password_accounts_are_redirected_before_any_workspace_access(): void
    {
        $user = User::factory()->accountant()->create(['password' => 'Temporary123!']);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Temporary123!'])
            ->assertRedirect(route('account.password.edit'));
        foreach (['/account', '/dashboard', '/company/setup', '/sheets/payroll', '/masters/parties', '/reports/trial-balance', '/preview'] as $path) {
            $this->get($path)->assertRedirect(route('account.password.edit'));
        }
        $this->postJson(route('reports.sawt.dat'), [])->assertForbidden()->assertJsonPath('password_change_url', route('account.password.edit'));
        $this->get(route('account.password.edit'))->assertOk()->assertSee('Set your own password');
    }

    public function test_password_change_requires_the_current_password_and_clears_the_mandatory_flag(): void
    {
        $user = User::factory()->accountant()->create(['password' => 'Temporary123!', 'remember_token' => 'old-token']);
        $this->actingAs($user)->putJson(route('account.password.update'), [
            'current_password' => 'wrong', 'password' => 'NewStrongPassword123!', 'password_confirmation' => 'NewStrongPassword123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertTrue($user->fresh()->must_change_password);
        $this->put(route('account.password.update'), [
            'current_password' => 'Temporary123!', 'password' => 'NewStrongPassword123!', 'password_confirmation' => 'NewStrongPassword123!',
        ])->assertRedirect(route('account.dashboard'));
        $updated = $user->fresh();
        $this->assertFalse($updated->must_change_password);
        $this->assertTrue(Hash::check('NewStrongPassword123!', $updated->password));
        $this->assertNotSame('old-token', $updated->remember_token);
        $this->get(route('account.dashboard'))->assertOk()->assertSee('No books assigned yet');
    }

    public function test_logout_clears_authentication_and_company_state_even_during_forced_password_change(): void
    {
        $user = User::factory()->accountant()->create();
        $this->actingAs($user)->withSession(['preview_access' => true, 'active_company_id' => 12, 'company_setup' => ['company_name' => 'Private']])
            ->post(route('logout'))->assertRedirect(route('login'))
            ->assertSessionMissing('company_setup')->assertSessionMissing('preview_access')->assertSessionMissing('active_company_id');
        $this->assertGuest();
        $this->get(route('account.dashboard'))->assertRedirect(route('login'));
    }

    public function test_book_dashboard_only_renders_assigned_companies_and_open_buttons(): void
    {
        $user = User::factory()->firm()->create();
        $book = Company::factory()->create(['name' => 'Visible Client Book']);
        Company::factory()->create(['name' => 'Hidden Foreign Company']);
        $book->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user)->get(route('account.dashboard'))->assertOk()->assertSee('Visible Client Book')
            ->assertSee('Open book')->assertSee('Create a company book')->assertDontSee('Hidden Foreign Company');
        $this->post(route('account.companies.store'), ['name' => 'New Client Book'])
            ->assertRedirect(route('company.setup', ['company_id' => Company::query()->where('name', 'New Client Book')->value('id')]))->assertSessionHas('active_company_id');
        $this->get(route('company.setup'))->assertOk()->assertSee('New Client Book');
    }

    public function test_authentication_forms_require_csrf_tokens(): void
    {
        $this->app->instance('env', 'production');
        $this->post(route('register.store'), $this->registrationData())->assertStatus(419);
        $this->post(route('login.store'), ['email' => 'owner@example.test', 'password' => 'StrongPassword123!'])->assertStatus(419);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_passwords_are_not_silently_truncated_by_bcrypt_and_null_bytes_are_rejected(): void
    {
        foreach ([str_repeat('Ab1', 25), 'GoodPassword123!'."\0", str_repeat('é', 36).'Ab1'] as $password) {
            $this->postJson(route('register.store'), [...$this->registrationData(), 'password' => $password, 'password_confirmation' => $password])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_sessions_with_an_old_password_hash_cannot_access_company_books(): void
    {
        $user = User::factory()->create();
        $oldHash = $user->password;
        $user->password = 'ChangedPassword123!';
        $user->save();
        $this->actingAs($user)->withSession(['password_hash_web' => $oldHash])
            ->get(route('account.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** @return array<string, string> */
    private function registrationData(): array
    {
        return ['name' => 'Example Owner', 'email' => 'owner@example.test', 'type' => 'individual', 'password' => 'StrongPassword123!', 'password_confirmation' => 'StrongPassword123!'];
    }
}
