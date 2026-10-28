<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveClientFollowUpRequest;
use App\Models\CompanyLedger;
use App\Services\ClientFollowUpService;
use App\Services\CompanyBookService;
use App\TeamPermissions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientFollowUpController extends Controller
{
    public function entry(Request $request, CompanyBookService $books): RedirectResponse
    {
        $companies = $this->companies($request, $books);
        $company = $companies->firstWhere('id', (int) $request->session()->get('active_company_id')) ?? $companies->first();

        return $company ? to_route('follow-ups.index', ['companyId' => $company->id]) : to_route('account.dashboard')->with('status', 'Open an assigned company with Sheets access to use client follow-ups.');
    }

    public function index(Request $request, int $companyId, ClientFollowUpService $service, CompanyBookService $books): Response
    {
        $company = $service->company($request->user(), $companyId);
        $filters = $this->filters($request);
        $request->validate(['edit' => ['nullable', 'integer', 'min:1'], 'txn_q' => ['nullable', 'string', 'max:100'], 'transaction' => ['nullable', 'string', 'max:140']]);
        $transactions = $service->transactions($companyId, $request->input('txn_q') ?? '');
        $preselected = $request->input('transaction');
        if ($preselected && ! collect($transactions)->contains('key', $preselected)) {
            throw ValidationException::withMessages(['transaction' => 'The selected entry is no longer available. Search this company’s saved transactions.']);
        }
        $items = $service->query($companyId, $filters)->orderBy('status')->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date')->orderByDesc('id')->paginate(20)->withQueryString();
        $events = DB::connection(config('tenancy.database.central_connection'))->table('client_follow_up_events')->leftJoin('users', 'users.id', '=', 'client_follow_up_events.actor_id')->where('company_id', $companyId)->whereIn('client_follow_up_id', $items->pluck('id'))->select(['client_follow_up_events.*', 'users.name AS actor_name'])->latest('client_follow_up_events.id')->limit(100)->get()->groupBy('client_follow_up_id');

        return response()->view('client-follow-ups', [
            'company' => $company, 'companyChoices' => $this->companies($request, $books), 'items' => $items, 'events' => $events,
            'filters' => $filters, 'transactions' => $transactions, 'preselectedTransaction' => $preselected, 'editing' => $request->filled('edit') ? $service->item($companyId, (int) $request->input('edit')) : null,
            'counts' => DB::connection(config('tenancy.database.central_connection'))->table('client_follow_ups')->where('company_id', $companyId)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->all(),
            'overdue' => DB::connection(config('tenancy.database.central_connection'))->table('client_follow_ups')->where('company_id', $companyId)->where('status', 'open')->where('due_date', '<', now('Asia/Manila')->toDateString())->count(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(SaveClientFollowUpRequest $request, int $companyId, ClientFollowUpService $service): RedirectResponse
    {
        $service->save($request->user(), $companyId, $request->validated());

        return to_route('follow-ups.index', ['companyId' => $companyId])->with('status', 'Client follow-up recorded. Download the checklist when you are ready to share it.');
    }

    public function batch(Request $request, int $companyId, ClientFollowUpService $service): JsonResponse
    {
        $service->company($request->user(), $companyId);
        $data = $request->validate(['items' => ['required', 'array', 'list', 'min:1', 'max:500'], 'items.*' => ['required', 'array:request_id,request_text,transaction_date,party,reference,amount'], 'items.*.request_id' => ['required', 'uuid', 'distinct'], 'items.*.request_text' => ['required', 'string', 'min:3', 'max:2000'], 'items.*.transaction_date' => ['nullable', 'date_format:Y-m-d'], 'items.*.party' => ['required', 'string', 'max:200'], 'items.*.reference' => ['nullable', 'string', 'max:200'], 'items.*.amount' => ['nullable', 'string', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D']]);
        $ids = DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($request, $companyId, $service, $data): array {
            $ids = [];
            foreach ($data['items'] as $item) {
                $ids[] = $service->save($request->user(), $companyId, [...$item, 'action' => 'create', 'category' => 'clarification']);
            }

            return $ids;
        });

        return response()->json(['count' => count($ids), 'url' => route('follow-ups.index', ['companyId' => $companyId])])->header('Cache-Control', 'private, no-store');
    }

    public function update(SaveClientFollowUpRequest $request, int $companyId, int $itemId, ClientFollowUpService $service): RedirectResponse
    {
        $service->save($request->user(), $companyId, $request->validated(), $itemId);

        return to_route('follow-ups.index', ['companyId' => $companyId, 'status' => $request->input('action') === 'resolve' ? 'resolved' : 'open'])->with('status', 'Follow-up updated. The change is recorded in history.');
    }

    public function export(Request $request, int $companyId, string $format, ClientFollowUpService $service): Response|StreamedResponse
    {
        $company = $service->company($request->user(), $companyId);
        $filters = $this->filters($request);
        $query = $service->query($companyId, $filters)->orderBy('transaction_date')->orderBy('id');
        $filename = 'client-follow-up-'.$companyId.'-'.now('Asia/Manila')->format('Y-m-d');
        if ($format === 'pdf') {
            if ((clone $query)->count() > 500) {
                throw ValidationException::withMessages(['export' => 'Filter this checklist to at most 500 items for PDF, or use CSV for the full list.']);
            }

            return Pdf::loadView('client-follow-up-export', ['company' => $company, 'items' => $query->get(), 'filters' => $filters])->setPaper('a4', 'landscape')->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'Helvetica'])->download($filename.'.pdf')->header('Cache-Control', 'private, no-store');
        }
        abort_unless($format === 'csv', 404);

        return response()->streamDownload(function () use ($company, $query): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Company', 'Item', 'Date', 'Transaction', 'Reference', 'Customer / Supplier', 'Amount (PHP)', 'Needed', 'Request to client', 'Requested by date', 'Status'], ',', '"', '', "\r\n");
            foreach ($query->cursor() as $item) {
                $values = [$company->name, 'FU-'.str_pad((string) $item->id, 6, '0', STR_PAD_LEFT), $item->transaction_date, $item->transaction_number, $item->reference, $item->party, str_replace(',', '', ClientFollowUpService::money($item->amount)), ClientFollowUpService::CATEGORIES[$item->category], $item->request_text, $item->due_date, ucfirst($item->status)];
                fputcsv($stream, array_map(fn ($value): string => preg_match('/^\s*[=+\-@]/u', (string) $value) ? "'".$value : (string) $value, $values), ',', '"', '', "\r\n");
            }
            fclose($stream);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function openEntry(Request $request, int $companyId, int $itemId, ClientFollowUpService $service): RedirectResponse
    {
        $service->company($request->user(), $companyId);
        $item = $service->item($companyId, $itemId);
        abort_unless($item->linked_sheet && $item->linked_record_id !== null, 404);
        $ledger = CompanyLedger::query()->where('company_id', $companyId)->first();
        $record = collect($item->linked_sheet === 'payroll' ? ($ledger?->payroll ?? []) : ($ledger?->state[$item->linked_sheet] ?? []))->first(fn (array $row): bool => (string) $row['id'] === $item->linked_record_id);
        abort_unless($record, 404, 'The linked transaction is no longer available. Its follow-up details are preserved.');
        $sheet = match ($item->linked_sheet) {
            'cwtCertificates' => 'cwt', 'wtpCertificates' => 'wtp', 'journalEntries' => 'journal', default => $item->linked_sheet
        };
        $request->session()->regenerate();
        $request->session()->put('active_company_id', $companyId);
        $request->session()->forget(['company_setup', 'preview_access']);

        return to_route('sheets.'.$sheet, ['entry' => $item->linked_record_id, 'period' => substr($record['date'] ?? now()->toDateString(), 0, 7)]);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:open,resolved,all'], 'category' => ['nullable', Rule::in(array_keys(ClientFollowUpService::CATEGORIES))], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])]]);
    }

    private function companies(Request $request, CompanyBookService $books): Collection
    {
        return $books->accessibleBooks($request->user())->get()->filter(fn ($company): bool => in_array('sheets', TeamPermissions::allowed($company->pivot->role, $company->pivot->allowed_tabs), true));
    }
}
