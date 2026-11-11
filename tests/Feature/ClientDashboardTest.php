<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $response->assertSeeInOrder(['js/company-ledger-records.js', 'js/company-ledger-sync.js', 'js/workspace-ledger.js', 'js/dashboard-report.js', 'js/dashboard.js']);
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

    public function test_company_sheets_show_the_signed_in_accountant_and_one_working_switcher_without_preview_labels(): void
    {
        $accountant = User::factory()->accountant()->create(['name' => 'Actual Accountant', 'must_change_password' => false]);
        $book = Company::factory()->create();
        $accountant->companies()->attach($book, ['role' => 'accountant']);
        $this->profile($book);

        foreach (['sales', 'collections', 'purchases', 'payments', 'cwt', 'wtp', 'journal', 'payroll'] as $sheet) {
            $response = $this->actingAs($accountant)->get(route('sheets.'.$sheet, ['company_id' => $book->id]))->assertOk()
                ->assertSee('Actual Accountant')->assertSee('Accountant')->assertSee('Change password')->assertSee('Sign out')
                ->assertSee('Company book')->assertSee('Private client data')->assertDontSee('Demo workspace')
                ->assertDontSee('Local sales preview')->assertDontSee('Sample data · No BIR connection')->assertDontSee('Demo '.$sheet.' sheet');
            $this->assertSame(1, substr_count($response->getContent(), 'aria-label="Switch company book"'));
            $this->assertStringNotContainsString('cursor-pointer hover:bg-slate-200/60', $response->getContent());
        }
    }

    public function test_anonymous_sheet_preview_keeps_preview_labels(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Preview Company', 'full_name' => 'Preview User']])
            ->get(route('sheets.sales'))->assertOk()->assertSee('Local sales preview')->assertSee('Demo sales sheet');
    }

    public function test_masters_always_loads_company_menu_assets_and_pins_the_rendered_book_context(): void
    {
        $owner = User::factory()->firm()->create();
        $book = Company::factory()->create();
        $owner->companies()->attach($book, ['role' => 'owner']);
        $this->profile($book);
        $urls = [route('masters.accounts', ['company_id' => $book->id])];
        foreach (['parties', 'employees', 'bank-accounts', 'tax-codes'] as $registry) {
            $urls[] = route('masters.registry', ['registry' => $registry, 'company_id' => $book->id]);
        }
        foreach ($urls as $url) {
            $response = $this->actingAs($owner)->get($url)->assertOk()
                ->assertSee('<meta name="tenant-company" content="'.$book->id.'">', false)
                ->assertSee('js/tenant-context.js')->assertSee('js/workspace-access.js');
            $this->assertSame(1, substr_count($response->getContent(), 'css/company-switcher.css'));
            $this->assertSame(1, substr_count($response->getContent(), 'js/company-switcher.js'));
            $this->assertSame(1, substr_count($response->getContent(), 'aria-label="Switch company book"'));
        }
    }

    public function test_dashboard_uses_current_company_standard_calendar_and_working_report_links(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00:00', 'Asia/Manila'));
        $owner = User::factory()->firm()->create();
        $book = Company::factory()->create();
        $owner->companies()->attach($book, ['role' => 'owner']);
        $this->profile($book);
        DB::table('tax_return_filing_records')->insert(['company_id' => $book->id, 'form' => '0619-E', 'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'filed_on' => '2026-11-10', 'created_at' => now(), 'updated_at' => now()]);
        $response = $this->actingAs($owner)->get(route('dashboard', ['company_id' => $book->id]))->assertOk()
            ->assertSee('Needs attention')->assertSee('Books flow')->assertSee('Income vs expenses')->assertSee('Standard dates from Tax Returns')->assertDontSee('Telemetry')->assertDontSee('Return generation and filing are not connected.');
        $events = collect($response->viewData('dashboardDeadlines'));
        $this->assertTrue($events->firstWhere('form', '0619-E')['filed']);
        $this->assertFalse($events->firstWhere('form', '1601-C')['filed']);
        $this->assertTrue($events->every(fn (array $event): bool => str_starts_with($event['due'], '2026-11') && str_contains($event['url'], 'company_id='.$book->id)));
        $this->assertDatabaseCount('company_ledgers', 0);
    }

    public function test_dashboard_does_not_expose_tax_filing_status_without_tax_module_access(): void
    {
        $staff = User::factory()->accountant()->create(['must_change_password' => false]);
        $book = Company::factory()->create();
        $book->users()->attach($staff, ['role' => 'staff', 'allowed_tabs' => json_encode(['dashboard', 'sheets'])]);
        $this->profile($book);
        $this->actingAs($staff)->get(route('dashboard', ['company_id' => $book->id]))->assertOk()->assertViewHas('dashboardDeadlines', []);
    }

    private function profile(Company $book): void
    {
        CompanySetting::factory()->create(['workspace_key' => $book->workspaceKey(), 'lookup_key' => $book->workspaceKey(), 'profile' => ['company_name' => $book->name, 'full_name' => 'Test owner', 'tin' => '123456789', 'vat_type' => 'vat']]);
    }
}
