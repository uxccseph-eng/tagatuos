<?php

namespace Tests\Feature;

use App\Jobs\ProvisionClientDatabase;
use App\Models\Company;
use App\Models\User;
use App\Services\OrganizationTeamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountantClientCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_creates_a_firm_owned_client_and_the_team_inherits_access(): void
    {
        [$firm, $accountant] = $this->team();
        $colleague = User::factory()->accountant()->create(['must_change_password' => false]);
        app(OrganizationTeamService::class)->save($firm, $colleague, ['role' => 'staff', 'tabs' => ['sheets']]);
        config(['tenancy.enabled' => true]);
        Bus::fake();
        $response = $this->actingAs($accountant)->postJson(route('account.companies.store'), ['name' => 'Accountant Created Client'])->assertCreated()->assertJsonPath('data.role', 'accountant')->assertJsonPath('data.can_retry_setup', true);
        $id = $response->json('data.id');
        $this->assertDatabaseHas('companies', ['id' => $id, 'organization_owner_id' => $firm->id]);
        $this->assertDatabaseHas('company_user', ['company_id' => $id, 'user_id' => $firm->id, 'role' => 'owner']);
        $this->assertDatabaseHas('company_user', ['company_id' => $id, 'user_id' => $accountant->id, 'role' => 'accountant']);
        $this->assertDatabaseHas('company_user', ['company_id' => $id, 'user_id' => $colleague->id, 'role' => 'staff']);
        $this->assertDatabaseHas('team_events', ['company_id' => $id, 'actor_id' => $accountant->id, 'action' => 'Client book created']);
        Bus::assertDispatched(ProvisionClientDatabase::class, fn ($job): bool => $job->companyId === $id);
    }

    public function test_dashboard_shows_creation_for_eligible_accountants_even_before_the_first_client(): void
    {
        [$firm, $accountant] = $this->team();
        $this->actingAs($accountant)->get(route('account.dashboard'))->assertOk()->assertSee('Create a company book')->assertSee('name="firm_id"', false)->assertSee($firm->name)->assertSee('firm remains the owner');
    }

    public function test_staff_restricted_and_suspended_members_cannot_create_clients(): void
    {
        foreach ([['staff', ['settings'], false], ['accountant', ['sheets'], false], ['accountant', ['settings'], true]] as [$role, $tabs, $suspended]) {
            [$firm, $member] = $this->team($role, $tabs, $suspended);
            $this->actingAs($member)->postJson(route('account.companies.store'), ['name' => 'Forbidden Client', 'firm_id' => $firm->id])->assertForbidden();
        }
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_multiple_firms_require_an_explicit_choice_and_cannot_target_an_unrelated_firm(): void
    {
        [$first, $accountant] = $this->team();
        $second = User::factory()->firm()->create();
        $foreign = User::factory()->firm()->create();
        app(OrganizationTeamService::class)->save($second, $accountant, ['role' => 'accountant', 'tabs' => ['settings', 'sheets']]);
        $this->actingAs($accountant)->postJson(route('account.companies.store'), ['name' => 'Ambiguous Client'])->assertUnprocessable()->assertJsonValidationErrors('firm_id');
        $this->postJson(route('account.companies.store'), ['name' => 'Foreign Client', 'firm_id' => $foreign->id])->assertForbidden();
        $this->postJson(route('account.companies.store'), ['name' => 'Second Firm Client', 'firm_id' => $second->id])->assertCreated();
        $this->assertDatabaseHas('companies', ['name' => 'Second Firm Client', 'organization_owner_id' => $second->id]);
        $this->assertDatabaseMissing('companies', ['organization_owner_id' => $first->id]);
    }

    public function test_removed_membership_and_owner_spoofing_do_not_create_a_client(): void
    {
        [$firm, $accountant] = $this->team();
        $this->actingAs($accountant)->postJson(route('account.companies.store'), ['name' => 'Forged Client', 'firm_id' => $firm->id, 'role' => 'owner'])->assertUnprocessable();
        DB::table('organization_members')->where('owner_id', $firm->id)->where('user_id', $accountant->id)->delete();
        $this->postJson(route('account.companies.store'), ['name' => 'Removed Access', 'firm_id' => $firm->id])->assertForbidden();
        $this->actingAs($firm)->postJson(route('account.companies.store'), ['name' => 'Owner Spoof', 'firm_id' => 999])->assertUnprocessable();
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_accountant_can_retry_failed_setup_but_does_not_gain_ownership(): void
    {
        [$firm, $accountant] = $this->team();
        $id = $this->actingAs($accountant)->postJson(route('account.companies.store'), ['name' => 'Retry Client'])->assertCreated()->json('data.id');
        $company = Company::query()->findOrFail($id);
        $company->forceFill(['provisioning_status' => 'failed'])->save();
        config(['tenancy.enabled' => true]);
        Bus::fake();
        $this->post(route('account.companies.provision', $id))->assertRedirect(route('account.dashboard'));
        Bus::assertDispatched(ProvisionClientDatabase::class);
        $this->assertDatabaseHas('company_user', ['company_id' => $id, 'user_id' => $accountant->id, 'role' => 'accountant']);
        app(OrganizationTeamService::class)->save($firm, $accountant, ['role' => 'accountant', 'tabs' => ['sheets']]);
        $this->post(route('account.companies.provision', $id))->assertForbidden();
    }

    /** @return array{User, User} */
    private function team(string $role = 'accountant', array $tabs = ['settings', 'sheets'], bool $suspended = false): array
    {
        $firm = User::factory()->firm()->create();
        $accountant = User::factory()->accountant()->create(['must_change_password' => false]);
        app(OrganizationTeamService::class)->save($firm, $accountant, ['role' => $role, 'tabs' => $tabs, 'is_suspended' => $suspended]);

        return [$firm, $accountant];
    }
}
