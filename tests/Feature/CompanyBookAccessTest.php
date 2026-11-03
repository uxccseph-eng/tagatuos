<?php

namespace Tests\Feature;

use App\Jobs\ProvisionClientDatabase;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyBookService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class CompanyBookAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_sessions_do_not_authenticate_company_book_requests(): void
    {
        $company = Company::factory()->create();

        $this->withSession(['preview_access' => true, 'company_setup' => ['company_id' => $company->id]])
            ->getJson(route('account.companies.index'))->assertUnauthorized();
        $this->getJson(route('account.companies.show', $company->id))->assertUnauthorized();
        $this->postJson(route('account.companies.store'), ['name' => 'Untrusted Book'])->assertUnauthorized();
        $this->assertDatabaseMissing('companies', ['name' => 'Untrusted Book']);
    }

    public function test_status_polling_returns_only_requested_assigned_books_and_validates_ids(): void
    {
        $owner = User::factory()->firm()->create();
        $first = Company::factory()->create(['provisioning_status' => 'pending']);
        $second = Company::factory()->create(['provisioning_status' => 'failed', 'provisioning_error' => 'Client setup needs retry.']);
        $foreign = Company::factory()->create();
        foreach ([$first, $second] as $company) {
            $company->users()->attach($owner, ['role' => 'owner']);
        }
        $this->actingAs($owner)->getJson(route('account.companies.index', ['ids' => [$second->id, $foreign->id]]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.0.setup_status', 'failed')->assertJsonPath('data.0.database_ready', false)->assertJsonPath('data.0.setup_message', 'Client setup needs retry.')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson(route('account.companies.index', ['ids' => [$first->id, $first->id]]))->assertUnprocessable();
        $this->getJson(route('account.companies.index', ['ids' => ['bad']]))->assertUnprocessable();
        $this->getJson(route('account.companies.index', ['ids' => range(1, 26)]))->assertUnprocessable();
    }

    public function test_pending_client_displays_progress_and_failed_retry_resets_its_status(): void
    {
        config(['tenancy.enabled' => true]);
        Bus::fake([ProvisionClientDatabase::class]);
        $owner = User::factory()->firm()->create();
        $company = Company::factory()->create(['provisioning_status' => 'pending']);
        $company->users()->attach($owner, ['role' => 'owner']);
        $this->actingAs($owner)->get(route('account.dashboard'))->assertOk()->assertSee('data-client-progress', false)->assertSee('Waiting for client setup to start')->assertSee('client-book-progress.js');
        $company->forceFill(['provisioning_status' => 'failed', 'provisioning_error' => 'Old failure'])->save();
        $this->post(route('account.companies.provision', $company->id))->assertRedirect(route('account.dashboard'));
        $this->assertSame('pending', $company->fresh()->provisioning_status);
        $this->assertNull($company->fresh()->provisioning_error);
        Bus::assertDispatched(ProvisionClientDatabase::class, fn (ProvisionClientDatabase $job): bool => $job->companyId === $company->id && $job->afterCommit);
    }

    public function test_firms_can_create_multiple_books_with_server_assigned_owner_memberships(): void
    {
        $firm = User::factory()->firm()->create();

        foreach (['Client One', 'Client Two'] as $name) {
            $response = $this->actingAs($firm)->postJson(route('account.companies.store'), ['name' => $name]);
            $response->assertCreated()->assertJsonPath('data.name', $name)->assertJsonPath('data.role', 'owner');
            $this->assertDatabaseHas('company_user', [
                'company_id' => $response->json('data.id'), 'user_id' => $firm->id, 'role' => 'owner',
            ]);
        }

        $this->getJson(route('account.companies.index'))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_individuals_cannot_create_a_second_book(): void
    {
        $individual = User::factory()->create();
        $this->actingAs($individual)->postJson(route('account.companies.store'), ['name' => 'My Book'])->assertCreated();
        $this->postJson(route('account.companies.store'), ['name' => 'Second Book'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('company_user', 1);
    }

    public function test_an_assigned_book_also_counts_toward_the_individual_limit(): void
    {
        $individual = User::factory()->create();
        $company = Company::factory()->create();
        $company->users()->attach($individual, ['role' => 'staff']);

        $this->actingAs($individual)->postJson(route('account.companies.store'), ['name' => 'Extra Book'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_members_only_see_their_assigned_books_and_membership_roles(): void
    {
        $accountant = User::factory()->accountant()->create(['must_change_password' => false]);
        $own = Company::factory()->create(['name' => 'Assigned Book']);
        $foreign = Company::factory()->create(['name' => 'Private Other Company']);
        $own->users()->attach($accountant, ['role' => 'staff']);

        $this->actingAs($accountant)->getJson(route('account.companies.index'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.role', 'staff')->assertDontSee($foreign->name);
        $this->getJson(route('account.companies.show', $own->id))->assertOk()->assertJsonPath('data.role', 'staff');
        $this->postJson(route('account.companies.store'), ['name' => 'Unauthorized Book'])->assertForbidden();
        $this->assertDatabaseMissing('companies', ['name' => 'Unauthorized Book']);
    }

    public function test_foreign_company_ids_return_the_same_not_found_response_as_missing_ids(): void
    {
        $user = User::factory()->firm()->create();
        $foreign = Company::factory()->create();

        $this->actingAs($user)->withSession(['company_id' => $foreign->id])
            ->getJson(route('account.companies.show', $foreign->id))->assertNotFound();
        $this->getJson(route('account.companies.show', 999999))->assertNotFound();
    }

    public function test_removed_membership_is_checked_again_on_the_next_request(): void
    {
        $user = User::factory()->firm()->create();
        $company = Company::factory()->create();
        $company->users()->attach($user, ['role' => 'accountant']);

        $this->actingAs($user)->getJson(route('account.companies.show', $company->id))->assertOk();
        $company->users()->detach($user);
        $this->withSession(['company_id' => $company->id])
            ->getJson(route('account.companies.show', $company->id))->assertNotFound();
        $this->getJson(route('account.companies.index'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_temporary_password_accounts_cannot_read_or_create_books(): void
    {
        $user = User::factory()->firm()->create(['must_change_password' => true]);
        $company = Company::factory()->create();
        $company->users()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)->getJson(route('account.companies.index'))->assertForbidden();
        $this->getJson(route('account.companies.show', $company->id))->assertForbidden();
        $this->postJson(route('account.companies.store'), ['name' => 'Blocked Book'])->assertForbidden();
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_company_creation_cannot_assign_another_owner_or_change_account_privileges(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->postJson(route('account.companies.store'), [
            'name' => 'Forged Book', 'user_id' => $other->id, 'company_id' => 100,
            'role' => 'owner', 'type' => 'firm', 'must_change_password' => false,
            'users' => [$other->id],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'user_id', 'company_id', 'role', 'type', 'must_change_password', 'users',
        ]);

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('company_user', 0);
        $this->assertSame('individual', $user->fresh()->type);
    }

    public function test_portal_slugs_are_normalized_unique_and_cannot_shadow_application_paths(): void
    {
        $user = User::factory()->firm()->create();
        $this->actingAs($user)->postJson(route('account.companies.store'), [
            'name' => '  Example Firm  ', 'slug' => '  EXAMPLE-FIRM  ',
        ])->assertCreated()->assertJsonPath('data.name', 'Example Firm')->assertJsonPath('data.slug', 'example-firm');

        foreach (['example-firm', 'sheets', '../company', 'invalid/slash'] as $slug) {
            $this->postJson(route('account.companies.store'), ['name' => 'Another Book', 'slug' => $slug])
                ->assertUnprocessable()->assertJsonValidationErrors('slug');
        }

        $this->assertDatabaseCount('companies', 1);
    }

    public function test_company_creation_rolls_back_if_creation_cannot_finish(): void
    {
        $user = User::factory()->firm()->create();
        $event = 'eloquent.created: '.Company::class;
        Event::listen($event, function (Company $company): void {
            throw new RuntimeException('Simulated failure after inserting the company.');
        });

        try {
            $this->actingAs($user)->postJson(route('account.companies.store'), ['name' => 'Incomplete Book'])
                ->assertInternalServerError();
        } finally {
            Event::forget($event);
        }

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('company_user', 0);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_the_service_rechecks_account_type_instead_of_trusting_a_stale_caller(): void
    {
        $user = User::factory()->firm()->create();
        DB::table('users')->where('id', $user->id)->update(['type' => 'accountant']);

        $this->expectException(AuthorizationException::class);
        app(CompanyBookService::class)->createBook($user, 'Not Allowed');
    }

    public function test_the_database_rejects_duplicate_memberships(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $company->users()->attach($user, ['role' => 'owner']);

        $this->expectException(QueryException::class);
        $company->users()->attach($user, ['role' => 'staff']);
    }

    public function test_the_database_rejects_unrecognized_membership_roles(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();

        $this->expectException(QueryException::class);
        $company->users()->attach($user, ['role' => 'administrator']);
    }

    public function test_the_database_rejects_memberships_for_missing_users(): void
    {
        $company = Company::factory()->create();

        $this->expectException(QueryException::class);
        $company->users()->attach(999999, ['role' => 'owner']);
    }

    public function test_company_metadata_responses_are_not_cacheable(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $company->users()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)->getJson(route('account.companies.show', $company->id))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_cookie_authenticated_book_creation_requires_a_csrf_token(): void
    {
        $user = User::factory()->create();
        $this->app->instance('env', 'production');

        $this->actingAs($user)->postJson(route('account.companies.store'), ['name' => 'Blocked CSRF Book'])
            ->assertStatus(419);
        $this->assertDatabaseCount('companies', 0);
    }
}
