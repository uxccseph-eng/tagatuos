<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PettyCashPartyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Petty Cash QA', 'full_name' => 'Test accountant']]);
    }

    public function test_default_petty_cash_payee_is_available_once_with_no_fabricated_tin(): void
    {
        $this->get('/masters/parties')->assertOk()->assertSee('Petty cash / miscellaneous payee');
        $this->get('/masters/parties')->assertOk();
        $party = Party::query()->where('code', 'PETTY-CASH')->sole();
        $this->assertTrue($party->is_petty_cash);
        $this->assertNull($party->tin);
        $this->get('/masters/chart-of-accounts/catalog.js')->assertOk()->assertSee('PETTY CASH')->assertSee('"is_petty_cash":true', false);
    }

    public function test_petty_cash_supplier_can_be_saved_without_tin_and_reverted_only_with_required_details(): void
    {
        $response = $this->postJson('/masters/parties', ['name' => 'Miscellaneous transport payees', 'classification' => 'Supplier', 'is_petty_cash' => '1', 'tin' => '', 'address' => ''])
            ->assertCreated()->assertJsonPath('record.is_petty_cash', true)->assertJsonPath('record.tin', null);
        $id = $response->json('record.id');
        $this->putJson('/masters/parties/'.$id, ['code' => $response->json('record.code'), 'name' => 'Miscellaneous transport payees', 'classification' => 'Supplier', 'is_petty_cash' => '0'])
            ->assertUnprocessable()->assertJsonValidationErrors(['tin', 'address']);
        $this->assertTrue(Party::query()->findOrFail($id)->is_petty_cash);
    }

    public function test_regular_suppliers_still_require_tin_and_petty_cash_is_not_a_customer_classification(): void
    {
        $this->postJson('/masters/parties', ['name' => 'Normal supplier', 'classification' => 'Supplier', 'is_petty_cash' => false])->assertUnprocessable()->assertJsonValidationErrors(['tin', 'address']);
        $this->postJson('/masters/parties', ['name' => 'Not a supplier', 'classification' => 'Customer', 'is_petty_cash' => true])->assertUnprocessable()->assertJsonValidationErrors('classification');
        $this->postJson('/masters/parties', ['name' => 'Invalid TIN', 'classification' => 'Supplier', 'is_petty_cash' => true, 'tin' => '123'])->assertUnprocessable()->assertJsonValidationErrors('tin');
    }

    public function test_import_accepts_explicit_petty_cash_rows_without_tin(): void
    {
        $rows = [['name' => 'Petty cash transport', 'classification' => 'Supplier', 'is_petty_cash' => 'yes', 'tin' => '', 'address' => '']];
        $preview = $this->postJson('/masters/parties/import/preview', ['records' => $rows])->assertOk()->assertJsonCount(0, 'errors');
        $this->postJson('/masters/parties/import', ['records' => $rows, 'preview_token' => $preview->json('preview_token')])->assertCreated();
        $this->assertDatabaseHas('parties', ['name' => 'Petty cash transport', 'is_petty_cash' => true, 'tin' => null]);
    }

    public function test_petty_cash_records_are_seeded_per_company_workspace(): void
    {
        $first = ChartAccount::workspaceKey(session('company_setup'));
        Party::seedWorkspace($first);
        Party::seedWorkspace('another-company-workspace');
        $this->assertSame(2, Party::query()->where('code', 'PETTY-CASH')->count());
        $this->assertNotSame(Party::query()->where('workspace_key', $first)->where('code', 'PETTY-CASH')->sole()->id, Party::query()->where('workspace_key', 'another-company-workspace')->where('code', 'PETTY-CASH')->sole()->id);
    }
}
