<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\InboxDocument;
use App\Models\MonthlyClosing;
use App\Models\User;
use App\Services\ClientDatabase;
use App\Services\CompanyBookService;
use App\Services\CompanyLedgerService;
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
        $configured = $assigned->filter(fn (Company $company): bool => app(ClientDatabase::class)->configured($company))->map->workspaceKey()->all();
        $sheetIds = $assigned->filter(fn (Company $company): bool => $this->allows($company, ['sheets']))->modelKeys();
        $activityIds = $assigned->filter(fn (Company $company): bool => $this->allows($company, ['sheets', 'books', 'reports']))->modelKeys();
        $drafts = [];
        $lastPosted = [];
        $recent = collect();
        $postedCount = 0;
        foreach ($assigned as $book) {
            $activity = $book->run(function () use ($book, $sheetIds, $activityIds): array {
                $draftCount = 0;
                if (in_array($book->id, $sheetIds, true)) {
                    $ledger = CompanyLedger::query()->where('company_id', $book->id)->first();
                    foreach ([...CompanyLedgerService::SHEETS, 'payroll'] as $sheet) {
                        $rows = $sheet === 'payroll' ? ($ledger?->payroll ?? []) : ($ledger?->state[$sheet] ?? []);
                        $draftCount += collect($rows)->where('status', 'draft')->count();
                    }
                }
                if (! in_array($book->id, $activityIds, true)) {
                    return [$draftCount, null, [], 0];
                }
                $query = DB::table('journal_entries')->where('company_id', $book->id)->where('status', 'posted');

                return [$draftCount, (clone $query)->max('created_at'), (clone $query)->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get(['number', 'created_at', 'posted_by'])->all(), (clone $query)->where('created_at', '>=', now()->subDays(7))->count()];
            });
            $drafts[$book->id] = $activity[0];
            $lastPosted[$book->id] = $activity[1];
            foreach ($activity[2] as $entry) {
                $entry->company_name = $book->name;
                $entry->actor_name = User::query()->whereKey($entry->posted_by)->value('name');
                $recent->push($entry);
            }
            $postedCount += $activity[3];
        }
        $recent = $recent->sortByDesc('created_at')->take(8)->values();
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
            'followUpCounts' => DB::connection(config('tenancy.database.central_connection'))->table('client_follow_ups')->whereIn('company_id', $sheetIds)->where('status', 'open')->selectRaw('company_id, COUNT(*) AS total')->groupBy('company_id')->pluck('total', 'company_id')->all(),
            'draftCounts' => $drafts, 'sheetCompanyIds' => $sheetIds, 'activityCompanyIds' => $activityIds, 'lastPosted' => $lastPosted, 'recentActivity' => $recent,
            'summary' => [
                'books' => $assigned->count(),
                'ready' => $assigned->filter(fn (Company $company): bool => in_array($company->workspaceKey(), $configured, true))->count(),
                'drafts' => array_sum($drafts),
                'posted' => $postedCount,
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
