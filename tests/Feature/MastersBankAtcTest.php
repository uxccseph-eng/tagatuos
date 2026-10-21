<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\ChartAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MastersBankAtcTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function workspace(string $name = 'Bank QA'): array
    {
        return ['preview_access' => true, 'company_setup' => ['company_name' => $name, 'full_name' => 'Preview', 'tin' => '']];
    }

    private function cashAccount(): ChartAccount
    {
        $key = ChartAccount::workspaceKey($this->workspace()['company_setup']);
        ChartAccount::seedWorkspace($key);

        return ChartAccount::query()->where('workspace_key', $key)->where('code', '1020')->firstOrFail();
    }

    public function test_bank_and_atc_pages_require_setup_and_use_shared_masters_navigation(): void
    {
        $this->get('/masters/atc-codes')->assertRedirect('/signin');
        $this->withSession(['preview_access' => true])->get('/masters/bank-accounts')->assertRedirect('/company/setup');
        $this->withSession($this->workspace())->get('/masters/bank-accounts')->assertOk()->assertSee('New bank account')->assertSee('GL Account')->assertSee('/masters/atc-codes');
        $this->assertDatabaseCount('bank_accounts', 0);
        $response = $this->get('/masters/atc-codes')->assertOk()->assertDontSee('id="registry-new"', false)->assertSee('WB030')->assertSee('WV070');
        $this->assertCount(172, $response->viewData('records'));
        $this->assertCount(172, array_unique(array_column($response->viewData('records'), 'code')));
        $this->postJson('/masters/atc-codes', [])->assertStatus(405);
        $this->putJson('/masters/atc-codes/1', [])->assertNotFound();
    }

    public function test_bank_crud_keeps_gl_link_and_retains_history_after_archiving(): void
    {
        $account = $this->cashAccount();
        $payload = ['name' => 'Operating Bank', 'bank' => 'Sample Bank', 'account_number' => '000-1234', 'chart_account_id' => $account->id, 'currency' => 'PHP'];
        $this->withSession($this->workspace())->postJson('/masters/bank-accounts', $payload)->assertCreated()->assertJsonPath('record.gl_account', '1020 · Cash in Bank');
        $bank = BankAccount::query()->firstOrFail();
        $this->putJson('/masters/bank-accounts/'.$bank->id, [...$payload, 'name' => 'Updated Bank'])->assertOk()->assertJsonPath('record.name', 'Updated Bank');
        $this->postJson('/masters/bank-accounts/'.$bank->id.'/archive')->assertOk()->assertJsonPath('record.archived', true);
        $this->assertDatabaseHas('bank_accounts', ['id' => $bank->id, 'account_number' => '000-1234']);
        $this->postJson('/masters/bank-accounts/'.$bank->id.'/restore')->assertOk()->assertJsonPath('record.archived', false);
        $account->update(['archived' => true]);
        $this->putJson('/masters/bank-accounts/'.$bank->id, [...$payload, 'name' => 'Retained link'])->assertOk();
        $this->postJson('/masters/bank-accounts', [...$payload, 'name' => 'New bank'])->assertUnprocessable()->assertJsonValidationErrors('chart_account_id');
    }

    public function test_bank_validation_and_company_isolation_reject_foreign_or_non_cash_gl_accounts(): void
    {
        $account = $this->cashAccount();
        $payload = ['name' => 'Main Bank', 'chart_account_id' => $account->id, 'currency' => 'PHP'];
        $this->withSession($this->workspace())->postJson('/masters/bank-accounts', $payload)->assertCreated();
        $bank = BankAccount::query()->firstOrFail();
        $this->postJson('/masters/bank-accounts', $payload)->assertUnprocessable()->assertJsonValidationErrors('name');
        $nonCash = ChartAccount::factory()->create(['workspace_key' => $account->workspace_key, 'is_cash_bank' => false]);
        $this->postJson('/masters/bank-accounts', [...$payload, 'name' => 'Bad', 'chart_account_id' => $nonCash->id, 'currency' => 'LONG'])->assertUnprocessable()->assertJsonValidationErrors(['chart_account_id', 'currency']);
        $this->withSession($this->workspace('Other Company'))->postJson('/masters/bank-accounts', $payload)->assertUnprocessable()->assertJsonValidationErrors('chart_account_id');
        $this->putJson('/masters/bank-accounts/'.$bank->id, $payload)->assertNotFound();
        $this->postJson('/masters/bank-accounts/'.$bank->id.'/archive')->assertNotFound();
        $this->postJson('/masters/bank-accounts/'.$bank->id.'/restore')->assertNotFound();
        $this->get('/masters/bank-accounts')->assertDontSee('Main Bank');
    }
}
