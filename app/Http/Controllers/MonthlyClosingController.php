<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveMonthlyClosingRequest;
use App\Models\MonthlyClosing;
use App\Services\MonthlyClosingService;
use App\TeamPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MonthlyClosingController extends Controller
{
    public function show(Request $request, int $companyId, string $month, MonthlyClosingService $service): Response
    {
        $company = $service->company($request->user(), $companyId);
        $closing = MonthlyClosing::query()->where('company_id', $companyId)->where('month', $month)->with(['assignee:id,name', 'completedBy:id,name'])->first();
        $members = TeamPermissions::canApproveClosing($company->pivot->role) ? $company->users()->select(['users.id', 'users.name'])->where('registration_status', 'approved')->where('must_change_password', false)->wherePivot('is_suspended', false)->orderBy('users.name')->get()->filter(fn ($member): bool => in_array('sheets', TeamPermissions::allowed($member->pivot->role, $member->pivot->allowed_tabs), true)) : collect();
        $events = DB::connection(config('tenancy.database.central_connection'))->table('monthly_closing_events')->join('monthly_closings', 'monthly_closings.id', '=', 'monthly_closing_events.monthly_closing_id')->leftJoin('users', 'users.id', '=', 'monthly_closing_events.actor_id')
            ->where('monthly_closing_events.company_id', $companyId)->where('monthly_closings.month', $month)->orderByDesc('monthly_closing_events.id')->select(['monthly_closing_events.*', 'users.name AS actor_name'])->paginate(15);

        return response()->view('monthly-closing', ['company' => $company, 'closing' => $closing, 'month' => $month, 'members' => $members, 'events' => $events, 'canEdit' => $service->canEdit($company, $request->user(), $closing)])->header('Cache-Control', 'no-store, private');
    }

    public function update(SaveMonthlyClosingRequest $request, int $companyId, string $month, MonthlyClosingService $service): RedirectResponse
    {
        $service->save($request->user(), $companyId, $month, $request->validated());

        return to_route('closing.show', ['companyId' => $companyId, 'month' => $month])->with('status', $request->input('action') === 'reopen' ? 'Monthly closing reopened. The reason is recorded in history.' : 'Monthly closing saved.');
    }
}
