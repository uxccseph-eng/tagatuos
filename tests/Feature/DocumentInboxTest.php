<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\InboxDocument;
use App\Models\Party;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\CompanyLedgerService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Http::preventStrayRequests();
    }

    public function test_upload_stays_private_and_can_be_previewed_only_by_authorized_company_members(): void
    {
        [$user, $company] = $this->book();
        $document = $this->upload($user, $company);
        Storage::disk('documents')->assertExists($document->path);
        $this->assertStringStartsWith($company->id.'/', $document->path);
        $this->assertArrayNotHasKey('path', $document->toArray());
        $fileUrl = route('documents.file', ['companyId' => $company->id, 'documentId' => $document->id]);
        $this->get($fileUrl)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff')->assertStreamedContent("%PDF-1.4\nTest invoice");
        $this->get($fileUrl.'?download=1')->assertDownload('invoice.pdf');
        $this->get(route('documents.show', ['companyId' => $company->id, 'documentId' => $document->id]))->assertOk()->assertSee('Create a draft entry')->assertSee('No document is sent to an OCR service.');
        [$foreignUser, $foreign] = $this->book();
        $this->actingAs($foreignUser)->get($fileUrl)->assertNotFound();
        $this->get(route('documents.file', ['companyId' => $foreign->id, 'documentId' => $document->id]))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_identical_files_are_rejected_including_archived_copies_but_not_across_companies(): void
    {
        [$user, $company] = $this->book();
        $document = $this->upload($user, $company);
        $this->postJson(route('documents.store', $company->id), ['file' => $this->file(), 'category' => 'sales'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->putJson($this->url($document), ['version' => 1, 'action' => 'metadata', 'status' => 'archived', 'notes' => 'Keep source'])->assertRedirect();
        $this->postJson(route('documents.store', $company->id), ['file' => $this->file(), 'category' => 'sales'])->assertUnprocessable();
        [$otherUser, $otherCompany] = $this->book();
        $this->upload($otherUser, $otherCompany);
        $this->assertDatabaseCount('inbox_documents', 2);
        $this->assertCount(2, Storage::disk('documents')->allFiles());
    }

    public function test_html_disguised_files_and_oversized_files_are_rejected(): void
    {
        [$user, $company] = $this->book();
        $this->actingAs($user);
        foreach ([UploadedFile::fake()->createWithContent('bad.pdf', '<html><script>alert(1)</script></html>')->mimeType('text/html'), UploadedFile::fake()->createWithContent('invoice.php', "%PDF-1.4\nTest"), UploadedFile::fake()->create('large.pdf', 2049, 'application/pdf')] as $file) {
            $this->postJson(route('documents.store', $company->id), ['file' => $file, 'category' => 'purchases'])->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->assertDatabaseCount('inbox_documents', 0);
        Storage::disk('documents')->assertDirectoryEmpty('/');
    }

    public function test_manual_purchase_creates_one_draft_attaches_source_and_opens_the_correct_company_sheet(): void
    {
        [$user, $company] = $this->book();
        [$party, $account, $tax] = $this->masters($company);
        $document = $this->upload($user, $company);
        $this->putJson($this->url($document), $this->entry($party, $account, $tax))->assertRedirect();
        $document->refresh();
        $record = CompanyLedger::query()->sole()->state['purchases'][0];
        $this->assertSame('draft', $record['status']);
        $this->assertSame(50000, $record['gross']);
        $this->assertSame($party->id, $record['partyId']);
        $this->assertSame('', $record['number']);
        $this->assertSame('linked', $document->status);
        $this->assertSame((string) $record['id'], $document->linked_record_id);
        $this->assertStringContainsString('/account/companies/'.$company->id.'/documents/'.$document->id.'/file', $record['attachments'][0]['data']);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('company_ledger_changes', 1);
        $this->get(route('documents.show', ['companyId' => $company->id, 'documentId' => $document->id]))->assertOk()->assertSee('Draft')->assertDontSee('BILL-000001')->assertDontSee('id="document-entry-form"', false);
        $this->post(route('documents.open-entry', ['companyId' => $company->id, 'documentId' => $document->id]))->assertRedirect(route('sheets.purchases', ['company_id' => $company->id, 'entry' => $record['id'], 'period' => '2026-10']))->assertSessionHas('active_company_id', $company->id);
    }

    public function test_sales_uses_customer_and_income_masters_and_rejects_foreign_ids(): void
    {
        [$user, $company] = $this->book();
        [$party, $account, $tax] = $this->masters($company, true);
        [, $foreign] = $this->book();
        [$foreignParty] = $this->masters($foreign, true);
        $document = $this->upload($user, $company);
        $data = [...$this->entry($party, $account, $tax), 'sheet' => 'sales'];
        $this->putJson($this->url($document), [...$data, 'party_id' => $foreignParty->id])->assertUnprocessable()->assertJsonValidationErrors('party_id');
        $this->putJson($this->url($document), $data)->assertRedirect();
        $record = CompanyLedger::query()->sole()->state['sales'][0];
        $this->assertSame('', $record['number']);
        $this->assertSame($party->name, $record['customer']);
        $this->assertSame('None', $record['cwt']);
        $this->assertDatabaseMissing('company_ledgers', ['company_id' => $foreign->id]);
    }

    public function test_existing_draft_limit_is_enforced_and_can_be_linked_instead(): void
    {
        [$user, $company] = $this->book();
        [$party, $account, $tax] = $this->masters($company);
        $first = $this->upload($user, $company);
        $this->putJson($this->url($first), $this->entry($party, $account, $tax))->assertRedirect();
        $record = CompanyLedger::query()->sole()->state['purchases'][0];
        $second = $this->upload($user, $company, 'Second file');
        $data = [...$this->entry($party, $account, $tax), 'ledger_revision' => 1, 'reference' => 'DIFFERENT'];
        $this->putJson($this->url($second), $data)->assertUnprocessable()->assertJsonValidationErrors('sheet');
        $this->putJson($this->url($second), ['version' => 1, 'action' => 'link', 'ledger_revision' => 1, 'transaction' => 'purchases:'.$record['id']])->assertRedirect();
        $rows = CompanyLedger::query()->sole()->state['purchases'];
        $this->assertCount(1, $rows);
        $this->assertCount(2, $rows[0]['attachments']);
        $this->assertSame('linked', $second->fresh()->status);
        $this->putJson($this->url($second), ['version' => 2, 'action' => 'unlink', 'ledger_revision' => 2])->assertRedirect();
        $this->assertCount(1, CompanyLedger::query()->sole()->state['purchases'][0]['attachments']);
        $this->assertSame('needs_entry', $second->fresh()->status);
    }

    public function test_duplicate_invoice_is_blocked_even_when_a_different_file_is_uploaded(): void
    {
        [$user, $company] = $this->book();
        [$party, $account, $tax] = $this->masters($company, true);
        $document = $this->upload($user, $company);
        $data = [...$this->entry($party, $account, $tax), 'sheet' => 'sales'];
        $this->putJson($this->url($document), $data)->assertRedirect();
        $ledger = CompanyLedger::query()->sole();
        $state = $ledger->state;
        $state['sales'][0]['status'] = 'posted';
        $state['sales'][0]['number'] = 'SI-000001';
        app(CompanyLedgerService::class)->save($company, $user, ['state' => $state, 'payroll' => [], 'revision' => 1, 'request_id' => (string) Str::uuid()]);
        $second = $this->upload($user, $company, 'Different scan');
        $this->putJson($this->url($second), [...$data, 'ledger_revision' => 2])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->assertSame('received', $second->fresh()->status);
        $this->assertCount(1, CompanyLedger::query()->sole()->state['sales']);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->putJson($this->url($second), ['version' => 1, 'action' => 'link', 'ledger_revision' => 2, 'transaction' => 'sales:'.$state['sales'][0]['id']])->assertRedirect();
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_document_and_book_revisions_protect_against_conflicting_saves(): void
    {
        [$user, $company] = $this->book();
        [$party, $account, $tax] = $this->masters($company);
        $document = $this->upload($user, $company);
        $this->putJson($this->url($document), ['version' => 1, 'action' => 'metadata', 'status' => 'needs_clarification', 'notes' => 'Need a clearer amount'])->assertRedirect();
        $this->putJson($this->url($document), $this->entry($party, $account, $tax))->assertConflict();
        CompanyLedger::factory()->create(['company_id' => $company->id, 'revision' => 1]);
        $this->putJson($this->url($document), [...$this->entry($party, $account, $tax), 'version' => 2])->assertConflict();
        $this->assertSame('Need a clearer amount', $document->fresh()->notes);
        $this->assertDatabaseCount('company_ledger_changes', 0);
    }

    public function test_suspended_restricted_and_foreign_members_cannot_access_or_change_source_documents(): void
    {
        [$user, $company] = $this->book();
        $document = $this->upload($user, $company);
        $staff = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($staff, ['role' => 'staff', 'allowed_tabs' => json_encode(['dashboard', 'books'])]);
        $url = route('documents.show', ['companyId' => $company->id, 'documentId' => $document->id]);
        $this->actingAs($staff)->get($url)->assertForbidden();
        $this->putJson($this->url($document), ['version' => 1, 'action' => 'metadata', 'status' => 'archived'])->assertForbidden();
        $company->users()->updateExistingPivot($staff->id, ['allowed_tabs' => json_encode(['dashboard', 'sheets']), 'is_suspended' => true]);
        $this->get($url)->assertNotFound();
        $this->assertSame('received', $document->fresh()->status);
    }

    public function test_foreign_transaction_identity_cannot_be_linked_and_assignment_is_company_scoped(): void
    {
        [$user, $company] = $this->book();
        [$foreignUser] = $this->book();
        $document = $this->upload($user, $company);
        $this->putJson($this->url($document), ['version' => 1, 'action' => 'link', 'ledger_revision' => 0, 'transaction' => 'sales:foreign-id'])->assertUnprocessable();
        $this->putJson($this->url($document), ['version' => 1, 'action' => 'metadata', 'status' => 'needs_entry', 'assigned_to' => $foreignUser->id])->assertUnprocessable();
        $this->assertDatabaseCount('company_ledgers', 0);
    }

    public function test_upload_audit_failure_removes_new_file_and_rolls_back_database_record(): void
    {
        [$user, $company] = $this->book();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'inbox_document_events')) {
                throw new \RuntimeException('Audit unavailable');
            }
        });
        $this->actingAs($user)->post(route('documents.store', $company->id), ['file' => $this->file(), 'category' => 'purchases'])->assertStatus(500);
        $this->assertDatabaseCount('inbox_documents', 0);
        Storage::disk('documents')->assertDirectoryEmpty('/');
    }

    public function test_failed_link_audit_rolls_back_the_draft_document_and_ledger_history_together(): void
    {
        [$user, $company] = $this->book();
        [$party, $account, $tax] = $this->masters($company);
        $document = $this->upload($user, $company);
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'inbox_document_events')) {
                throw new \RuntimeException('Audit unavailable');
            }
        });
        $this->putJson($this->url($document), $this->entry($party, $account, $tax))->assertStatus(500);
        $this->assertSame('received', $document->fresh()->status);
        $this->assertSame(1, $document->fresh()->version);
        $this->assertDatabaseCount('company_ledgers', 0);
        $this->assertDatabaseCount('company_ledger_changes', 0);
        $this->assertDatabaseCount('inbox_document_events', 1);
        Storage::disk('documents')->assertExists($document->path);
    }

    public function test_manual_entry_rejects_foreign_or_archived_accounts_and_tax_codes(): void
    {
        [$user, $company] = $this->book();
        [$party, $account, $tax] = $this->masters($company);
        [, $other] = $this->book();
        [, $otherAccount, $otherTax] = $this->masters($other);
        $document = $this->upload($user, $company);
        $data = $this->entry($party, $account, $tax);
        $this->putJson($this->url($document), [...$data, 'account_id' => $otherAccount->id])->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->putJson($this->url($document), [...$data, 'tax_code_id' => $otherTax->id])->assertUnprocessable()->assertJsonValidationErrors('tax_code_id');
        $account->update(['archived' => true]);
        $this->putJson($this->url($document), $data)->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->assertDatabaseCount('company_ledgers', 0);
    }

    public function test_upload_requires_authentication_and_csrf_and_reading_does_not_create_drafts(): void
    {
        [$user, $company] = $this->book();
        $this->get(route('documents.index', $company->id))->assertRedirect(route('login'));
        $document = $this->upload($user, $company);
        $this->get(route('documents.index', $company->id))->assertOk()->assertSee('invoice.pdf');
        $this->get(route('documents.show', ['companyId' => $company->id, 'documentId' => $document->id]))->assertOk();
        $this->assertDatabaseCount('company_ledgers', 0);
        $this->app->instance('env', 'production');
        $this->post(route('documents.store', $company->id), ['file' => $this->file(), 'category' => 'sales'])->assertStatus(419);
    }

    /** @return array{User, Company} */
    private function book(): array
    {
        $user = User::factory()->firm()->create();
        $company = Company::factory()->create();
        $company->users()->attach($user, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey()]);

        return [$user, $company];
    }

    /** @return array{Party, ChartAccount, TaxCode} */
    private function masters(Company $company, bool $sales = false): array
    {
        $scope = $company->workspaceKey();

        return [Party::factory()->create(['workspace_key' => $scope, 'classification' => $sales ? 'Customer' : 'Supplier']), ChartAccount::factory()->create(['workspace_key' => $scope, 'code' => $sales ? '4020' : '6110', 'type' => $sales ? 'Income' : 'Expense', 'ewt' => false, 'ewt_atc_code' => null]), TaxCode::factory()->create(['workspace_key' => $scope, 'code' => 'VAT', 'kind' => 'VAT', 'rate' => 12])];
    }

    private function upload(User $user, Company $company, string $content = 'Test invoice'): InboxDocument
    {
        $this->actingAs($user)->post(route('documents.store', $company->id), ['file' => $this->file($content), 'category' => 'purchases'])->assertRedirect()->assertSessionHasNoErrors();

        return InboxDocument::query()->where('company_id', $company->id)->latest('id')->firstOrFail();
    }

    private function file(string $content = 'Test invoice'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n".$content);
    }

    private function url(InboxDocument $document): string
    {
        return route('documents.update', ['companyId' => $document->company_id, 'documentId' => $document->id]);
    }

    /** @return array<string, mixed> */
    private function entry(Party $party, ChartAccount $account, TaxCode $tax): array
    {
        return ['version' => 1, 'action' => 'create_draft', 'ledger_revision' => 0, 'sheet' => 'purchases', 'date' => '2026-10-26', 'party_id' => $party->id, 'account_id' => $account->id, 'tax_code_id' => $tax->id, 'gross' => '500.00', 'reference' => 'INV-123', 'description' => ''];
    }
}
