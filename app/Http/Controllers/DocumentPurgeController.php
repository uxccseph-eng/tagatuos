<?php

namespace App\Http\Controllers;

use App\Models\InboxDocument;
use App\Services\DocumentPurgeService;
use App\Services\LarkDocumentBackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class DocumentPurgeController extends Controller
{
    public function index(Request $request, int $companyId, LarkDocumentBackupService $lark): Response
    {
        $company = $lark->owner($request->user(), $companyId);
        $candidates = InboxDocument::query()->where('company_id', $companyId)->whereNull('local_purged_at')->whereNull('local_purge_batch_id')->where('lark_status', 'backed_up')->whereNotNull('lark_file_token')->where('lark_backed_up_at', '<=', now()->subDays(DocumentPurgeService::GRACE_DAYS))->latest('id')->paginate(25);
        $recentBatches = DB::connection(config('tenancy.database.central_connection'))->table('document_purge_batches')->where('company_id', $companyId)->where('created_by', $request->user()->id)->latest('created_at')->limit(10)->get();

        return response()->view('document-purge', ['company' => $company, 'candidates' => $candidates, 'recentBatches' => $recentBatches, 'batch' => null])->header('Cache-Control', 'no-store, private');
    }

    public function prepare(Request $request, int $companyId, DocumentPurgeService $service, LarkDocumentBackupService $lark): RedirectResponse
    {
        $lark->owner($request->user(), $companyId);
        $data = $request->validate(['document_ids' => ['required', 'array', 'min:1', 'max:5'], 'document_ids.*' => ['required', 'integer', 'min:1', 'distinct']]);
        $id = $service->prepare($request->user(), $companyId, array_map('intval', $data['document_ids']));

        return to_route('documents.purge.show', ['companyId' => $companyId, 'batchId' => $id]);
    }

    public function show(Request $request, int $companyId, string $batchId, DocumentPurgeService $service, LarkDocumentBackupService $lark): Response
    {
        $company = $lark->owner($request->user(), $companyId);

        return response()->view('document-purge', ['company' => $company, 'batch' => $service->batch($request->user(), $companyId, $batchId)])->header('Cache-Control', 'no-store, private');
    }

    public function confirm(Request $request, int $companyId, string $batchId, DocumentPurgeService $service, LarkDocumentBackupService $lark): RedirectResponse
    {
        $lark->owner($request->user(), $companyId);
        $data = $request->validate(['confirmation' => ['required', 'in:PURGE LOCAL COPIES'], 'acknowledge' => ['required', 'accepted']]);
        $service->confirm($request->user(), $companyId, $batchId, $data['confirmation'], $request->boolean('acknowledge'));

        return to_route('documents.purge.show', ['companyId' => $companyId, 'batchId' => $batchId])->with('status', 'Manual purge approval processed. Review the per-document results below.');
    }
}
