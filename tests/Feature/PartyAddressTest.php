<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartyAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Address test', 'full_name' => 'Test user']]);
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return ['name' => 'Address customer', 'classification' => 'Both', 'tin' => '123-456-789-00000', 'building' => 'Unit 2', 'street' => 'Main Street', 'barangay' => 'Sample Barangay', 'city' => 'Sample City', 'province' => 'Sample Province', 'zip_code' => '0100'];
    }

    public function test_separate_address_fields_are_saved_and_combined_for_transaction_choices(): void
    {
        $created = $this->postJson('/masters/parties', [...$this->payload(), 'address' => 'Untrusted flat address'])->assertCreated()->assertJsonPath('record.address', 'Unit 2, Main Street, Sample Barangay, Sample City, Sample Province, 0100')->assertJsonPath('record.zip_code', '0100');
        $id = $created->json('record.id');
        $this->assertDatabaseHas('parties', ['id' => $id, ...$this->payload()]);
        $this->putJson('/masters/parties/'.$id, [...$this->payload(), 'code' => $created->json('record.code'), 'street' => 'Changed Street'])->assertOk()->assertJsonPath('record.street', 'Changed Street')->assertJsonPath('record.address', 'Unit 2, Changed Street, Sample Barangay, Sample City, Sample Province, 0100');
        $this->get('/masters/chart-of-accounts/catalog.js')->assertOk()->assertSee('Changed Street')->assertSee('Sample Province');
    }

    public function test_structured_addresses_validate_location_zip_and_combined_length(): void
    {
        $this->postJson('/masters/parties', [...$this->payload(), 'city' => '', 'province' => '', 'zip_code' => '12345'])->assertUnprocessable()->assertJsonValidationErrors(['city', 'province', 'zip_code']);
        $this->postJson('/masters/parties', [...$this->payload(), 'street' => str_repeat('a', 255), 'building' => str_repeat('b', 255)])->assertUnprocessable()->assertJsonValidationErrors('address');
        $this->assertDatabaseCount('parties', 0);
    }

    public function test_import_preserves_separate_fields_and_legacy_flat_address_input_still_works(): void
    {
        $row = $this->payload();
        $preview = $this->postJson('/masters/parties/import/preview', ['records' => [$row]])->assertOk()->assertJsonPath('errors', []);
        $this->postJson('/masters/parties/import', ['records' => [$row], 'preview_token' => $preview->json('preview_token')])->assertCreated()->assertJsonPath('records.0.city', 'Sample City')->assertJsonPath('records.0.zip_code', '0100');
        $legacy = Party::factory()->create(['workspace_key' => ChartAccount::workspaceKey(session('company_setup')), 'address' => 'Previously saved address']);
        $this->get('/masters/parties')->assertOk()->assertSee('Unit / Floor / Building')->assertSee('data-party-address-part', false);
        $this->assertDatabaseHas('parties', ['id' => $legacy->id, 'address' => 'Previously saved address', 'city' => null]);
        $this->postJson('/masters/parties', ['name' => 'Legacy input', 'classification' => 'Customer', 'tin' => '234567890000', 'address' => 'Legacy API address'])->assertCreated()->assertJsonPath('record.address', 'Legacy API address');
    }

    public function test_street_and_building_can_be_blank_when_creating_editing_and_importing_parties(): void
    {
        $payload = [...$this->payload(), 'building' => '', 'street' => ''];
        $created = $this->postJson('/masters/parties', $payload)->assertCreated()->assertJsonPath('record.street', null)
            ->assertJsonPath('record.address', 'Sample Barangay, Sample City, Sample Province, 0100');
        $this->putJson('/masters/parties/'.$created->json('record.id'), [...$payload, 'code' => $created->json('record.code'), 'name' => 'Edited party'])
            ->assertOk()->assertJsonPath('record.street', null);
        $rows = [[...$payload, 'name' => 'Imported without street', 'tin' => '234-567-890-00000']];
        $preview = $this->postJson('/masters/parties/import/preview', ['records' => $rows])->assertOk()->assertJsonPath('errors', []);
        $this->postJson('/masters/parties/import', ['records' => $rows, 'preview_token' => $preview->json('preview_token')])->assertCreated()->assertJsonPath('records.0.street', null);
    }
}
