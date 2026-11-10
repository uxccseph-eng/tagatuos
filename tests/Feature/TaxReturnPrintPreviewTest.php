<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\CompanyLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaxReturnPrintPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_0619e_print_uses_the_supplied_layout_and_frozen_values_without_recording_filing(): void
    {
        [$book, $id, $setting] = $this->prepare();
        $setting->update(['profile' => [...$setting->profile, 'company_name' => 'CHANGED LIVE NAME']]);
        $response = $this->get(route('tax-returns.show', ['company_id' => $book->id, 'id' => $id]))->assertOk()
            ->assertViewIs('tax-return-0619e-print')->assertSee('bir-0619e-jan2018.png')->assertSee('ARCHIVED COMPANY')
            ->assertDontSee('CHANGED LIVE NAME')->assertSee('NOT PROOF OF BIR FILING OR PAYMENT')
            ->assertSee('data-bir-amount="14" data-cents="2000"', false)
            ->assertSee('data-bir-amount="17D" data-cents="650"', false)
            ->assertSee('data-bir-amount="18" data-cents="2650"', false);
        $this->assertSame('123456789', $response->viewData('printData')['tin']);
        $this->assertSame('00000', $response->viewData('printData')['branch']);
        $this->assertDatabaseHas('tax_return_preparations', ['id' => $id, 'status' => 'prepared', 'filed_on' => null]);
        $workspace = $this->get(route('tax-returns.index', ['company_id' => $book->id, 'year' => 2026, 'period' => 10]))->assertOk()
            ->assertSee('data-print-preview="'.route('tax-returns.show', ['id' => $id, 'company_id' => $book->id]).'"', false)
            ->assertSee('return-print-dialog')->assertSee('Print / Save as PDF')
            ->assertSee('Return prepared')->assertSee('Save PDF…')->assertSee('Previous reporting period');
        $this->assertSame(2650, $workspace->viewData('cards')['0619-E']['generated']['payable']);
        $this->assertSame($id, $workspace->viewData('cards')['0619-E']['generated']['entry']->id);
    }

    public function test_unmapped_credits_remain_on_the_worksheet_and_other_companies_cannot_preview(): void
    {
        [$book, $id] = $this->prepare('1.00');
        $this->get(route('tax-returns.show', ['company_id' => $book->id, 'id' => $id]))->assertOk()
            ->assertViewIs('tax-return-print')->assertSee('without a verified field mapping');
        $other = Company::factory()->create();
        $other->users()->attach(auth()->user(), ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $other->workspaceKey(), 'lookup_key' => $other->workspaceKey()]);
        $this->get(route('tax-returns.show', ['company_id' => $other->id, 'id' => $id]))->assertNotFound();
    }

    /** @return array{Company, int, CompanySetting} */
    private function prepare(string $credits = '0.00'): array
    {
        $user = User::factory()->firm()->create();
        $book = Company::factory()->create();
        $book->users()->attach($user, ['role' => 'owner']);
        $setting = CompanySetting::factory()->create(['workspace_key' => $book->workspaceKey(), 'lookup_key' => $book->workspaceKey(), 'profile' => ['company_name' => 'ARCHIVED COMPANY', 'full_name' => $user->name, 'tin' => '123456789', 'branch_code' => '00000', 'rdo' => '033', 'vat_type' => 'vat', 'entity_type' => 'corporation']]);
        $state = CompanyLedgerService::emptyState();
        $state['wtpCertificates'] = [['id' => 1, 'number' => '2307I-000001', 'date' => '2026-10-20', 'status' => 'posted', 'ewtCode' => 'WC157', 'ewt' => 2, 'taxBase' => 100000]];
        CompanyLedger::factory()->create(['company_id' => $book->id, 'revision' => 1, 'state' => $state]);
        $selection = ['form' => '0619-E', 'year' => 2026, 'period' => 10];
        $this->actingAs($user)->withSession(['active_company_id' => $book->id])->withHeader('X-Ledger-Revision', '1');
        $preview = $this->getJson(route('tax-returns.preview', ['company_id' => $book->id, ...$selection]))->assertOk()->json();
        $input = [...$selection, 'request_id' => (string) Str::uuid(), 'ledger_revision' => 1, 'source_hash' => $preview['source_hash'], 'reviewed' => true, 'review_note' => 'Reconciled to posted withholding.', 'additional_tax' => '0.00', 'credits' => $credits, 'surcharge' => '1.00', 'interest' => '2.50', 'compromise' => '3.00'];
        $id = $this->postJson(route('tax-returns.prepare', ['company_id' => $book->id]), $input)->assertCreated()->json('id');

        return [$book, $id, $setting];
    }
}
