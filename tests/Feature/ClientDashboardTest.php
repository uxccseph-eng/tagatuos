<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_book_shows_analytics_and_all_assigned_clients_without_onboarding(): void
    {
        $owner = User::factory()->firm()->create();
        $books = Company::factory()->count(40)->create();
        foreach ($books as $book) {
            $owner->companies()->attach($book, ['role' => 'owner']);
        }
        $hidden = Company::factory()->create(['name' => 'Suspended client']);
        $owner->companies()->attach($hidden, ['role' => 'accountant', 'is_suspended' => true]);
        $foreign = Company::factory()->create(['name' => 'Foreign private client']);
        $this->profile($books[0]);
        $response = $this->actingAs($owner)->get(route('dashboard', ['company_id' => $books[0]->id]))->assertOk()
            ->assertSee('Cash &amp; Bank', false)->assertSee('Net Income (YTD)')->assertSee('Available client books')->assertDontSee('Get started')->assertDontSee('Free Core')->assertDontSee('Local workspace preview')->assertDontSee($hidden->name)->assertDontSee($foreign->name);
        foreach ($books as $book) {
            $response->assertSee(route('dashboard', ['company_id' => $book->id]), false);
        }
        $this->assertDatabaseCount('company_ledgers', 0);
    }

    public function test_switch_links_use_explicit_company_context_and_do_not_grant_access_to_other_clients(): void
    {
        $user = User::factory()->firm()->create();
        $first = Company::factory()->create(['name' => 'First client']);
        $second = Company::factory()->create(['name' => 'Second client']);
        foreach ([$first, $second] as $book) {
            $user->companies()->attach($book, ['role' => 'owner']);
            $this->profile($book);
        }
        $this->actingAs($user)->withSession(['active_company_id' => $first->id])
            ->get(route('dashboard', ['company_id' => $second->id]))->assertOk()->assertViewHas('company', fn (array $company): bool => $company['company_id'] === $second->id);
        $this->get(route('dashboard', ['company_id' => $first->id]))->assertOk()->assertViewHas('company', fn (array $company): bool => $company['company_id'] === $first->id);
        $foreign = Company::factory()->create();
        $this->getJson(route('dashboard', ['company_id' => $foreign->id]))->assertForbidden();
    }

    private function profile(Company $book): void
    {
        CompanySetting::factory()->create(['workspace_key' => $book->workspaceKey(), 'lookup_key' => $book->workspaceKey(), 'profile' => ['company_name' => $book->name, 'full_name' => 'Test owner', 'tin' => '123456789', 'vat_type' => 'vat']]);
    }
}
