<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FirmOwnershipTransfer;
use App\Models\TeamLoginToken;
use App\Models\User;
use App\Services\FirmOwnershipService;
use App\Services\OrganizationTeamService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FirmOwnershipTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_whole_firm_handover_preserves_books_and_team_and_removes_old_owner(): void
    {
        [$owner, $target, $books] = $this->firm();
        $staff = User::factory()->accountant()->create(['must_change_password' => false, 'provisioned_by' => $owner->id]);
        app(OrganizationTeamService::class)->save($owner, $staff, ['role' => 'staff', 'tabs' => ['sheets'], 'is_suspended' => true]);
        $original = $books->map->getAttributes()->all();
        $ownerPassword = $owner->password;
        $targetPassword = $target->password;
        $this->actingAs($owner)->get(route('account.ownership.index'))->assertOk()->assertSee('Transfer firm ownership');
        $transfer = $this->requestTransfer($owner, $target);
        $this->assertDatabaseHas('companies', ['id' => $books[0]->id, 'organization_owner_id' => $owner->id]);
        $this->actingAs($target)->get(route('account.dashboard'))->assertOk()->assertSee('Review handover');
        $this->get(route('account.ownership.show', $transfer->id))->assertOk()->assertSee($books[1]->name);
        $this->acceptTransfer($owner, $target, $transfer);
        foreach ($books as $index => $book) {
            $this->assertSame($target->id, $book->fresh()->organization_owner_id);
            $this->assertSame($original[$index]['workspace_key'], $book->fresh()->workspace_key);
            $this->assertSame($original[$index]['name'], $book->fresh()->name);
            $this->assertDatabaseMissing('company_user', ['company_id' => $book->id, 'user_id' => $owner->id]);
            $this->assertDatabaseHas('company_user', ['company_id' => $book->id, 'user_id' => $target->id, 'role' => 'owner', 'is_suspended' => false, 'allowed_tabs' => null]);
            $this->assertDatabaseHas('company_user', ['company_id' => $book->id, 'user_id' => $staff->id, 'role' => 'staff', 'is_suspended' => true]);
            $this->assertDatabaseHas('team_events', ['company_id' => $book->id, 'action' => 'Firm ownership transferred']);
        }
        $this->assertDatabaseHas('organization_members', ['owner_id' => $target->id, 'user_id' => $staff->id, 'role' => 'staff', 'is_suspended' => true]);
        $this->assertDatabaseMissing('organization_members', ['user_id' => $target->id]);
        $this->assertSame($target->id, $staff->fresh()->provisioned_by);
        $this->assertSame('firm', $target->fresh()->type);
        $this->assertFalse($target->fresh()->is_system_owner);
        $this->assertNull($target->fresh()->provisioned_by);
        $this->assertSame($ownerPassword, $owner->fresh()->password);
        $this->assertSame($targetPassword, $target->fresh()->password);
        $this->assertSame('accepted', $transfer->fresh()->status);
        $this->get(route('account.ownership.show', $transfer->id))->assertOk()->assertSee($books[0]->name)->assertSee($books[1]->name);
        $this->actingAs($owner)->postJson(route('account.companies.open', $books[0]->id))->assertNotFound();
        $this->actingAs($target)->post(route('account.ownership.accept', $transfer->id), $this->acceptData($owner, $transfer))->assertRedirect(route('account.dashboard'));
        $this->assertDatabaseCount('companies', 2);
    }

    public function test_wrong_password_confirmation_and_unrelated_actors_cannot_transfer(): void
    {
        [$owner, $target] = $this->firm();
        $this->actingAs($owner)->postJson(route('account.ownership.store'), ['email' => $target->email, 'current_password' => 'wrong', 'confirmation' => $owner->name])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson(route('account.ownership.store'), ['email' => $target->email, 'current_password' => 'password', 'confirmation' => 'wrong'])->assertUnprocessable();
        $transfer = $this->requestTransfer($owner, $target);
        $outsider = User::factory()->firm()->create();
        $this->actingAs($outsider)->getJson(route('account.ownership.show', $transfer->id))->assertForbidden();
        $this->postJson(route('account.ownership.accept', $transfer->id), $this->acceptData($owner, $transfer))->assertForbidden();
        $this->postJson(route('account.ownership.close', $transfer->id))->assertForbidden();
        $this->actingAs($target)->postJson(route('account.ownership.accept', $transfer->id), array_replace($this->acceptData($owner, $transfer), ['confirmation' => 'wrong']))->assertUnprocessable();
        $this->assertSame('pending', $transfer->fresh()->status);
    }

    public function test_accountant_cannot_initiate_and_existing_firms_cannot_be_merged(): void
    {
        [$owner, $target] = $this->firm();
        $this->actingAs($target)->getJson(route('account.ownership.index'))->assertForbidden();
        $this->postJson(route('account.ownership.store'), ['email' => $owner->email, 'current_password' => 'password', 'confirmation' => $target->name])->assertForbidden();
        $foreign = User::factory()->firm()->create();
        $foreignBook = Company::factory()->create(['organization_owner_id' => $foreign->id]);
        $foreign->companies()->attach($foreignBook, ['role' => 'owner']);
        $this->actingAs($owner)->postJson(route('account.ownership.store'), ['email' => $foreign->email, 'current_password' => 'password', 'confirmation' => $owner->name])->assertUnprocessable();
        $this->assertDatabaseCount('firm_ownership_transfers', 0);
    }

    public function test_stale_review_and_new_outside_membership_stop_acceptance(): void
    {
        [$owner, $target, $books] = $this->firm();
        $transfer = $this->requestTransfer($owner, $target);
        $data = $this->acceptData($owner, $transfer);
        $books[0]->update(['name' => 'Changed after review']);
        $this->actingAs($target)->postJson(route('account.ownership.accept', $transfer->id), $data)->assertUnprocessable()->assertJsonValidationErrors('ownership');
        $foreign = Company::factory()->create();
        $target->companies()->attach($foreign, ['role' => 'accountant']);
        $this->postJson(route('account.ownership.accept', $transfer->id), $this->acceptData($owner, $transfer))->assertUnprocessable();
        $this->assertSame($owner->id, $books[0]->fresh()->organization_owner_id);
    }

    public function test_expired_requests_and_source_password_changes_invalidate_acceptance(): void
    {
        [$owner, $target] = $this->firm();
        $transfer = $this->requestTransfer($owner, $target);
        $transfer->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->actingAs($target)->postJson(route('account.ownership.accept', $transfer->id), $this->acceptData($owner, $transfer))->assertUnprocessable();
        $transfer->forceFill(['expires_at' => now()->addDay()])->save();
        $owner->forceFill(['password' => 'ChangedPassword123'])->save();
        $this->postJson(route('account.ownership.accept', $transfer->id), $this->acceptData($owner, $transfer))->assertUnprocessable();
        $this->assertSame('pending', $transfer->fresh()->status);
    }

    public function test_cancellation_and_decline_leave_client_ownership_unchanged(): void
    {
        [$owner, $target, $books] = $this->firm();
        $transfer = $this->requestTransfer($owner, $target);
        $this->actingAs($owner)->post(route('account.ownership.close', $transfer->id))->assertRedirect();
        $this->assertSame('cancelled', $transfer->fresh()->status);
        $this->actingAs($target)->postJson(route('account.ownership.accept', $transfer->id), $this->acceptData($owner, $transfer))->assertUnprocessable();
        $next = $this->requestTransfer($owner, $target);
        $this->actingAs($target)->post(route('account.ownership.close', $next->id))->assertRedirect();
        $this->assertSame('declined', $next->fresh()->status);
        $this->assertSame($owner->id, $books[0]->fresh()->organization_owner_id);
    }

    public function test_duplicate_requests_and_suspended_or_unapproved_recipients_are_rejected(): void
    {
        [$owner, $target] = $this->firm();
        $transfer = $this->requestTransfer($owner, $target);
        $this->actingAs($owner)->postJson(route('account.ownership.store'), ['email' => $target->email, 'current_password' => 'password', 'confirmation' => $owner->name])->assertUnprocessable();
        DB::table('organization_members')->where('user_id', $target->id)->update(['is_suspended' => true]);
        $this->actingAs($target)->postJson(route('account.ownership.accept', $transfer->id), $this->acceptData($owner, $transfer))->assertUnprocessable();
        $this->assertSame('pending', $transfer->fresh()->status);
    }

    public function test_coowned_and_deleting_clients_block_the_entire_handover(): void
    {
        [$owner, $target, $books] = $this->firm();
        $books[1]->forceFill(['provisioning_status' => 'deleting'])->save();
        $this->actingAs($owner)->postJson(route('account.ownership.store'), ['email' => $target->email, 'current_password' => 'password', 'confirmation' => $owner->name])->assertUnprocessable();
        $books[1]->forceFill(['provisioning_status' => 'ready'])->save();
        $outsider = User::factory()->firm()->create();
        $outsider->companies()->attach($books[1], ['role' => 'owner']);
        $this->postJson(route('account.ownership.store'), ['email' => $target->email, 'current_password' => 'password', 'confirmation' => $owner->name])->assertUnprocessable();
        $this->assertDatabaseCount('firm_ownership_transfers', 0);
    }

    public function test_failure_during_acceptance_rolls_back_all_books_and_memberships(): void
    {
        [$owner, $target, $books] = $this->firm();
        $transfer = $this->requestTransfer($owner, $target);
        DB::statement("CREATE TRIGGER fail_handover BEFORE UPDATE ON companies WHEN NEW.id = {$books[1]->id} BEGIN SELECT RAISE(ABORT, 'Simulated failure'); END");
        $this->actingAs($target)->postJson(route('account.ownership.accept', $transfer->id), $this->acceptData($owner, $transfer))->assertStatus(500);
        foreach ($books as $book) {
            $this->assertSame($owner->id, $book->fresh()->organization_owner_id);
            $this->assertDatabaseHas('company_user', ['company_id' => $book->id, 'user_id' => $owner->id, 'role' => 'owner']);
        }
        $this->assertSame('pending', $transfer->fresh()->status);
        $this->assertSame('accountant', $target->fresh()->type);
    }

    public function test_clean_firm_account_can_accept_and_old_setup_links_are_revoked(): void
    {
        [$owner, $accountant, $books] = $this->firm();
        $target = User::factory()->firm()->create();
        $token = TeamLoginToken::factory()->create(['company_id' => $books[0]->id, 'user_id' => $accountant->id, 'issued_by' => $owner->id]);
        $transfer = $this->requestTransfer($owner, $target);
        $this->acceptTransfer($owner, $target, $transfer);
        $this->assertNotNull($token->fresh()->used_at);
        $this->assertDatabaseHas('organization_members', ['owner_id' => $target->id, 'user_id' => $accountant->id]);
        $this->actingAs($target)->postJson(route('account.companies.store'), ['name' => 'Client after handover'])->assertCreated();
        $newBook = Company::query()->where('name', 'Client after handover')->firstOrFail();
        $this->assertSame($target->id, $newBook->organization_owner_id);
        $this->assertDatabaseHas('company_user', ['company_id' => $newBook->id, 'user_id' => $accountant->id, 'role' => 'accountant']);
        $this->assertDatabaseMissing('company_user', ['company_id' => $newBook->id, 'user_id' => $owner->id]);
    }

    public function test_individual_temporary_unapproved_and_unrelated_accountants_cannot_receive_the_firm(): void
    {
        [$owner] = $this->firm();
        $recipients = [User::factory()->create(), User::factory()->accountant()->create(), User::factory()->firm()->pending()->create(), User::factory()->accountant()->create(['must_change_password' => false])];
        foreach ($recipients as $recipient) {
            $this->actingAs($owner)->postJson(route('account.ownership.store'), ['email' => $recipient->email, 'current_password' => 'password', 'confirmation' => $owner->name])->assertUnprocessable();
        }
        $this->assertDatabaseCount('firm_ownership_transfers', 0);
    }

    /** @return array{User, User, Collection<int, Company>} */
    private function firm(): array
    {
        $owner = User::factory()->firm()->create();
        $target = User::factory()->accountant()->create(['must_change_password' => false, 'provisioned_by' => $owner->id]);
        $books = Company::factory()->count(2)->create(['organization_owner_id' => $owner->id]);
        foreach ($books as $book) {
            $owner->companies()->attach($book, ['role' => 'owner']);
        }
        app(OrganizationTeamService::class)->save($owner, $target, ['role' => 'accountant', 'tabs' => ['sheets', 'settings']]);

        return [$owner, $target, $books];
    }

    private function requestTransfer(User $owner, User $target): FirmOwnershipTransfer
    {
        $this->actingAs($owner)->post(route('account.ownership.store'), ['email' => $target->email, 'current_password' => 'password', 'confirmation' => $owner->name])->assertRedirect(route('account.ownership.index'))->assertSessionHasNoErrors();

        return FirmOwnershipTransfer::query()->latest('id')->firstOrFail();
    }

    /** @return array<string, string> */
    private function acceptData(User $owner, FirmOwnershipTransfer $transfer): array
    {
        return ['current_password' => 'password', 'confirmation' => $owner->name, 'scope_hash' => app(FirmOwnershipService::class)->scopeHash(app(FirmOwnershipService::class)->scope($owner->fresh()))];
    }

    private function acceptTransfer(User $owner, User $target, FirmOwnershipTransfer $transfer): void
    {
        $this->actingAs($target)->post(route('account.ownership.accept', $transfer->id), $this->acceptData($owner, $transfer))->assertRedirect(route('account.dashboard'))->assertSessionHasNoErrors();
    }
}
