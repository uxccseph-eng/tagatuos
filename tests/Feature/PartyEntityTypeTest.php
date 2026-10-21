<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartyEntityTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Entity test', 'full_name' => 'Test user']]);
    }

    /** @return array<string, string> */
    private function individual(): array
    {
        return ['entity_type' => 'individual', 'last_name' => '  Dela  Cruz ', 'first_name' => ' Juan ', 'middle_name' => ' Santos ', 'classification' => 'Both', 'tin' => '123-456-789-00000', 'address' => 'Main Street, Sample City'];
    }

    public function test_individual_names_are_saved_separately_and_combined_for_sheets(): void
    {
        $created = $this->postJson('/masters/parties', [...$this->individual(), 'name' => 'Ignored entity name'])->assertCreated()
            ->assertJsonPath('record.name', 'Dela Cruz, Juan Santos')->assertJsonPath('record.last_name', 'Dela Cruz')->assertJsonPath('record.first_name', 'Juan')->assertJsonPath('record.middle_name', 'Santos');
        $id = $created->json('record.id');
        $this->assertDatabaseHas('parties', ['id' => $id, 'entity_type' => 'individual', 'last_name' => 'Dela Cruz']);
        $this->get('/masters/chart-of-accounts/catalog.js')->assertOk()->assertSee('Dela Cruz')->assertSee('individual');
        $this->putJson('/masters/parties/'.$id, [...$this->individual(), 'code' => $created->json('record.code'), 'first_name' => 'Maria', 'middle_name' => ''])->assertOk()->assertJsonPath('record.name', 'Dela Cruz, Maria');
        $this->putJson('/masters/parties/'.$id, [...$this->individual(), 'code' => $created->json('record.code'), 'entity_type' => 'corporation', 'name' => 'Sample Corporation'])->assertOk()->assertJsonPath('record.name', 'Sample Corporation')->assertJsonPath('record.last_name', null)->assertJsonPath('record.first_name', null)->assertJsonPath('record.middle_name', null);
    }

    public function test_required_names_and_entity_type_are_validated(): void
    {
        $this->postJson('/masters/parties', [...$this->individual(), 'last_name' => '', 'first_name' => ''])->assertUnprocessable()->assertJsonValidationErrors(['last_name', 'first_name']);
        $this->postJson('/masters/parties', [...$this->individual(), 'entity_type' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors('entity_type');
        $this->postJson('/masters/parties', [...$this->individual(), 'last_name' => ['invalid']])->assertUnprocessable()->assertJsonValidationErrors('last_name');
        $this->postJson('/masters/parties', [...$this->individual(), 'first_name' => str_repeat('a', 31)])->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->postJson('/masters/parties', [...$this->individual(), 'entity_type' => 'corporation', 'name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('parties', 0);
    }

    public function test_legacy_parties_default_to_non_individual_without_losing_registered_names(): void
    {
        $row = ['name' => 'Existing business', 'classification' => 'Customer', 'tin' => '234-567-890-00000', 'address' => 'Existing address'];
        $created = $this->postJson('/masters/parties', $row)->assertCreated()->assertJsonPath('record.entity_type', 'corporation')->assertJsonPath('record.name', 'Existing business');
        $this->putJson('/masters/parties/'.$created->json('record.id'), [...$row, 'code' => $created->json('record.code')])->assertOk()->assertJsonPath('record.entity_type', 'corporation');
        $this->get('/masters/parties')->assertOk()->assertSee('Non-individual')->assertSee('Individual')->assertSee('Last Name')->assertSee('First Name')->assertSee('Middle Name');
    }

    public function test_import_supports_individual_names_and_blocks_duplicate_combined_names(): void
    {
        $row = $this->individual();
        $preview = $this->postJson('/masters/parties/import/preview', ['records' => [$row]])->assertOk()->assertJsonPath('errors', []);
        $this->postJson('/masters/parties/import', ['records' => [$row], 'preview_token' => $preview->json('preview_token')])->assertCreated()->assertJsonPath('records.0.name', 'Dela Cruz, Juan Santos')->assertJsonPath('records.0.entity_type', 'individual');
        $duplicate = $this->postJson('/masters/parties/import/preview', ['records' => [[...$row, 'tin' => '345-678-901-00000']]])->assertOk();
        $this->assertNotEmpty($duplicate->json('errors'));
        $this->assertDatabaseCount('parties', 1);
    }
}
