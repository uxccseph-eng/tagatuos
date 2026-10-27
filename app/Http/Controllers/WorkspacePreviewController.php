<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\InboxDocument;
use App\Models\MonthlyClosing;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;

class WorkspacePreviewController extends Controller
{
    public function show(string $page): Response
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        if (in_array($page, ['guided-import', 'payroll-import', 'tax-preflight'], true)) {
            return response()->view($page.'-preview')->header('Cache-Control', 'no-store, private');
        }
        abort_unless(in_array($page, ['my-work', 'documents', 'document-review', 'closing', 'lark-backup', 'manual-purge', 'follow-ups'], true), 404);
        $accountant = new User(['name' => 'Sample Accountant']);
        $accountant->id = 0;
        $company = new Company(['name' => 'Sample Trading Company']);
        $company->forceFill(['id' => 0, 'workspace_key' => str_repeat('a', 64)]);
        $company->setRelation('pivot', new Pivot(['role' => 'manager']));
        $closing = new MonthlyClosing;
        $closing->forceFill(['company_id' => 0, 'month' => '2026-10', 'status' => 'for_review', 'assignee_id' => 0, 'version' => 1, 'checklist' => array_fill_keys(array_keys(MonthlyClosing::CHECKLIST), true), 'notes' => 'Sample review: bank balances reconciled; supporting documents attached. Ready for manager review.']);
        $closing->setRelation('assignee', $accountant);
        $documents = collect();
        foreach (['needs_entry' => 'Sample-office-supplies.png', 'received' => 'Sample-utilities.pdf', 'linked' => 'Sample-sales-invoice.pdf', 'needs_clarification' => 'Sample-delivery-receipt.jpg'] as $status => $filename) {
            $document = new InboxDocument;
            $document->forceFill(['id' => 0, 'company_id' => 0, 'original_name' => $filename, 'mime_type' => 'image/png', 'size' => 102400, 'category' => 'purchases', 'status' => $status, 'version' => 1, 'created_at' => '2026-10-26 02:00:00']);
            $document->setRelation('uploader', $accountant);
            $document->setRelation('assignee', $accountant);
            $documents->push($document);
        }
        $common = ['previewMode' => true, 'company' => $company];
        $data = match ($page) {
            'follow-ups' => [
                'companyChoices' => collect([$company]), 'filters' => [], 'editing' => null, 'counts' => ['open' => 2, 'resolved' => 1], 'overdue' => 1,
                'transactions' => [['key' => 'purchases:sample-one', 'label' => 'BILL-000012 · Sample Office Supplies · 2026-10-19', 'date' => '2026-10-19']],
                'items' => new LengthAwarePaginator(collect([
                    (object) ['id' => 1, 'category' => 'receipt', 'status' => 'open', 'transaction_number' => 'BILL-000012', 'transaction_date' => '2026-10-19', 'reference' => 'SAMPLE-001', 'party' => 'Sample Office Supplies', 'amount' => '1120.0000', 'request_text' => 'Please provide a clear copy of the supplier invoice for this office supplies payment.', 'due_date' => '2026-10-25', 'linked_sheet' => 'purchases', 'version' => 1, 'resolution_note' => null],
                    (object) ['id' => 2, 'category' => 'form2307', 'status' => 'open', 'transaction_number' => 'SI-000018', 'transaction_date' => '2026-10-21', 'reference' => 'SAMPLE-002', 'party' => 'Sample Customer', 'amount' => '22400.0000', 'request_text' => 'Please send Form 2307 covering the withholding on this payment.', 'due_date' => '2026-10-30', 'linked_sheet' => 'sales', 'version' => 1, 'resolution_note' => null],
                ]), 2, 20),
                'events' => collect([1 => collect([(object) ['created_at' => '2026-10-26 09:00:00', 'actor_name' => 'Sample Accountant', 'action' => 'create', 'note' => null]])]),
            ],
            'manual-purge' => ['batch' => (object) ['id' => '00000000-0000-4000-8000-000000000001', 'status' => 'ready', 'expires_at' => now()->addMinutes(15), 'items' => [['id' => 0, 'original_name' => 'Sample-office-supplies.png', 'size' => 102400], ['id' => 0, 'original_name' => 'Sample-utilities.pdf', 'size' => 204800]], 'outcomes' => []]],
            'lark-backup' => ['connection' => ['app_id' => null, 'folder_token' => null, 'has_secret' => false, 'enabled' => false, 'verified_at' => null, 'version' => 0, 'locked' => false], 'backupCounts' => [], 'backupEvents' => collect()],
            'my-work' => [
                'companies' => new LengthAwarePaginator([$company], 1, 12), 'summary' => ['books' => 1, 'ready' => 1, 'drafts' => 2, 'posted' => 18],
                'closingMonth' => '2026-10', 'closingCounts' => ['not_started' => 0, 'in_progress' => 0, 'for_review' => 1, 'completed' => 0],
                'configuredWorkspaces' => [$company->workspaceKey()], 'draftCounts' => [0 => 2], 'closings' => collect([0 => $closing]), 'sheetCompanyIds' => [0], 'activityCompanyIds' => [0],
                'lastPosted' => [0 => '2026-10-26 02:00:00'], 'pendingDocuments' => 3, 'setupNeeded' => 0, 'canManageTeam' => true,
                'followUpCounts' => [0 => 2],
                'recentActivity' => collect([(object) ['number' => 'SI-000018', 'company_name' => $company->name, 'actor_name' => $accountant->name, 'created_at' => '2026-10-26 02:00:00']]),
            ],
            'documents' => ['documents' => new LengthAwarePaginator($documents, 4, 20), 'counts' => array_fill_keys(['received', 'needs_entry', 'linked', 'needs_clarification'], 1)],
            'document-review' => [
                'document' => $documents->first(), 'members' => collect([$accountant]), 'events' => collect(), 'transactions' => collect(), 'linkedRecord' => null,
                'ledgerRevision' => 0, 'ledgerInitialized' => true, 'ledgerBrowserKey' => 'presentation-only',
                'parties' => collect([['id' => 0, 'code' => 'SUP-001', 'name' => 'Sample Office Supplies', 'classification' => 'Supplier', 'tin' => '000-000-000-00000', 'address' => 'Demonstration address']]),
                'accounts' => collect([['id' => 0, 'code' => '6100', 'name' => 'Office Supplies Expense', 'type' => 'Expense']]),
                'taxCodes' => collect([(object) ['id' => 0, 'code' => 'VAT12', 'name' => 'VAT 12%']]), 'sourceUrl' => route('workspace.preview-document'),
                'sampleEntry' => ['party_id' => 0, 'account_id' => 0, 'tax_code_id' => 0, 'date' => '2026-10-26', 'gross' => '1120.00', 'reference' => 'SAMPLE-001', 'description' => 'Office supplies · sample purchase'],
            ],
            'closing' => [
                'closing' => $closing, 'month' => '2026-10', 'members' => collect([$accountant]), 'canEdit' => true,
                'events' => new LengthAwarePaginator([(object) ['action' => 'for_review', 'actor_name' => $accountant->name, 'created_at' => '2026-10-26 02:00:00', 'reason' => null]], 1, 15),
            ],
        };
        $view = match ($page) {
            'documents' => 'document-inbox', 'closing' => 'monthly-closing', 'lark-backup' => 'document-backup-settings', 'manual-purge' => 'document-purge', 'follow-ups' => 'client-follow-ups', default => $page,
        };

        return response()->view($view, [...$common, ...$data])->header('Cache-Control', 'no-store, private');
    }

    public function sampleDocument(): Response
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        return response()->view('sample-document')->header('Content-Type', 'image/svg+xml')->header('Cache-Control', 'no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
