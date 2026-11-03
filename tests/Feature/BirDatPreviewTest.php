<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BirDatPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_party_preview_shows_export_text_without_rewriting_registered_details(): void
    {
        $user = User::factory()->firm()->create();
        $company = Company::factory()->create();
        $company->users()->attach($user, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => $company->name, 'full_name' => $user->name]]);
        $party = Party::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => 'Peña & Co., Inc.', 'address' => 'Unit #12-B / José St.']);
        $this->actingAs($user)->postJson(route('masters.parties.dat-preview', ['company_id' => $company->id]), ['fields' => ['name' => $party->name, 'address' => $party->address]])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['fields' => ['name' => 'PENA AND CO INC', 'address' => 'UNIT 12 B JOSE ST']]);
        $this->assertSame('Peña & Co., Inc.', $party->fresh()->name);
        $this->assertSame('Unit #12-B / José St.', $party->fresh()->address);
        $this->get(route('masters.registry', ['registry' => 'parties', 'company_id' => $company->id]))->assertOk()->assertSee('BIR DAT text preview');
        $this->postJson(route('masters.parties.dat-preview', ['company_id' => $company->id]), ['fields' => ['name' => ['not a scalar']]])->assertUnprocessable();
        $foreign = Company::factory()->create();
        $this->postJson(route('masters.parties.dat-preview', ['company_id' => $foreign->id]), ['fields' => ['name' => 'Other']])->assertForbidden();
    }

    public function test_preview_does_not_accept_unauthenticated_requests(): void
    {
        $this->postJson(route('masters.parties.dat-preview'), ['fields' => ['name' => 'Supplier']])->assertUnauthorized();
    }
}
