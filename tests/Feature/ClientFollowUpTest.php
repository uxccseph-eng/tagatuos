<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\User;
use App\Services\ClientFollowUpService;
use App\Services\CompanyLedgerService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientFollowUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_request_uses_company_snapshot_and_retry_does_not_duplicate_or_change_books(): void
    {
        [$company, $user] = $this->book();
        $state = CompanyLedgerService::emptyState();
        $state['purchases'] = [['id' => 'own-entry', 'number' => 'BILL-000001', 'date' => '2026-10-19', 'status' => 'posted', 'supplier' => 'Own Supplier', 'reference' => 'INV-123', 'gross' => 112000]];
        $ledger = CompanyLedger::factory()->create(['company_id' => $company->id, 'state' => $state]);
        $this->actingAs($user);
        $this->get(route('follow-ups.index', ['companyId' => $company->id, 'transaction' => 'purchases:own-entry', 'txn_q' => 'BILL-000001']))->assertOk()->assertViewHas('preselectedTransaction', 'purchases:own-entry');
        $data = $this->payload(['transaction' => 'purchases:own-entry', 'party' => 'TAMPERED', 'amount' => '999.00']);
        $this->post(route('follow-ups.store', $company->id), $data)->assertRedirect(route('follow-ups.index', $company->id));
        $this->post(route('follow-ups.store', $company->id), $data)->assertRedirect();
        $this->assertDatabaseCount('client_follow_ups', 1);
        $this->assertDatabaseCount('client_follow_up_events', 1);
        $this->assertDatabaseHas('client_follow_ups', ['company_id' => $company->id, 'party' => 'Own Supplier', 'reference' => 'INV-123', 'amount' => '1120.0000', 'linked_record_id' => 'own-entry']);
        $this->postJson(route('follow-ups.store', $company->id), [...$data, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson(route('follow-ups.store', $company->id), [...$data, 'request_text' => 'Different retry data'])->assertConflict();
        $this->assertSame($state, $ledger->fresh()->state);
        $this->assertDatabaseCount('journal_entries', 0);
        $id = DB::table('client_follow_ups')->value('id');
        $this->post(route('follow-ups.open-entry', [$company->id, $id]))->assertRedirect(route('sheets.purchases', ['company_id' => $company->id, 'entry' => 'own-entry', 'period' => '2026-10']))->assertSessionHas('active_company_id', $company->id);
    }

    public function test_only_active_assigned_members_with_sheets_access_can_read_write_or_export(): void
    {
        [$company, $owner] = $this->book();
        $id = app(ClientFollowUpService::class)->save($owner, $company->id, $this->payload());
        [$other, $outsider] = $this->book();
        $this->actingAs($outsider)->get(route('follow-ups.index', $company->id))->assertNotFound();
        $this->postJson(route('follow-ups.store', $company->id), $this->payload())->assertNotFound();
        $this->get(route('follow-ups.export', [$company->id, 'csv']))->assertNotFound();
        $this->putJson(route('follow-ups.update', [$other->id, $id]), ['action' => 'resolve', 'version' => 1, 'note' => 'Wrong company'])->assertNotFound();
        $staff = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($staff, ['role' => 'staff', 'allowed_tabs' => json_encode(['dashboard', 'reports'])]);
        $this->actingAs($staff)->get(route('follow-ups.index', $company->id))->assertForbidden();
        $this->get(route('follow-ups.export', [$company->id, 'pdf']))->assertForbidden();
        $company->users()->updateExistingPivot($staff->id, ['allowed_tabs' => json_encode(['sheets']), 'is_suspended' => true]);
        $this->get(route('follow-ups.index', $company->id))->assertNotFound();
        $this->actingAs($owner)->get(route('follow-ups.index', ['companyId' => $company->id, 'edit' => $id]))->assertOk();
    }

    public function test_accountants_resolve_edit_and_reopen_with_history_and_stale_write_protection(): void
    {
        [$company, $owner] = $this->book();
        $user = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($user, ['role' => 'accountant']);
        $id = app(ClientFollowUpService::class)->save($owner, $company->id, $this->payload());
        $this->actingAs($user);
        $route = route('follow-ups.update', [$company->id, $id]);
        $this->put($route, ['action' => 'edit', 'version' => 1, 'request_text' => 'Please provide the original invoice.', 'due_date' => '2026-10-30'])->assertRedirect();
        $this->putJson($route, ['action' => 'resolve', 'version' => 1, 'note' => 'Old version'])->assertConflict();
        $this->putJson($route, ['action' => 'resolve', 'version' => 2])->assertUnprocessable();
        $this->put($route, ['action' => 'resolve', 'version' => 2, 'note' => 'Received and uploaded to Documents.'])->assertRedirect();
        $this->assertDatabaseHas('client_follow_ups', ['id' => $id, 'status' => 'resolved', 'resolved_by' => $user->id, 'open_key' => null]);
        $this->put($route, ['action' => 'reopen', 'version' => 3, 'note' => 'The copy is not readable.'])->assertRedirect();
        $this->assertDatabaseHas('client_follow_ups', ['id' => $id, 'status' => 'open', 'version' => 4, 'resolved_at' => null]);
        $this->assertSame(['create', 'edit', 'resolve', 'reopen'], DB::table('client_follow_up_events')->orderBy('id')->pluck('action')->all());
        $this->assertSame(3, DB::table('client_follow_up_events')->where('actor_id', $user->id)->count());
    }

    public function test_foreign_or_missing_transactions_cannot_supply_details(): void
    {
        [$company, $user] = $this->book();
        [$foreign] = $this->book();
        $state = CompanyLedgerService::emptyState();
        $state['sales'] = [['id' => 'foreign-only', 'number' => 'SI-SECRET', 'date' => '2026-10-01', 'status' => 'posted', 'customer' => 'SECRET CUSTOMER', 'gross' => 50000]];
        CompanyLedger::factory()->create(['company_id' => $foreign->id, 'state' => $state]);
        $this->actingAs($user)->postJson(route('follow-ups.store', $company->id), $this->payload(['transaction' => 'sales:foreign-only']))->assertUnprocessable();
        $this->get(route('follow-ups.index', $company->id))->assertOk()->assertDontSee('SECRET CUSTOMER')->assertDontSee('SI-SECRET');
        $this->assertDatabaseCount('client_follow_ups', 0);
    }

    public function test_exports_default_to_open_filter_per_company_and_exclude_internal_history(): void
    {
        [$company, $user] = $this->book();
        [$foreign, $other] = $this->book();
        $service = app(ClientFollowUpService::class);
        $service->save($user, $company->id, $this->payload(['party' => '=1+1', 'request_text' => '=FORMULA', 'amount' => '1234.56', 'due_date' => '2026-10-30']));
        $id = $service->save($user, $company->id, $this->payload(['category' => 'form2307', 'request_text' => 'RESOLVED REQUEST']));
        $service->save($user, $company->id, ['action' => 'resolve', 'version' => 1, 'note' => 'INTERNAL SECRET NOTE'], $id);
        $service->save($other, $foreign->id, $this->payload(['request_text' => 'FOREIGN SECRET REQUEST']));
        $this->actingAs($user);
        $response = $this->get(route('follow-ups.export', [$company->id, 'csv']))->assertOk()->assertDownload();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=FORMULA", $csv);
        $this->assertStringContainsString('1234.56', $csv);
        foreach (['INTERNAL SECRET NOTE', 'RESOLVED REQUEST', 'FOREIGN SECRET REQUEST'] as $secret) {
            $this->assertStringNotContainsString($secret, $csv);
        }
        $resolved = $this->get(route('follow-ups.export', ['companyId' => $company->id, 'format' => 'csv', 'status' => 'resolved']))->assertOk()->streamedContent();
        $this->assertStringContainsString('RESOLVED REQUEST', $resolved);
        $this->assertStringNotContainsString('INTERNAL SECRET NOTE', $resolved);
        $this->get(route('follow-ups.index', ['companyId' => $company->id, 'q' => 'FORMULA']))->assertOk()->assertSee('=FORMULA')->assertDontSee('RESOLVED REQUEST');
        $pdf = $this->get(route('follow-ups.export', [$company->id, 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->get(route('my-work'))->assertOk()->assertViewHas('followUpCounts', [$company->id => 1]);
    }

    public function test_audit_failure_rolls_back_request_and_export_amounts_do_not_require_floats(): void
    {
        [$company, $user] = $this->book();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'client_follow_up_events')) {
                throw new \RuntimeException('Audit unavailable');
            }
        });
        try {
            app(ClientFollowUpService::class)->save($user, $company->id, $this->payload());
            $this->fail('An audit failure must roll back the request.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        }
        $this->assertDatabaseCount('client_follow_ups', 0);
        $this->assertDatabaseCount('client_follow_up_events', 0);
        $this->assertSame('999,999,999.99', ClientFollowUpService::money('999999999.9900'));
    }

    public function test_preview_is_read_only_and_unavailable_in_production(): void
    {
        $this->get(route('workspace.preview', ['page' => 'follow-ups']))->assertOk()->assertSee('One list. Fewer follow-ups')->assertSee('<div inert>', false)->assertSee('Sample Office Supplies');
        $this->assertDatabaseCount('client_follow_ups', 0);
        $this->app->instance('env', 'production');
        $this->get(route('workspace.preview', ['page' => 'follow-ups']))->assertNotFound();
    }

    /** @return array{Company, User} */
    private function book(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->firm()->create();
        $company->users()->attach($user, ['role' => 'owner']);

        return [$company, $user];
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [...['action' => 'create', 'request_id' => (string) Str::uuid(), 'category' => 'receipt', 'request_text' => 'Please provide the supplier invoice.', 'transaction_date' => '2026-10-19', 'reference' => 'REF-001', 'party' => 'Example Supplier', 'amount' => '1120.00'], ...$overrides];

    }
}
