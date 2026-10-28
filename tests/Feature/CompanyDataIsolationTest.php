<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\DepreciationRun;
use App\Models\Employee;
use App\Models\Party;
use App\Models\PpeAsset;
use App\Models\TaxCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class CompanyDataIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_scope_is_unique_per_book_immutable_and_independent_of_application_key(): void
    {
        $first = Company::factory()->create(['name' => 'Same Name']);
        $second = Company::factory()->create(['name' => 'Same Name']);
        $key = $first->workspaceKey();
        $this->assertNotSame($key, $second->workspaceKey());
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertSame($key, $first->fresh()->workspaceKey());
        $first->update(['name' => 'Renamed']);
        $this->assertSame($key, $first->fresh()->workspaceKey());
        $this->expectException(LogicException::class);
        $first->forceFill(['workspace_key' => $second->workspaceKey()])->save();
    }

    public function test_guest_preview_and_forged_preview_sessions_cannot_reach_production_data_routes(): void
    {
        $this->app->instance('env', 'production');
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Forged'], '_token' => 'valid-test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'valid-test-token']);
        $this->postJson(route('preview.start'), ['method' => 'google'])->assertNotFound();
        foreach (['/masters/parties', '/masters/chart-of-accounts/catalog.js', '/lapsing/data', '/settings'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        foreach (['/masters/parties', '/lapsing/runs', '/reports/generate-qap-dat', '/tax-reports/relief-sales/excel'] as $path) {
            $this->postJson($path, [])->assertUnauthorized();
        }
        $this->assertDatabaseCount('parties', 0);
        $this->assertDatabaseCount('chart_accounts', 0);
    }

    public function test_production_gate_preserves_authenticated_company_access(): void
    {
        [$user, $own] = $this->books();
        $this->app->instance('env', 'production');
        $this->actingAs($user)->withSession(['active_company_id' => $own->id])
            ->get(route('masters.accounts'))->assertOk()->assertSee($own->name);
    }

    public function test_company_switching_isolates_all_masters_and_journal_catalogs(): void
    {
        [$user, $own, $other] = $this->books();
        foreach ([$own, $other] as $index => $company) {
            $prefix = $index === 0 ? 'FIRST' : 'SECOND';
            ChartAccount::factory()->create(['workspace_key' => $company->workspaceKey(), 'code' => '8888', 'name' => $prefix.' Account']);
            Party::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => $prefix.' Party']);
            Employee::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => $prefix.' Employee']);
            TaxCode::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => $prefix.' Tax']);
            PpeAsset::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => $prefix.' Asset']);
            DepreciationRun::factory()->create(['workspace_key' => $company->workspaceKey(), 'journal' => ['source' => $prefix.' JOURNAL']]);
        }
        $this->actingAs($user)->withSession(['active_company_id' => $own->id]);
        foreach ([['parties', 'Party'], ['employees', 'Employee'], ['tax-codes', 'Tax']] as [$registry, $label]) {
            $this->get(route('masters.registry', $registry))->assertOk()->assertSee('FIRST '.$label)->assertDontSee('SECOND '.$label);
        }
        $this->get(route('masters.accounts'))->assertOk()->assertSee('FIRST Account')->assertDontSee('SECOND Account');
        $this->get(route('masters.ppe'))->assertOk()->assertSee('FIRST Asset')->assertDontSee('SECOND Asset');
        $this->get(route('masters.accounts.catalog'))->assertOk()->assertSee('FIRST JOURNAL')->assertDontSee('SECOND JOURNAL');
        $this->post(route('account.companies.open', $other->id))->assertRedirect(route('dashboard', ['company_id' => $other->id]));
        $this->get(route('masters.accounts.catalog'))->assertOk()->assertSee('SECOND Party')->assertDontSee('FIRST Party')->assertSee('SECOND JOURNAL')->assertDontSee('FIRST JOURNAL');
    }

    public function test_foreign_record_ids_cannot_be_archived_or_updated(): void
    {
        [$user, $own, $foreign] = $this->books();
        $workspace = $foreign->workspaceKey();
        $party = Party::factory()->create(['workspace_key' => $workspace]);
        $employee = Employee::factory()->create(['workspace_key' => $workspace]);
        $tax = TaxCode::factory()->create(['workspace_key' => $workspace]);
        $account = ChartAccount::factory()->create(['workspace_key' => $workspace]);
        $bank = BankAccount::factory()->create(['workspace_key' => $workspace, 'chart_account_id' => $account->id]);
        $asset = PpeAsset::factory()->create(['workspace_key' => $workspace]);
        $this->actingAs($user)->withSession(['active_company_id' => $own->id]);
        foreach ([['parties', $party], ['employees', $employee], ['tax-codes', $tax], ['bank-accounts', $bank]] as [$registry, $record]) {
            $this->postJson(route('masters.registry.archive', ['registry' => $registry, 'record' => $record->id]))->assertNotFound();
            $this->putJson(route('masters.registry.update', ['registry' => $registry, 'record' => $record->id]), [])->assertNotFound();
            $this->assertFalse($record->fresh()->archived);
        }
        $this->postJson(route('masters.accounts.archive', $account->id))->assertNotFound();
        $this->postJson(route('masters.ppe.archive', $asset->id))->assertNotFound();
        $this->assertFalse($account->fresh()->archived);
        $this->assertFalse($asset->fresh()->archived);
    }

    public function test_browser_cannot_override_company_scope_when_creating_a_record(): void
    {
        [$user, $own, $foreign] = $this->books();
        $this->actingAs($user)->withSession(['active_company_id' => $own->id])
            ->postJson(route('masters.registry.store', 'employees'), [
                'code' => 'EMP-NEW', 'name' => 'Scoped Employee', 'classification' => 'Regular',
                'workspace_key' => $foreign->workspaceKey(), 'company_id' => $foreign->id,
            ])->assertConflict();
        $this->assertDatabaseMissing('employees', ['name' => 'Scoped Employee', 'workspace_key' => $own->workspaceKey()]);
        $this->assertDatabaseMissing('employees', ['name' => 'Scoped Employee', 'workspace_key' => $foreign->workspaceKey()]);
    }

    public function test_bank_relationships_reject_accounts_from_a_different_company(): void
    {
        [$user, $own, $foreign] = $this->books();
        $account = ChartAccount::factory()->create(['workspace_key' => $foreign->workspaceKey(), 'is_cash_bank' => true]);
        $this->actingAs($user)->withSession(['active_company_id' => $own->id])
            ->postJson(route('masters.registry.store', 'bank-accounts'), ['name' => 'Invalid Bank', 'currency' => 'PHP', 'chart_account_id' => $account->id])->assertUnprocessable()->assertJsonValidationErrors('chart_account_id');
        $this->assertDatabaseCount('bank_accounts', 0);
    }

    public function test_restricted_catalog_returns_choices_without_bank_numbers_or_hidden_journals(): void
    {
        [, $company] = $this->books();
        $staff = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($staff, ['role' => 'staff', 'allowed_tabs' => json_encode(['dashboard', 'sheets'])]);
        $account = ChartAccount::factory()->create(['workspace_key' => $company->workspaceKey(), 'is_cash_bank' => true]);
        BankAccount::factory()->create(['workspace_key' => $company->workspaceKey(), 'chart_account_id' => $account->id, 'name' => 'Selectable Bank', 'account_number' => 'PRIVATE-ACCOUNT-NUMBER']);
        DepreciationRun::factory()->create(['workspace_key' => $company->workspaceKey(), 'journal' => ['source' => 'PRIVATE-DEPRECIATION-JOURNAL']]);
        $this->actingAs($staff)->withSession(['active_company_id' => $company->id])
            ->get(route('masters.accounts.catalog'))->assertOk()->assertSee('Selectable Bank')->assertDontSee('PRIVATE-ACCOUNT-NUMBER')->assertDontSee('PRIVATE-DEPRECIATION-JOURNAL');
        $company->users()->updateExistingPivot($staff->id, ['allowed_tabs' => json_encode(['dashboard'])]);
        $this->get(route('masters.accounts.catalog'))->assertForbidden();
    }

    public function test_explicit_tabs_keep_their_own_company_when_the_session_preference_changes(): void
    {
        [$user, $first, $second] = $this->books();
        $this->actingAs($user)->withSession(['active_company_id' => $second->id]);
        $this->withHeader('X-Company-ID', (string) $first->id)->postJson(route('masters.registry.store', 'employees'), ['name' => 'First Book Employee'])->assertCreated();
        $this->getJson(route('masters.accounts.catalog'))->assertOk();
        $this->assertDatabaseHas('employees', ['name' => 'First Book Employee', 'company_id' => $first->id]);
        $this->assertDatabaseMissing('employees', ['name' => 'First Book Employee', 'company_id' => $second->id]);
        $this->withHeader('X-Company-ID', (string) $second->id)->get(route('masters.accounts.catalog'))->assertOk();
    }

    public function test_export_and_pdf_routes_reject_an_unassigned_active_company(): void
    {
        [$user, , $other] = $this->books();
        $other->users()->detach($user);
        $this->actingAs($user)->withSession(['active_company_id' => $other->id, 'preview_access' => true]);
        foreach (['reports.sales.dat', 'reports.purchases.dat', 'reports.sawt.dat', 'reports.qap.dat', 'reports.qap.annual.dat'] as $route) {
            $this->postJson(route($route), [])->assertForbidden();
        }
        $this->postJson(route('reports.form2307', 1), [])->assertForbidden();
        $this->postJson(route('reports.form2316', 1), [])->assertForbidden();
    }

    public function test_import_preview_cannot_be_reused_after_switching_companies(): void
    {
        [$user, $first, $second] = $this->books();
        $rows = [['name' => 'Imported Customer', 'classification' => 'Customer', 'address' => '10 Example Street', 'tin' => '000-000-000-00000']];
        $this->actingAs($user)->withSession(['active_company_id' => $first->id]);
        $preview = $this->postJson(route('masters.import.preview', 'parties'), ['records' => $rows])->assertOk();
        $this->assertSame([], $preview->json('errors'));
        $this->post(route('account.companies.open', $second->id))->assertRedirect();
        $this->postJson(route('masters.registry.import', 'parties'), ['records' => $rows, 'preview_token' => $preview->json('preview_token')])->assertUnprocessable();
        $this->assertDatabaseMissing('parties', ['name' => 'Imported Customer']);
    }

    public function test_identical_master_codes_names_and_tins_are_independent_between_companies(): void
    {
        [$user, $first, $second] = $this->books();
        foreach ([$first, $second] as $company) {
            $this->actingAs($user)->withSession(['active_company_id' => $company->id])
                ->postJson(route('masters.registry.store', 'employees'), ['code' => 'EMP-001', 'name' => 'Same Employee', 'classification' => 'Regular', 'tin' => '123-456-789-00000'])->assertCreated();
        }
        $this->assertSame(2, Employee::query()->where('code', 'EMP-001')->count());
        $this->assertDatabaseHas('employees', ['code' => 'EMP-001', 'workspace_key' => $first->workspaceKey()]);
        $this->assertDatabaseHas('employees', ['code' => 'EMP-001', 'workspace_key' => $second->workspaceKey()]);
    }

    /** @return array{User, Company, Company} */
    private function books(): array
    {
        $user = User::factory()->firm()->create();
        $companies = [];
        foreach (['First Company', 'Second Company'] as $name) {
            $company = Company::factory()->create(['name' => $name]);
            $company->users()->attach($user, ['role' => 'owner']);
            CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => $name, 'full_name' => 'Example Owner', 'tin' => '000000000', 'branch_code' => '00000', 'vat_type' => 'non_vat']]);
            $companies[] = $company;
        }

        return [$user, ...$companies];
    }
}
