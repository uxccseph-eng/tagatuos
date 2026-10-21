<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\PpeAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LapsingPageTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function workspace(string $name = 'PPE QA'): array
    {
        return ['preview_access' => true, 'company_setup' => ['company_name' => $name, 'full_name' => 'Sample Owner', 'tin' => '', 'accounting_period' => 'Calendar year', 'vat_type' => 'Non-VAT', 'branch_code' => '00000', 'rdo' => '']];
    }

    /** @return array<string, mixed> */
    private function asset(): array
    {
        return ['name' => 'Office computer', 'category' => 'Office equipment', 'acquired_date' => '2026-01-10', 'service_date' => '2026-01-15', 'cost' => '12000.29', 'residual' => '1200.01', 'life_months' => 36];
    }

    public function test_lapsing_requires_company_and_renders_an_empty_register(): void
    {
        $this->get('/lapsing')->assertRedirect('/signin');
        $this->withSession(['preview_access' => true])->get('/lapsing')->assertRedirect('/company/setup');
        $this->withSession($this->workspace())->get('/lapsing')->assertOk()->assertSee('Post run')->assertSee('Working month')->assertSee('Dep this month')->assertSee('ppe-schedule.js')->assertDontSee('masters-registry.js');
        $this->assertDatabaseCount('ppe_assets', 0);
    }

    public function test_asset_create_edit_archive_and_restore_preserve_exact_cents(): void
    {
        $this->withSession($this->workspace());
        $record = $this->postJson('/masters/ppe-items', $this->asset())->assertCreated()->assertJsonPath('record.code', 'PPE-000001')->assertJsonPath('record.cost_cents', 1200029)->assertJsonPath('record.residual_cents', 120001)->json('record');
        $this->putJson('/masters/ppe-items/'.$record['id'], [...$this->asset(), 'name' => 'Updated asset'])->assertOk()->assertJsonPath('record.code', 'PPE-000001');
        $this->postJson('/masters/ppe-items/'.$record['id'].'/archive')->assertOk()->assertJsonPath('record.archived', true);
        $this->postJson('/masters/ppe-items/'.$record['id'].'/archive')->assertOk()->assertJsonPath('record.archived', false);
        $this->get('/masters/ppe-items')->assertOk()->assertSee('Updated asset');
    }

    public function test_asset_validation_blocks_bad_dates_values_duplicate_codes_and_serials(): void
    {
        $this->withSession($this->workspace());
        $this->postJson('/masters/ppe-items', [...$this->asset(), 'code' => 'PC-1', 'serial_number' => 'SERIAL-1'])->assertCreated();
        $this->postJson('/masters/ppe-items', [...$this->asset(), 'code' => 'pc-1'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/masters/ppe-items', [...$this->asset(), 'serial_number' => 'SERIAL-1'])->assertUnprocessable()->assertJsonValidationErrors('serial_number');
        foreach ([['cost' => '-1'], ['cost' => '1.005'], ['residual' => '13000'], ['life_months' => 0], ['service_date' => '2025-01-01'], ['disposal_date' => '2025-12-31']] as $invalid) {
            $this->postJson('/masters/ppe-items', [...$this->asset(), ...$invalid])->assertUnprocessable();
        }
        $this->assertDatabaseCount('ppe_assets', 1);
    }

    public function test_assets_and_account_links_are_scoped_to_the_company(): void
    {
        $workspace = $this->workspace();
        $key = ChartAccount::workspaceKey($workspace['company_setup']);
        $asset = PpeAsset::factory()->create(['workspace_key' => $key]);
        $account = ChartAccount::factory()->create(['workspace_key' => $key, 'type' => 'Asset']);
        $this->withSession($this->workspace('Other PPE Company'));
        $this->get('/lapsing')->assertOk()->assertDontSee($asset->name);
        $this->putJson('/masters/ppe-items/'.$asset->id, $this->asset())->assertNotFound();
        $this->postJson('/masters/ppe-items/'.$asset->id.'/archive')->assertNotFound();
        $this->postJson('/masters/ppe-items', [...$this->asset(), 'asset_account_id' => $account->id])->assertUnprocessable()->assertJsonValidationErrors('asset_account_id');
    }

    public function test_sheet_and_masters_navigation_links_to_lapsing(): void
    {
        $this->withSession($this->workspace());
        foreach (['/sheets/sales', '/sheets/payroll', '/masters/chart-of-accounts', '/dashboard'] as $url) {
            $this->get($url)->assertOk()->assertSee('href="'.route('lapsing').'"', false);
        }
    }

    public function test_existing_archived_account_links_can_be_kept_but_not_newly_selected(): void
    {
        $workspace = $this->workspace();
        $key = ChartAccount::workspaceKey($workspace['company_setup']);
        $account = ChartAccount::factory()->create(['workspace_key' => $key, 'type' => 'Asset', 'archived' => true]);
        $asset = PpeAsset::factory()->create(['workspace_key' => $key, 'asset_account_id' => $account->id]);
        $this->withSession($workspace);
        $this->putJson('/masters/ppe-items/'.$asset->id, [...$this->asset(), 'asset_account_id' => $account->id])->assertOk();
        $this->postJson('/masters/ppe-items', [...$this->asset(), 'asset_account_id' => $account->id])->assertUnprocessable();
    }
}
