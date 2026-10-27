<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\InboxDocument;
use App\Models\MonthlyClosing;
use App\Services\CompanyBookService;
use App\TeamPermissions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MyWorkController extends Controller
{
    public function index(Request $request, CompanyBookService $books): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'month' => ['nullable', 'regex:/^(?:19|20|21)\d{2}-(?:0[1-9]|1[0-2])$/D']]);
        $search = $filters['q'] ?? '';
        $month = $filters['month'] ?? now('Asia/Manila')->format('Y-m');
        $assigned = $books->accessibleBooks($request->user())->get();
        $configured = CompanySetting::query()->whereIn('workspace_key', $assigned->map->workspaceKey())->pluck('workspace_key')->all();
        $sheetIds = $assigned->filter(fn (Company $company): bool => $this->allows($company, ['sheets']))->modelKeys();
        $activityIds = $assigned->filter(fn (Company $company): bool => $this->allows($company, ['sheets', 'books', 'reports']))->modelKeys();
        $drafts = [];
        foreach (CompanyLedger::query()->whereIn('company_id', $sheetIds)->select(['id', 'company_id', 'state', 'payroll'])->lazyById(1) as $ledger) {
            $drafts[$ledger->company_id] = 0;
            foreach (['sales', 'purchases', 'collections', 'payments', 'cwtCertificates', 'wtpCertificates', 'journalEntries'] as $sheet) {
                $drafts[$ledger->company_id] += collect($ledger->state[$sheet] ?? [])->where('status', 'draft')->count();
            }
            $drafts[$ledger->company_id] += collect($ledger->payroll)->where('status', 'draft')->count();
        }
        $lastPosted = DB::table('journal_entries')->whereIn('company_id', $activityIds)->where('status', 'posted')->groupBy('company_id')->selectRaw('company_id, MAX(created_at) AS posted_at')->pluck('posted_at', 'company_id')->all();
        $recent = DB::table('journal_entries')->join('companies', 'companies.id', '=', 'journal_entries.company_id')
            ->leftJoin('users', 'users.id', '=', 'journal_entries.posted_by')->whereIn('journal_entries.company_id', $activityIds)->where('journal_entries.status', 'posted')
            ->select(['journal_entries.number', 'journal_entries.created_at', 'companies.name AS company_name', 'users.name AS actor_name'])
            ->orderByDesc('journal_entries.created_at')->orderByDesc('journal_entries.id')->limit(8)->get();
        $companies = $books->accessibleBooks($request->user())
            ->when($search !== '', fn ($query) => $query->whereRaw("LOWER(companies.name) LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%']))
            ->paginate(12)->withQueryString();
        $closings = MonthlyClosing::query()->whereIn('company_id', $assigned->modelKeys())->where('month', $month)->with('assignee:id,name')->get()->keyBy('company_id');
        $closingCounts = array_fill_keys(array_keys(MonthlyClosing::STATUSES), 0);
        foreach ($assigned as $book) {
            $closingCounts[$closings->get($book->id)?->status ?? 'not_started']++;
        }

        return response()->view('my-work', [
            'companies' => $companies, 'configuredWorkspaces' => $configured,
            'closingMonth' => $month, 'closings' => $closings, 'closingCounts' => $closingCounts,
            'pendingDocuments' => InboxDocument::query()->whereIn('company_id', $sheetIds)->whereIn('status', ['received', 'needs_entry', 'needs_clarification'])->count(),
            'followUpCounts' => DB::table('client_follow_ups')->whereIn('company_id', $sheetIds)->where('status', 'open')->selectRaw('company_id, COUNT(*) AS total')->groupBy('company_id')->pluck('total', 'company_id')->all(),
            'draftCounts' => $drafts, 'sheetCompanyIds' => $sheetIds, 'activityCompanyIds' => $activityIds, 'lastPosted' => $lastPosted, 'recentActivity' => $recent,
            'summary' => [
                'books' => $assigned->count(),
                'ready' => $assigned->filter(fn (Company $company): bool => in_array($company->workspaceKey(), $configured, true))->count(),
                'drafts' => array_sum($drafts),
                'posted' => DB::table('journal_entries')->whereIn('company_id', $activityIds)->where('status', 'posted')->where('created_at', '>=', now()->subDays(7))->count(),
            ],
            'setupNeeded' => $assigned->filter(fn (Company $company): bool => ! in_array($company->workspaceKey(), $configured, true))->count(),
            'canManageTeam' => $assigned->contains(fn (Company $company): bool => $company->pivot->role === 'owner'),
        ])->header('Cache-Control', 'no-store, private');
    }

    /** @param list<string> $modules */
    private function allows(Company $company, array $modules): bool
    {
        return array_intersect($modules, TeamPermissions::allowed($company->pivot->role, $company->pivot->allowed_tabs)) !== [];
    }
}
