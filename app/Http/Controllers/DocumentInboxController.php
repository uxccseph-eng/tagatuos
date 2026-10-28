<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateInboxDocumentRequest;
use App\Http\Requests\UploadInboxDocumentRequest;
use App\Models\ChartAccount;
use App\Models\CompanyLedger;
use App\Models\InboxDocument;
use App\Models\Party;
use App\Models\TaxCode;
use App\Services\CompanyBookService;
use App\Services\CompanyLedgerService;
use App\Services\DocumentInboxService;
use App\Services\LarkDocumentBackupService;
use App\TeamPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentInboxController extends Controller
{
    public function entry(Request $request, CompanyBookService $books): RedirectResponse
    {
        $companies = $books->accessibleBooks($request->user())->get()->filter(fn ($company): bool => in_array('sheets', TeamPermissions::allowed($company->pivot->role, $company->pivot->allowed_tabs), true));
        $company = $companies->firstWhere('id', (int) $request->session()->get('active_company_id')) ?? $companies->first();

        return $company ? to_route('documents.index', ['companyId' => $company->id]) : to_route('account.dashboard')->with('status', 'Open an assigned company with Sheets access to use the Document Inbox.');
    }

    public function index(Request $request, int $companyId, DocumentInboxService $service): Response
    {
        $company = $service->company($request->user(), $companyId);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(array_keys(InboxDocument::STATUSES))]]);
        $query = InboxDocument::query()->where('company_id', $companyId);
        $counts = (clone $query)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->all();
        if ($filters['status'] ?? null) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', '!=', 'archived');
        }
        if ($filters['q'] ?? null) {
            $query->whereRaw("LOWER(original_name) LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['q'])).'%']);
        }

        return response()->view('document-inbox', ['company' => $company, 'documents' => $query->with(['assignee:id,name', 'uploader:id,name'])->latest('id')->paginate(20)->withQueryString(), 'counts' => $counts])->header('Cache-Control', 'private, no-store');
    }

    public function store(UploadInboxDocumentRequest $request, int $companyId, DocumentInboxService $service): RedirectResponse
    {
        $document = $service->upload($request->user(), $companyId, $request->file('file'), $request->validated());

        return to_route('documents.show', ['companyId' => $companyId, 'documentId' => $document->id])->with('status', 'Document uploaded privately. Review it before creating or linking an entry.');
    }

    public function show(Request $request, int $companyId, int $documentId, DocumentInboxService $service): Response
    {
        $company = $service->company($request->user(), $companyId);
        $document = $service->document($company, $documentId)->load(['uploader:id,name', 'assignee:id,name']);
        $filters = $request->validate(['txn_q' => ['nullable', 'string', 'max:100']]);
        $ledger = CompanyLedger::query()->where('company_id', $companyId)->first();
        $state = $ledger?->state ?? CompanyLedgerService::emptyState();
        $transactions = collect();
        $linkedRecord = null;
        foreach ([...CompanyLedgerService::SHEETS, 'payroll'] as $sheet) {
            foreach ($sheet === 'payroll' ? ($ledger?->payroll ?? []) : $state[$sheet] as $record) {
                if ($document->linked_sheet === $sheet && $document->linked_record_id === (string) $record['id']) {
                    $linkedRecord = $record;
                }
                if (! in_array($record['status'], ['posted', 'draft'], true)) {
                    continue;
                }
                $label = ($record['status'] === 'draft' ? 'Draft' : $record['number']).' · '.($record['customer'] ?? $record['supplier'] ?? ucfirst($sheet)).' · '.$record['date'].' · '.$record['status'];
                if (($filters['txn_q'] ?? '') !== '' && ! str_contains(mb_strtolower($label), mb_strtolower($filters['txn_q']))) {
                    continue;
                }
                $transactions->push(['key' => $sheet.':'.$record['id'], 'label' => $label, 'date' => $record['date']]);
            }
        }
        $members = $company->users()->select(['users.id', 'users.name'])->where('registration_status', 'approved')->where('must_change_password', false)->wherePivot('is_suspended', false)->orderBy('users.name')->get()->filter(fn ($member): bool => in_array('sheets', TeamPermissions::allowed($member->pivot->role, $member->pivot->allowed_tabs), true));
        $events = DB::connection(config('tenancy.database.central_connection'))->table('inbox_document_events')->leftJoin('users', 'users.id', '=', 'inbox_document_events.actor_id')->where('inbox_document_id', $documentId)->where('company_id', $companyId)->select(['inbox_document_events.action', 'inbox_document_events.created_at', 'users.name AS actor_name'])->latest('inbox_document_events.id')->limit(15)->get();

        return response()->view('document-review', ['company' => $company, 'document' => $document, 'members' => $members, 'events' => $events, 'transactions' => $transactions->sortByDesc('date')->take(200), 'linkedRecord' => $linkedRecord, 'ledgerRevision' => $ledger?->revision ?? 0, 'ledgerInitialized' => $ledger !== null, 'ledgerBrowserKey' => 'company-'.hash_hmac('sha256', 'company-browser:'.$companyId.'|'.$request->user()->id, config('app.key')),
            'parties' => Party::query()->where('workspace_key', $company->workspaceKey())->where('archived', false)->orderBy('name')->get(['id', 'code', 'name', 'classification', 'tin', 'address']),
            'accounts' => ChartAccount::query()->where('workspace_key', $company->workspaceKey())->where('archived', false)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'taxCodes' => TaxCode::query()->where('workspace_key', $company->workspaceKey())->where('archived', false)->whereIn('kind', ['VAT', 'Exempt', 'Zero Rated'])->orderBy('code')->get(['id', 'code', 'name', 'rate']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(UpdateInboxDocumentRequest $request, int $companyId, int $documentId, DocumentInboxService $service): RedirectResponse
    {
        $service->update($request->user(), $companyId, $documentId, $request->validated());

        return to_route('documents.show', ['companyId' => $companyId, 'documentId' => $documentId])->with('status', $request->input('action') === 'create_draft' ? 'Draft saved and document linked. Review the entry in Sheets before posting.' : 'Document updated.');
    }

    public function file(Request $request, int $companyId, int $documentId, DocumentInboxService $service, LarkDocumentBackupService $lark): StreamedResponse
    {
        $document = $service->document($service->company($request->user(), $companyId), $documentId);
        $headers = ['Content-Type' => $document->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN', 'Content-Security-Policy' => "frame-ancestors 'self'; base-uri 'none'"];

        if (! Storage::disk('documents')->exists($document->path)) {
            abort_unless($document->lark_status === 'backed_up', 404);
            try {
                $bytes = $lark->verifiedDownload($document);
            } catch (\Throwable) {
                abort(503, 'The Lark document could not be verified or retrieved. Contact the book owner to restore access.');
            }

            return response()->streamDownload(function () use ($bytes): void {
                echo $bytes;
            }, $document->original_name, [...$headers, 'Content-Length' => (string) strlen($bytes)], $request->boolean('download') ? 'attachment' : 'inline');
        }

        return Storage::disk('documents')->response($document->path, $document->original_name, $headers, $request->boolean('download') ? 'attachment' : 'inline');
    }

    public function openEntry(Request $request, int $companyId, int $documentId, DocumentInboxService $service): RedirectResponse
    {
        $document = $service->document($service->company($request->user(), $companyId), $documentId);
        abort_unless($document->linked_record_id !== null, 404);
        $sheet = match ($document->linked_sheet) {
            'cwtCertificates' => 'cwt', 'wtpCertificates' => 'wtp', 'journalEntries' => 'journal', default => $document->linked_sheet
        };
        abort_unless(in_array($sheet, ['sales', 'purchases', 'collections', 'payments', 'cwt', 'wtp', 'journal', 'payroll'], true), 404);
        $ledger = CompanyLedger::query()->where('company_id', $companyId)->first();
        $record = collect($document->linked_sheet === 'payroll' ? ($ledger?->payroll ?? []) : ($ledger?->state[$document->linked_sheet] ?? []))->first(fn (array $record): bool => (string) $record['id'] === $document->linked_record_id);
        abort_unless($record, 404, 'The linked transaction is no longer available.');
        $request->session()->regenerate();
        $request->session()->put('active_company_id', $companyId);
        $request->session()->forget(['company_setup', 'preview_access']);

        return to_route('sheets.'.$sheet, ['entry' => $document->linked_record_id, 'period' => substr($record['date'], 0, 7)]);
    }
}
