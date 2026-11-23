<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\PpeAsset;
use App\Models\User;
use App\PpeDepreciation;
use App\Services\CompanyLedgerService;
use App\Services\PurchasePpeDrafts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchasePpeDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_posted_vat_asset_purchase_creates_one_pending_asset_at_net_capitalized_cost(): void
    {
        [$company,$owner] = $this->book();
        $account = ChartAccount::factory()->create(['workspace_key' => $company->workspaceKey(), 'code' => '1501', 'name' => 'Furniture and Fixtures', 'type' => 'Asset', 'normal_balance' => 'Debit', 'ppe_role' => 'Asset']);
        $state = CompanyLedgerService::emptyState();
        $bill = ['id' => 'furniture', 'number' => 'BILL-000001', 'date' => '2026-06-24', 'status' => 'posted', 'supplier' => 'Supplier', 'gross' => 13451500, 'tax' => 'VAT (12%)', 'account' => '1501', 'ewt' => 0, 'wvat' => 0, 'terms' => 'On Account', 'bank' => '1010'];
        $state['purchases'] = [$bill];
        $saved = app(CompanyLedgerService::class)->save($company, $owner, ['revision' => 0, 'request_id' => (string) Str::uuid(), 'state' => $state, 'payroll' => []]);
        $asset = $company->run(fn () => PpeAsset::query()->sole());
        $this->assertSame(12010268, $asset->cost_cents);
        $this->assertTrue($asset->setup_pending);
        $this->assertNull($asset->service_date);
        $this->assertNull($asset->life_months);
        $this->assertSame($account->id, $asset->asset_account_id);
        $this->assertSame(0, app(PpeDepreciation::class)->accumulated($asset, '2026-12-31'));
        $saved = app(CompanyLedgerService::class)->save($company, $owner, ['revision' => 1, 'request_id' => (string) Str::uuid(), 'state' => $saved['state'], 'payroll' => []]);
        $this->assertDatabaseCount('ppe_assets', 1);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->putJson(route('masters.ppe.update', ['asset' => $asset->id, 'company_id' => $company->id]), ['code' => $asset->code, 'name' => $asset->name, 'category' => $asset->category, 'acquired_date' => $asset->acquired_date, 'service_date' => '2026-07-01', 'life_months' => 36, 'cost' => '120102.68', 'residual' => '0.00', 'asset_account_id' => $account->id, 'capitalization_reference' => 'BILL-000001'])->assertOk()->assertJsonPath('record.setup_pending', false);
        $confirmed = $company->run(fn () => PpeAsset::query()->sole());
        $this->assertGreaterThan(0, app(PpeDepreciation::class)->accumulated($confirmed, '2026-07-31'));
        $saved['state']['purchases'][0]['status'] = 'void';
        $saved['state']['purchases'][0]['voidReason'] = 'Wrong furniture purchase';
        app(CompanyLedgerService::class)->save($company, $owner, ['revision' => 2, 'request_id' => (string) Str::uuid(), 'state' => $saved['state'], 'payroll' => []]);
        $this->assertTrue($company->run(fn () => PpeAsset::query()->sole())->source_review_required);
    }

    public function test_authenticated_ppe_register_uses_its_own_data_without_sheet_save_recovery(): void
    {
        [$company, $owner] = $this->book();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->get('/masters/ppe-items?company_id='.$company->id)
            ->assertOk()
            ->assertSee('id="ppe-data"', false)
            ->assertSee('ppe-items.js')
            ->assertSee('working-period.js')
            ->assertDontSee('company-ledger-sync.js');

        foreach (['/lapsing', '/lapsing/attention'] as $path) {
            $this->get($path.'?company_id='.$company->id)
                ->assertOk()
                ->assertSee('lapsing.js')
                ->assertDontSee('company-ledger-sync.js');
        }

        $this->get('/sheets/sales?company_id='.$company->id)
            ->assertOk()
            ->assertSee('company-ledger-sync.js');
    }

    public function test_drafts_and_ordinary_current_assets_do_not_create_ppe(): void
    {
        [$company,$owner] = $this->book();
        $state = CompanyLedgerService::emptyState();
        $state['purchases'] = [['id' => 'draft', 'number' => '', 'date' => '2026-06-24', 'status' => 'draft', 'account' => '1500']];
        app(CompanyLedgerService::class)->save($company, $owner, ['revision' => 0, 'request_id' => (string) Str::uuid(), 'state' => $state, 'payroll' => []]);
        $this->assertDatabaseCount('ppe_assets', 0);
        $state['purchases'] = [['id' => 'ordinary', 'number' => 'BILL-000001', 'date' => '2026-06-24', 'status' => 'posted', 'supplier' => 'Supplier', 'gross' => 112000, 'tax' => 'VAT (12%)', 'account' => '2110', 'ewt' => 0, 'wvat' => 0, 'terms' => 'On Account', 'bank' => '1010']];
        $company->run(fn () => app(PurchasePpeDrafts::class)->synchronize($company, $state));
        $this->assertDatabaseCount('ppe_assets', 0);
    }

    /** @return array{Company, User} */
    private function book(): array
    {
        $company = Company::factory()->create();
        $owner = User::factory()->firm()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey()]);
        ChartAccount::seedWorkspace($company->workspaceKey());
        Party::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => 'Supplier', 'classification' => 'Supplier']);
        Party::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => 'Customer', 'classification' => 'Customer']);

        return [$company, $owner];
    }
}
