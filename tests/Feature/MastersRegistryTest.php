<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Party;
use App\Models\TaxCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MastersRegistryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function workspace(string $name = 'Registry QA'): array
    {
        return ['preview_access' => true, 'company_setup' => ['company_name' => $name, 'full_name' => 'Preview', 'tin' => '']];
    }

    public function test_registry_pages_require_company_and_share_navigation_without_reseeding_archived_records(): void
    {
        $this->get('/masters/parties')->assertRedirect('/signin');
        $this->withSession(['preview_access' => true])->get('/masters/tax-codes')->assertRedirect('/company/setup');
        $this->withSession($this->workspace())->get('/masters/parties')->assertOk()->assertViewIs('masters-registry')->assertSee('New party')->assertSee(route('masters.registry', ['registry' => 'tax-codes']));
        $this->get('/masters/tax-codes')->assertOk()->assertSee('New tax code')->assertSee('VAT-IMPORT');
        $this->assertDatabaseCount('parties', 3);
        $this->assertDatabaseCount('tax_codes', 7);
        $party = Party::query()->firstOrFail();
        $party->update(['archived' => true, 'name' => 'Edited name']);
        $this->get('/masters/parties')->assertOk();
        $this->assertDatabaseHas('parties', ['id' => $party->id, 'name' => 'Edited name', 'archived' => true]);
        $this->get('/masters/unknown')->assertNotFound();
    }

    public function test_party_crud_validates_input_and_preserves_company_isolation(): void
    {
        $payload = ['code' => 'new-customer', 'name' => 'New Customer', 'classification' => 'Customer', 'tin' => '123-456-789-00000', 'address' => '10 Sample Street', 'description' => 'Sample customer', 'email' => 'qa@example.test'];
        $this->withSession($this->workspace())->postJson('/masters/parties', $payload)->assertCreated()->assertJsonPath('record.code', 'NEW-CUSTOMER');
        $party = Party::query()->where('code', 'NEW-CUSTOMER')->firstOrFail();
        $this->putJson('/masters/parties/'.$party->id, [...$payload, 'classification' => 'Both', 'name' => 'Updated Customer'])->assertOk()->assertJsonPath('record.classification', 'Both');
        $this->postJson('/masters/parties', $payload)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/masters/parties', [...$payload, 'code' => 'OTHER', 'tin' => '123', 'email' => 'bad', 'classification' => 'Bad'])->assertUnprocessable()->assertJsonValidationErrors(['tin', 'email', 'classification']);
        $this->postJson('/masters/parties/'.$party->id.'/archive')->assertOk()->assertJsonPath('record.archived', true);
        $this->postJson('/masters/parties/'.$party->id.'/restore')->assertOk()->assertJsonPath('record.archived', false);
        $this->withSession($this->workspace('Other Company'))->putJson('/masters/parties/'.$party->id, $payload)->assertNotFound();
        $this->postJson('/masters/parties/'.$party->id.'/archive')->assertNotFound();
        $this->postJson('/masters/parties/'.$party->id.'/restore')->assertNotFound();
        $this->get('/masters/parties')->assertDontSee('Updated Customer');
    }

    public function test_tax_codes_persist_precise_rates_and_reject_invalid_or_duplicate_values(): void
    {
        $payload = ['code' => 'CUSTOM', 'name' => 'Custom reference', 'kind' => 'Withholding', 'rate' => '2.1250', 'atc' => 'WI100'];
        $this->withSession($this->workspace())->postJson('/masters/tax-codes', $payload)->assertCreated()->assertJsonPath('record.rate', '2.1250');
        $record = TaxCode::query()->where('code', 'CUSTOM')->firstOrFail();
        $this->putJson('/masters/tax-codes/'.$record->id, [...$payload, 'rate' => '1.5'])->assertOk()->assertJsonPath('record.rate', '1.5000');
        $this->postJson('/masters/tax-codes', [...$payload, 'code' => 'VAT'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/masters/tax-codes', [...$payload, 'code' => 'BAD', 'rate' => 101, 'kind' => 'Invalid', 'atc' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors(['rate', 'kind', 'atc']);
        $this->postJson('/masters/tax-codes/'.$record->id.'/archive')->assertOk()->assertJsonPath('record.archived', true);
        $this->postJson('/masters/tax-codes/'.$record->id.'/restore')->assertOk()->assertJsonPath('record.archived', false);
        $this->withSession($this->workspace('Other'))->putJson('/masters/tax-codes/'.$record->id, $payload)->assertNotFound();
    }

    public function test_rendering_escapes_saved_content_and_mutations_require_csrf(): void
    {
        Party::factory()->create(['workspace_key' => ChartAccount::workspaceKey($this->workspace()['company_setup']), 'name' => '</script><script>alert(1)</script>']);
        $this->withSession($this->workspace())->get('/masters/parties')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        $this->app->instance('env', 'production');
        $this->postJson('/masters/parties', ['code' => 'SAFE', 'name' => 'Sample', 'classification' => 'Customer'])->assertStatus(419);
    }

    public function test_import_validates_every_row_before_saving_and_rejects_duplicate_or_reserved_codes(): void
    {
        $row = ['code' => 'import-1', 'name' => 'Imported Party', 'classification' => 'Supplier', 'tin' => '000-000-000-00000', 'address' => '10 Sample Street'];
        $rows = [$row, [...$row, 'code' => 'IMPORT-2', 'name' => 'Other Imported Party']];
        $preview = $this->withSession($this->workspace())->postJson('/masters/parties/import/preview', ['records' => $rows])->assertOk()->assertJsonPath('errors', []);
        $this->postJson('/masters/parties/import', ['records' => $rows, 'preview_token' => $preview->json('preview_token')])->assertCreated()->assertJsonCount(2, 'records');
        $this->assertDatabaseHas('parties', ['code' => 'IMPORT-1', 'name' => 'Imported Party']);
        $this->postJson('/masters/parties/import/preview', ['records' => [[...$row, 'code' => 'IMPORT-3'], [...$row, 'code' => 'IMPORT-4', 'classification' => 'Bad']]])->assertOk()->assertJsonPath('preview_token', null);
        $this->assertDatabaseMissing('parties', ['code' => 'IMPORT-3']);
        $this->postJson('/masters/parties/import/preview', ['records' => [$row]])->assertOk()->assertJsonPath('preview_token', null);
        $this->postJson('/masters/parties/import/preview', ['records' => [[...$row, 'code' => 'SAME'], [...$row, 'code' => 'same']]])->assertOk()->assertJsonPath('preview_token', null);
        $this->postJson('/masters/parties/import/preview', ['records' => [[...$row, 'code' => 'IMPORT-5', 'archived' => true]]])->assertOk()->assertJsonPath('preview_token', null);
        $this->withSession($this->workspace('Fresh Workspace'))->postJson('/masters/parties', [...$row, 'code' => 'VARIOUS-CUST'])->assertUnprocessable()->assertJsonValidationErrors('code');
    }
}
