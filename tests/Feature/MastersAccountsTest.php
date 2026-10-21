<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MastersAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(string $name = 'Test Studio'): array
    {
        return ['preview_access' => true, 'company_setup' => ['company_name' => $name, 'full_name' => 'Demo Owner', 'tin' => '123-456-789']];
    }

    private function payload(): array
    {
        return ['code' => '7160', 'name' => 'Delivery Expense', 'type' => 'Expense', 'normal_balance' => 'Debit', 'statement_section' => 'General and Administrative Expenses', 'is_cash_bank' => false, 'is_advance' => false, 'is_control' => false, 'subsidiary' => null, 'ppe_role' => null, 'ppe_category' => null, 'relief' => false, 'percentage_tax' => false, 'ewt' => true];
    }

    public function test_masters_requires_preview_and_company_and_seeds_each_workspace_once(): void
    {
        $this->get('/masters/chart-of-accounts')->assertRedirect('/signin');
        $this->withSession(['preview_access' => true])->get('/masters/chart-of-accounts')->assertRedirect('/company/setup');
        $this->withSession($this->workspace())->get('/masters/chart-of-accounts')->assertOk()->assertViewIs('masters')->assertSee('Chart of Accounts')->assertSee('New account');
        $count = count(ChartAccount::defaults());
        $this->assertDatabaseCount('chart_accounts', $count);
        $this->get('/masters/chart-of-accounts')->assertOk();
        $this->assertDatabaseCount('chart_accounts', $count);
        $this->assertDatabaseHas('chart_accounts', ['code' => '2110', 'type' => 'Asset', 'name' => 'Input VAT']);
    }

    public function test_accounts_persist_and_edit_archive_restore_without_changing_their_codes(): void
    {
        $this->withSession($this->workspace())->postJson('/masters/chart-of-accounts', $this->payload())->assertCreated()->assertJsonPath('account.code', '7160');
        $account = ChartAccount::query()->where('code', '7160')->firstOrFail();
        $this->putJson('/masters/chart-of-accounts/'.$account->id, [...$this->payload(), 'name' => 'Freight and Delivery'])->assertOk()->assertJsonPath('account.name', 'Freight and Delivery');
        $this->putJson('/masters/chart-of-accounts/'.$account->id, [...$this->payload(), 'code' => '7161'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/masters/chart-of-accounts/'.$account->id.'/archive')->assertOk()->assertJsonPath('account.archived', true);
        $this->assertDatabaseHas('chart_accounts', ['id' => $account->id, 'name' => 'Freight and Delivery', 'archived' => true]);
        $this->get('/masters/chart-of-accounts/catalog.js')->assertOk()->assertSee('Freight and Delivery')->assertSee('window.WorkspaceAccounts');
        $this->postJson('/masters/chart-of-accounts/'.$account->id.'/restore')->assertOk()->assertJsonPath('account.archived', false);
        $this->withSession(['preview_access' => true, 'company_setup' => $this->workspace()['company_setup'], 'different_session_marker' => true])->get('/masters/chart-of-accounts')->assertSee('Freight and Delivery');
    }

    public function test_validation_prevents_duplicate_codes_including_archived_codes_and_invalid_flags(): void
    {
        $this->withSession($this->workspace())->postJson('/masters/chart-of-accounts', $this->payload())->assertCreated();
        $this->postJson('/masters/chart-of-accounts', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/masters/chart-of-accounts', [...$this->payload(), 'code' => '1010'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/masters/chart-of-accounts', [...$this->payload(), 'code' => 'bad', 'type' => 'Bad', 'normal_balance' => 'Bad', 'is_cash_bank' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors(['code', 'type', 'normal_balance', 'is_cash_bank']);
        $this->postJson('/masters/chart-of-accounts', [...$this->payload(), 'code' => '7161', 'is_control' => true])->assertUnprocessable()->assertJsonValidationErrors('subsidiary');
        $this->postJson('/masters/chart-of-accounts', [...$this->payload(), 'code' => '7161', 'is_cash_bank' => true])->assertUnprocessable()->assertJsonValidationErrors('is_cash_bank');
        $account = ChartAccount::query()->where('code', '7160')->firstOrFail();
        $this->postJson('/masters/chart-of-accounts/'.$account->id.'/archive')->assertOk();
        $this->postJson('/masters/chart-of-accounts', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_workspaces_cannot_edit_archive_restore_or_read_another_companys_accounts(): void
    {
        $this->withSession($this->workspace())->postJson('/masters/chart-of-accounts', $this->payload())->assertCreated();
        $account = ChartAccount::query()->where('code', '7160')->firstOrFail();
        $this->withSession($this->workspace('Another Company'))->putJson('/masters/chart-of-accounts/'.$account->id, $this->payload())->assertNotFound();
        $this->postJson('/masters/chart-of-accounts/'.$account->id.'/archive')->assertNotFound();
        $this->postJson('/masters/chart-of-accounts/'.$account->id.'/restore')->assertNotFound();
        $this->get('/masters/chart-of-accounts/catalog.js')->assertDontSee('Delivery Expense');
        $this->postJson('/masters/chart-of-accounts', $this->payload())->assertCreated();
        $this->assertSame(2, ChartAccount::query()->where('code', '7160')->count());
    }

    public function test_catalog_safely_encodes_names_and_writes_require_csrf(): void
    {
        $this->withSession($this->workspace())->postJson('/masters/chart-of-accounts', [...$this->payload(), 'name' => '</script><script>alert(1)</script>'])->assertCreated();
        $this->get('/masters/chart-of-accounts/catalog.js')->assertHeader('Content-Type', 'application/javascript')->assertDontSee('</script>', false);
        $this->get('/masters/chart-of-accounts')->assertDontSee('<script>alert(1)</script>', false);
        $this->app->instance('env', 'production');
        $this->postJson('/masters/chart-of-accounts', [...$this->payload(), 'code' => '7161'])->assertStatus(419);
    }

    public function test_editor_details_persist_on_create_and_update_and_validate_allowed_values(): void
    {
        $details = ['description' => 'Operating account notes', 'allow_subsidiaries' => true, 'subsidiary' => 'Non-trade Supplier', 'cash_flow_section' => 'Operating', 'cash_flow_subclass' => 'expense', 'relief_category' => 'services', 'ewt_atc_code' => 'WI100'];
        $this->withSession($this->workspace())->postJson('/masters/chart-of-accounts', [...$this->payload(), ...$details])->assertCreated()->assertJsonPath('account.description', $details['description'])->assertJsonPath('account.allow_subsidiaries', true);
        $account = ChartAccount::query()->where('code', '7160')->firstOrFail();
        $this->assertDatabaseHas('chart_accounts', ['id' => $account->id, ...$details]);
        $this->putJson('/masters/chart-of-accounts/'.$account->id, [...$this->payload(), ...$details, 'description' => 'Updated notes', 'cash_flow_section' => 'Investing'])->assertOk()->assertJsonPath('account.cash_flow_section', 'Investing');
        $this->get('/masters/chart-of-accounts')->assertSee('Updated notes')->assertSee('Posting behavior')->assertSee('Financial statement mapping');
        $this->putJson('/masters/chart-of-accounts/'.$account->id, [...$this->payload(), ...$details, 'cash_flow_section' => 'Invalid', 'relief_category' => 'Invalid', 'ewt_atc_code' => 'script'])->assertUnprocessable()->assertJsonValidationErrors(['cash_flow_section', 'relief_category', 'ewt_atc_code']);
        $cleared = array_fill_keys(['description', 'cash_flow_section', 'cash_flow_subclass', 'relief_category', 'ewt_atc_code'], null);
        $this->putJson('/masters/chart-of-accounts/'.$account->id, [...$this->payload(), ...$cleared, 'allow_subsidiaries' => false])->assertOk()->assertJsonPath('account.description', null)->assertJsonPath('account.allow_subsidiaries', false);
    }

    public function test_ewt_flag_and_atc_tag_remain_consistent_when_creating_and_editing(): void
    {
        $this->withSession($this->workspace());
        $created = $this->postJson('/masters/chart-of-accounts', [...$this->payload(), 'ewt_atc_code' => ' wi100 '])->assertCreated()->assertJsonPath('account.ewt', true)->assertJsonPath('account.ewt_atc_code', 'WI100');
        $id = $created->json('account.id');
        $this->putJson('/masters/chart-of-accounts/'.$id, [...$this->payload(), 'ewt' => false, 'ewt_atc_code' => 'WI100'])->assertOk()->assertJsonPath('account.ewt', false)->assertJsonPath('account.ewt_atc_code', null);
        $this->assertDatabaseHas('chart_accounts', ['id' => $id, 'ewt' => false, 'ewt_atc_code' => null]);
        $this->postJson('/masters/chart-of-accounts', [...$this->payload(), 'code' => '7161', 'ewt' => false, 'ewt_atc_code' => 'WI100'])->assertCreated()->assertJsonPath('account.ewt_atc_code', null);
        $this->putJson('/masters/chart-of-accounts/'.$id, [...$this->payload(), 'ewt_atc_code' => 'WI100'])->assertOk()->assertJsonPath('account.ewt', true)->assertJsonPath('account.ewt_atc_code', 'WI100');
    }
}
