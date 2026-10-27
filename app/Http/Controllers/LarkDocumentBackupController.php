<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveLarkDocumentBackupRequest;
use App\Models\InboxDocument;
use App\Services\LarkDocumentBackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class LarkDocumentBackupController extends Controller
{
    public function show(Request $request, int $companyId, LarkDocumentBackupService $service): Response
    {
        $company = $service->owner($request->user(), $companyId);

        return response()->view('document-backup-settings', ['company' => $company, 'connection' => $service->configuration($companyId), 'backupEvents' => DB::table('document_backup_events')->leftJoin('users', 'users.id', '=', 'document_backup_events.actor_id')->where('company_id', $companyId)->select(['document_backup_events.action', 'document_backup_events.created_at', 'users.name AS actor_name'])->latest('document_backup_events.id')->limit(15)->get(), 'backupCounts' => InboxDocument::query()->where('company_id', $companyId)->selectRaw('lark_status, COUNT(*) AS total')->groupBy('lark_status')->pluck('total', 'lark_status')->all()])->header('Cache-Control', 'no-store, private');
    }

    public function update(SaveLarkDocumentBackupRequest $request, int $companyId, LarkDocumentBackupService $service): RedirectResponse
    {
        $service->saveConfiguration($request->user(), $companyId, $request->validated());

        return to_route('documents.backup.show', ['companyId' => $companyId])->with('status', 'Lark backup settings saved. Existing files are copied only when you queue them below.');
    }

    public function verify(Request $request, int $companyId, LarkDocumentBackupService $service): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $service->verify($request->user(), $companyId, (int) $data['version']);

        return to_route('documents.backup.show', ['companyId' => $companyId])->with('status', 'Lark authentication and folder listing succeeded. Upload permission is checked when a backup runs. You may now enable backups.');
    }

    public function queue(Request $request, int $companyId, LarkDocumentBackupService $service): RedirectResponse
    {
        $count = $service->queueExisting($request->user(), $companyId);

        return to_route('documents.backup.show', ['companyId' => $companyId])->with('status', $count.' documents queued. The scheduled backup worker will copy them to Lark.');
    }
}
