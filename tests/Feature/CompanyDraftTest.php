<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompanyDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_two_recovery_drafts_are_allowed_but_existing_drafts_can_still_be_updated(): void
    {
        [$company, $user, $other] = $this->book();
        $this->member($company, $user);
        $first = (string) Str::uuid();
        foreach ([$first, (string) Str::uuid()] as $id) {
            $this->putJson(route('sheets.drafts.store', $id), ['version' => 0, 'payload' => $this->payload($company)])->assertOk();
        }
        $this->putJson(route('sheets.drafts.store', (string) Str::uuid()), ['version' => 0, 'payload' => $this->payload($company)])->assertUnprocessable();
        $this->putJson(route('sheets.drafts.store', $first), ['version' => 1, 'payload' => $this->payload($company)])->assertOk()->assertJsonPath('version', 2);
        $this->assertDatabaseCount('company_ledger_drafts', 2);
        $this->member($company, $other);
        $this->putJson(route('sheets.drafts.store', (string) Str::uuid()), ['version' => 0, 'payload' => $this->payload($company)])->assertOk();
    }

    public function test_incomplete_private_drafts_are_encrypted_and_do_not_create_transactions_or_numbers(): void
    {
        [$company, $user] = $this->book();
        $this->member($company, $user);
        $id = (string) Str::uuid();
        $payload = $this->payload($company);
        $this->putJson(route('sheets.drafts.store', $id), ['version' => 0, 'payload' => $payload])->assertOk()->assertJsonPath('version', 1);
        $this->getJson(route('sheets.drafts.show', $id))->assertOk()->assertJsonPath('payload.changes.0.record.number', '')->assertJsonPath('payload.changes.0.record.name', 'PRIVATE INCOMPLETE PAYROLL');
        $this->assertStringNotContainsString('PRIVATE INCOMPLETE PAYROLL', DB::table('company_ledger_drafts')->value('payload'));
        $this->assertDatabaseCount('company_ledgers', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('company_ledger_changes', 0);
        $this->getJson(route('sheets.drafts.index'))->assertOk()->assertJsonCount(1, 'drafts')->assertJsonMissingPath('drafts.0.payload');
    }

    public function test_other_accountants_cannot_list_read_change_or_delete_a_private_draft(): void
    {
        [$company, $a, $b] = $this->book();
        $this->member($company, $a);
        $id = (string) Str::uuid();
        $this->putJson(route('sheets.drafts.store', $id), ['version' => 0, 'payload' => $this->payload($company)])->assertOk();
        $this->member($company, $b);
        $this->getJson(route('sheets.drafts.index'))->assertOk()->assertJsonCount(0, 'drafts');
        $this->getJson(route('sheets.drafts.show', $id))->assertNotFound();
        $this->deleteJson(route('sheets.drafts.destroy', $id), ['version' => 1])->assertOk();
        $this->putJson(route('sheets.drafts.store', $id), ['version' => 0, 'payload' => $this->payload($company)])->assertOk();
        $this->assertDatabaseCount('company_ledger_drafts', 2);
        $this->member($company, $a);
        $this->getJson(route('sheets.drafts.show', $id))->assertOk()->assertJsonPath('version', 1);
    }

    public function test_stale_saves_and_cleanup_cannot_replace_or_delete_newer_work(): void
    {
        [$company, $user] = $this->book();
        $this->member($company, $user);
        $id = (string) Str::uuid();
        $payload = $this->payload($company);
        $this->putJson(route('sheets.drafts.store', $id), ['version' => 0, 'payload' => $payload])->assertOk();
        $payload['changes'][0]['record']['name'] = 'NEWER';
        $this->putJson(route('sheets.drafts.store', $id), ['version' => 1, 'payload' => $payload])->assertOk()->assertJsonPath('version', 2);
        $this->putJson(route('sheets.drafts.store', $id), ['version' => 1, 'payload' => $this->payload($company)])->assertConflict();
        $this->deleteJson(route('sheets.drafts.destroy', $id), ['version' => 1])->assertConflict();
        $this->getJson(route('sheets.drafts.show', $id))->assertOk()->assertJsonPath('payload.changes.0.record.name', 'NEWER');
        $this->deleteJson(route('sheets.drafts.destroy', $id), ['version' => 2])->assertOk();
        $this->deleteJson(route('sheets.drafts.destroy', $id), ['version' => 2])->assertOk();
        $this->assertDatabaseCount('company_ledger_drafts', 0);
    }

    public function test_company_identity_permissions_and_payload_limits_are_enforced(): void
    {
        [$company, $user] = $this->book();
        $this->member($company, $user);
        $id = (string) Str::uuid();
        $payload = $this->payload($company);
        $payload['company_id'] = $company->id + 1;
        $this->putJson(route('sheets.drafts.store', $id), ['version' => 0, 'payload' => $payload])->assertConflict();
        $payload = $this->payload($company);
        $payload['changes'][0]['sheet'] = 'users';
        $this->putJson(route('sheets.drafts.store', $id), ['version' => 0, 'payload' => $payload])->assertUnprocessable();
        $company->users()->updateExistingPivot($user->id, ['role' => 'staff', 'allowed_tabs' => json_encode(['reports'])]);
        $this->getJson(route('sheets.drafts.index'))->assertForbidden();
        $this->assertDatabaseCount('company_ledger_drafts', 0);
    }

    public function test_unresolved_drafts_remain_available_after_a_month(): void
    {
        [$company, $user] = $this->book();
        $this->member($company, $user);
        $id = (string) Str::uuid();
        $this->putJson(route('sheets.drafts.store', $id), ['version' => 0, 'payload' => $this->payload($company)])->assertOk();
        $this->travel(31)->days();
        $this->getJson(route('sheets.drafts.index'))->assertOk()->assertJsonCount(1, 'drafts');
        $this->getJson(route('sheets.drafts.show', $id))->assertOk();
    }

    public function test_successful_book_save_cleans_only_the_acknowledged_private_version_of_its_accountant(): void
    {
        [$company, $a, $b] = $this->book();
        $id = (string) Str::uuid();
        $this->member($company, $b);
        $this->putJson(route('sheets.drafts.store', $id), ['version' => 0, 'payload' => $this->payload($company)])->assertOk();
        $this->member($company, $a);
        $this->putJson(route('sheets.drafts.store', $id), ['version' => 0, 'payload' => $this->payload($company)])->assertOk();
        $operation = ['sheet' => 'journalEntries', 'id' => 'draft', 'expected_version' => 0, 'record' => ['id' => 'draft', 'number' => '', 'date' => '2026-11-01', 'status' => 'draft', 'lines' => []]];
        $this->postJson(route('sheets.database.save'), ['revision' => 0, 'request_id' => (string) Str::uuid(), 'operations' => [$operation], 'private_drafts' => [['id' => $id, 'version' => 1]]])->assertOk();
        $this->assertDatabaseMissing('company_ledger_drafts', ['user_id' => $a->id]);
        $this->assertDatabaseHas('company_ledger_drafts', ['user_id' => $b->id]);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_a_lost_save_acknowledgement_does_not_offer_the_already_applied_draft_again(): void
    {
        [$company, $user] = $this->book();
        $this->member($company, $user);
        $id = (string) Str::uuid();
        $operation = ['sheet' => 'journalEntries', 'id' => 'draft', 'expected_version' => 0, 'record' => ['id' => 'draft', 'number' => '', 'date' => '2026-11-01', 'status' => 'draft', 'lines' => []]];
        $requestId = (string) Str::uuid();
        $payload = ['format' => 2, 'company_id' => $company->id, 'revision' => 0, 'changes' => [$operation], 'before' => [['sheet' => 'journalEntries', 'id' => 'draft', 'record' => null]], 'request' => ['revision' => 0, 'request_id' => $requestId, 'operations' => [$operation]], 'error' => null];
        $this->putJson(route('sheets.drafts.store', $id), ['version' => 0, 'payload' => $payload])->assertOk();
        $this->postJson(route('sheets.database.save'), ['revision' => 0, 'request_id' => $requestId, 'operations' => [$operation]])->assertOk();
        $this->getJson(route('sheets.drafts.index'))->assertOk()->assertJsonCount(0, 'drafts');
        $this->assertDatabaseCount('company_ledger_drafts', 0);
        $this->assertDatabaseCount('company_ledger_changes', 1);
    }

    public function test_rejected_posting_does_not_delete_the_private_recovery(): void
    {
        [$company, $user] = $this->book();
        $this->member($company, $user);
        $id = (string) Str::uuid();
        $this->putJson(route('sheets.drafts.store', $id), ['version' => 0, 'payload' => $this->payload($company)])->assertOk();
        $operation = ['sheet' => 'journalEntries', 'id' => 'bad', 'expected_version' => 0, 'record' => ['id' => 'bad', 'number' => '', 'date' => '2026-11-01', 'status' => 'posted', 'lines' => []]];
        $this->postJson(route('sheets.database.save'), ['revision' => 0, 'request_id' => (string) Str::uuid(), 'operations' => [$operation], 'private_drafts' => [['id' => $id, 'version' => 1]]])->assertUnprocessable();
        $this->getJson(route('sheets.drafts.show', $id))->assertOk();
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_retrying_a_private_save_after_a_lost_acknowledgement_is_idempotent(): void
    {
        [$company, $user] = $this->book();
        $this->member($company, $user);
        $id = (string) Str::uuid();
        $data = ['version' => 0, 'payload' => $this->payload($company)];
        $this->putJson(route('sheets.drafts.store', $id), $data)->assertOk()->assertJsonPath('version', 1);
        $this->putJson(route('sheets.drafts.store', $id), $data)->assertOk()->assertJsonPath('version', 1);
        $this->assertDatabaseCount('company_ledger_drafts', 1);
    }

    public function test_switching_company_context_never_returns_another_clients_private_draft(): void
    {
        [$first, $user] = $this->book();
        [$second] = $this->book();
        $second->users()->attach($user, ['role' => 'accountant']);
        $this->member($first, $user);
        $id = (string) Str::uuid();
        $this->putJson(route('sheets.drafts.store', $id), ['version' => 0, 'payload' => $this->payload($first)])->assertOk();
        $this->member($second, $user);
        $this->getJson(route('sheets.drafts.index'))->assertOk()->assertJsonCount(0, 'drafts');
        $this->getJson(route('sheets.drafts.show', $id))->assertNotFound();
        $this->putJson(route('sheets.drafts.store', $id), ['version' => 0, 'payload' => $this->payload($first)])->assertConflict();
        $this->member($first, $user);
        $this->getJson(route('sheets.drafts.show', $id))->assertOk();
    }

    private function member(Company $company, User $user): void
    {
        $this->actingAs($user)->withSession(['active_company_id' => $company->id])->withHeader('X-Company-ID', (string) $company->id);
    }

    private function payload(Company $company): array
    {
        return ['format' => 2, 'company_id' => $company->id, 'revision' => 0, 'changes' => [['sheet' => 'payroll', 'id' => 'private-run', 'expected_version' => 0, 'record' => ['id' => 'private-run', 'status' => 'draft', 'number' => '', 'name' => 'PRIVATE INCOMPLETE PAYROLL']]], 'before' => [['sheet' => 'payroll', 'id' => 'private-run', 'record' => null]], 'request' => null, 'error' => null];
    }

    /** @return array{Company, User, User} */
    private function book(): array
    {
        $company = Company::factory()->create();
        $a = User::factory()->firm()->create();
        $b = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($a, ['role' => 'owner']);
        $company->users()->attach($b, ['role' => 'accountant']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey()]);

        return [$company, $a, $b];
    }
}
