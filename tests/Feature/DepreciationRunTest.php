<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\DepreciationRun;
use App\Models\PpeAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepreciationRunTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function workspace(string $name = 'Run QA'): array
    {
        return ['preview_access' => true, 'company_setup' => ['company_name' => $name, 'full_name' => 'Sample Owner', 'tin' => '']];
    }

    private function readyAsset(): PpeAsset
    {
        $workspace = $this->workspace();
        $key = ChartAccount::workspaceKey($workspace['company_setup']);
        $this->withSession($workspace);
        $asset = ChartAccount::factory()->create(['workspace_key' => $key, 'type' => 'Asset', 'normal_balance' => 'Debit', 'code' => '1501']);
        $accumulated = ChartAccount::factory()->create(['workspace_key' => $key, 'type' => 'Asset', 'normal_balance' => 'Credit', 'code' => '1591']);
        $expense = ChartAccount::factory()->create(['workspace_key' => $key, 'type' => 'Expense', 'normal_balance' => 'Debit', 'code' => '7199']);

        return PpeAsset::factory()->create(['workspace_key' => $key, 'acquired_date' => '2026-10-06', 'service_date' => '2026-10-06', 'cost_cents' => 10000, 'residual_cents' => 100, 'life_months' => 50, 'capitalization_reference' => 'OPEN-001', 'asset_account_id' => $asset->id, 'accumulated_account_id' => $accumulated->id, 'expense_account_id' => $expense->id]);
    }

    /** @return array<string, mixed> */
    private function preview(string $period = '2026-10'): array
    {
        return $this->getJson('/lapsing/data?period='.$period)->assertOk()->json();
    }

    public function test_asset_maintenance_exists_only_in_masters_with_connected_run_and_attention_pages(): void
    {
        $this->withSession($this->workspace());
        $this->get('/masters/ppe-items')->assertOk()->assertSee('New PPE item')->assertSee('Capitalization')->assertSee('ppe-items.js');
        $this->get('/lapsing')->assertOk()->assertSee('Post run')->assertSee(route('masters.ppe'))->assertSee('Working month')->assertSee('lapsing-period.js')->assertDontSee('Sheet navigation')->assertDontSee('Working period From')->assertDontSee('run-history')->assertDontSee('id="ppe-add"', false)->assertDontSee('id="ppe-form"', false);
        $this->get('/lapsing/attention')->assertOk()->assertSee('run-attention');
        $this->get('/lapsing/runs')->assertRedirect('/lapsing');
        $this->get('/sheets/lapsing')->assertRedirect('/lapsing');
        $this->postJson('/lapsing/assets', [])->assertNotFound();
    }

    public function test_monthly_posting_is_balanced_idempotent_and_available_to_books(): void
    {
        $asset = $this->readyAsset();
        $preview = $this->preview();
        $this->assertTrue($preview['ready']);
        $this->assertSame(198, $preview['rows'][0]['depreciation']);
        $this->assertSame(9802, $preview['rows'][0]['net']);
        $posted = $this->postJson('/lapsing/runs', ['period' => '2026-10', 'preview_hash' => $preview['preview_hash']])->assertCreated()->assertJsonPath('created', true)->json('run');
        $lines = $posted['journal']['lines'];
        $this->assertSame(198, array_sum(array_column($lines, 'debit')));
        $this->assertSame(array_sum(array_column($lines, 'debit')), array_sum(array_column($lines, 'credit')));
        $this->postJson('/lapsing/runs', ['period' => '2026-10', 'preview_hash' => $preview['preview_hash']])->assertOk()->assertJsonPath('created', false);
        $this->assertDatabaseCount('depreciation_runs', 1);
        $this->assertSame($asset->id, DepreciationRun::query()->first()->snapshot['rows'][0]['id']);
        $this->get('/masters/ppe-items')->assertOk()->assertViewHas('assets', function ($assets) use ($asset): bool {
            $record = $assets->find($asset->id);

            return $record->posted_net_cents === 9802 && $record->months_remaining === 49 && $record->basis_locked;
        });
        $this->get('/masters/chart-of-accounts/catalog.js')->assertOk()->assertSee('WorkspaceDepreciationJournals')->assertSee('DEP-2026-10');
        $this->assertFalse($this->preview()['ready']);
        $this->postJson('/masters/ppe-items/'.$asset->id.'/archive')->assertOk();
        $this->assertSame(198, $this->preview('2026-11')['total_cents']);
    }

    public function test_attention_blocks_uncapitalized_or_untagged_assets_without_creating_a_run(): void
    {
        $asset = $this->readyAsset();
        $asset->update(['capitalization_reference' => null, 'expense_account_id' => null]);
        $preview = $this->preview();
        $this->assertFalse($preview['ready']);
        $this->assertCount(2, $preview['attention']);
        $this->postJson('/lapsing/runs', ['period' => '2026-10', 'preview_hash' => $preview['preview_hash']])->assertUnprocessable();
        $this->assertDatabaseCount('depreciation_runs', 0);
    }

    public function test_missing_months_and_stale_previews_cannot_be_posted(): void
    {
        $asset = $this->readyAsset();
        $preview = $this->preview();
        $asset->update(['cost_cents' => 20000]);
        $this->postJson('/lapsing/runs', ['period' => '2026-10', 'preview_hash' => $preview['preview_hash']])->assertUnprocessable();
        $fresh = $this->preview();
        $this->postJson('/lapsing/runs', ['period' => '2026-10', 'preview_hash' => $fresh['preview_hash']])->assertCreated();
        $december = $this->preview('2026-12');
        $this->assertFalse($december['ready']);
        $this->postJson('/lapsing/runs', ['period' => '2026-12', 'preview_hash' => $december['preview_hash']])->assertUnprocessable();
        $november = $this->preview('2026-11');
        $this->postJson('/lapsing/runs', ['period' => '2026-11', 'preview_hash' => $november['preview_hash']])->assertCreated();
        $this->assertTrue($this->preview('2026-12')['ready']);
    }

    public function test_posted_asset_basis_is_locked_and_zero_depreciation_cannot_be_posted(): void
    {
        $asset = $this->readyAsset();
        $preview = $this->preview();
        $this->postJson('/lapsing/runs', ['period' => '2026-10', 'preview_hash' => $preview['preview_hash']])->assertCreated();
        $this->putJson('/masters/ppe-items/'.$asset->id, ['name' => $asset->name, 'category' => $asset->category, 'acquired_date' => $asset->acquired_date, 'service_date' => $asset->service_date, 'cost' => '200.00', 'residual' => '1.00', 'life_months' => 50])->assertUnprocessable();
        $this->assertSame(10000, $asset->fresh()->cost_cents);
        $this->assertSame(10000, $this->preview()['rows'][0]['cost_cents']);
        $this->assertFalse($this->preview('2032-01')['ready']);
    }

    public function test_run_data_is_company_scoped_and_period_inputs_are_validated(): void
    {
        $this->readyAsset();
        $preview = $this->preview();
        $this->postJson('/lapsing/runs', ['period' => '2026-10', 'preview_hash' => $preview['preview_hash']])->assertCreated();
        $this->withSession($this->workspace('Other Company'));
        $other = $this->preview();
        $this->assertSame([], $other['rows']);
        $this->assertSame([], $other['history']);
        $this->assertNull($other['run']);
        $this->getJson('/lapsing/data?period=2026-13')->assertUnprocessable();
        $this->postJson('/lapsing/runs', ['period' => '2026-10', 'preview_hash' => $other['preview_hash'], 'total_cents' => 1])->assertUnprocessable();
        $this->withSession(['preview_access' => false]);
        $this->getJson('/lapsing/data?period=2026-10')->assertForbidden();
    }
}
