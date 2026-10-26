<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewRegistrationRequest;
use App\Models\RegistrationReview;
use App\Models\User;
use App\Services\RegistrationApprovalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SystemDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'all'])], 'type' => ['nullable', Rule::in(['individual', 'firm'])], 'q' => ['nullable', 'string', 'max:100']]);
        $status = $filters['status'] ?? 'pending';
        $applicants = User::query()->whereIn('type', ['individual', 'firm'])->where('is_system_owner', false);
        $counts = [];
        foreach (['pending', 'approved', 'rejected'] as $state) {
            $counts[$state] = (clone $applicants)->where('registration_status', $state)->count();
        }
        $records = $applicants->when($status !== 'all', fn (Builder $query): Builder => $query->where('registration_status', $status))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type): Builder => $query->where('type', $type))
            ->when($filters['q'] ?? null, fn (Builder $query, string $search): Builder => $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')->orWhere('username', 'like', '%'.$search.'%');
            }))->orderBy('created_at', $status === 'pending' ? 'asc' : 'desc')->orderBy('id')->paginate(20)->withQueryString();

        return view('system-dashboard', ['applicants' => $records, 'counts' => $counts, 'status' => $status, 'reviews' => RegistrationReview::query()->with(['actor:id,name', 'applicant:id,name,email'])->latest('id')->limit(12)->get()]);
    }

    public function approve(ReviewRegistrationRequest $request, int $applicantId, RegistrationApprovalService $approval): RedirectResponse
    {
        $approval->approve($request->user(), $applicantId, $request->validated('username'));

        return to_route('system.dashboard')->with('status', 'Registration approved and username assigned. The applicant can now open their account.');
    }

    public function reject(ReviewRegistrationRequest $request, int $applicantId, RegistrationApprovalService $approval): RedirectResponse
    {
        $approval->reject($request->user(), $applicantId, $request->validated('reason'));

        return to_route('system.dashboard')->with('status', 'Registration rejected. The reason is visible on the applicant’s registration status page.');
    }

    public function username(ReviewRegistrationRequest $request, int $applicantId, RegistrationApprovalService $approval): RedirectResponse
    {
        $approval->changeUsername($request->user(), $applicantId, $request->validated('username'));

        return to_route('system.dashboard', ['status' => 'approved'])->with('status', 'Username saved. The account password and company memberships are unchanged.');
    }
}
