<?php

namespace App\Http\Controllers;

use App\Http\Requests\ManageTeamMemberRequest;
use App\Http\Requests\UpdatePortalRequest;
use App\Models\Company;
use App\Models\TeamEvent;
use App\Models\User;
use App\Services\OrganizationTeamService;
use App\Services\TeamAccessService;
use App\TeamPermissions;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function preview(Request $request): View
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        $section = $request->validate(['section' => ['nullable', Rule::in(['members', 'access', 'templates', 'history'])]])['section'] ?? 'members';
        $company = new Company(['name' => 'Sample Company Book']);
        $company->id = 0;
        $members = collect();
        foreach ([['Sample Owner', 'owner'], ['Sample Manager', 'manager'], ['Sample Accountant', 'accountant'], ['Sample Staff', 'staff']] as $index => [$name, $role]) {
            $user = new User(['name' => $name, 'email' => $role.'@example.test']);
            $user->id = $index + 1;
            $user->setRelation('pivot', new Pivot(['role' => $role, 'is_suspended' => false, 'allowed_tabs' => $role === 'staff' ? ['dashboard', 'sheets'] : null]));
            $members->push($user);
        }
        $q = mb_strtolower((string) $request->query('q', ''));
        $members = $members->filter(fn (User $user): bool => str_contains(mb_strtolower($user->name.' '.$user->email), $q) && (! $request->query('role') || $user->pivot->role === $request->query('role')) && $request->query('status') !== 'suspended')->values();

        return view('team', [
            'teamCompany' => $company, 'section' => $section, 'previewMode' => true, 'organizationAccess' => false, 'organizationMemberIds' => [],
            'members' => new LengthAwarePaginator($members, $members->count(), 25),
            'memberData' => $members->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'role' => $user->pivot->role, 'tabs' => TeamPermissions::allowed($user->pivot->role, $user->pivot->allowed_tabs), 'suspended' => false]),
            'events' => new LengthAwarePaginator([], 0, 25), 'portalUrl' => null, 'portalQr' => null, 'credential' => null, 'credentialQr' => null,
            'tabs' => TeamPermissions::TABS, 'templates' => TeamPermissions::templates(),
        ]);
    }

    public function entry(Request $request): RedirectResponse
    {
        $company = $request->user()->companies()->wherePivot('role', 'owner')->wherePivot('is_suspended', false)
            ->orderByRaw('companies.id = ? desc', [(int) $request->session()->get('active_company_id')])->orderBy('companies.id')->first();

        return $company ? to_route('team.index', ['companyId' => $company->id])
            : to_route('account.dashboard')->with('status', 'Create a company book, or ask its owner to manage team access.');
    }

    public function index(Request $request, int $companyId, TeamAccessService $access, string $section = 'members'): View
    {
        $company = $access->ownedCompany($request->user(), $companyId);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'role' => ['nullable', Rule::in(Company::ROLES)], 'status' => ['nullable', Rule::in(['active', 'suspended'])], 'sort' => ['nullable', Rule::in(['name', 'last_login_at'])]]);
        $members = $company->users()->select(['users.id', 'users.name', 'users.email', 'users.type', 'users.must_change_password', 'users.last_login_at'])
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where(function (Builder $query) use ($search): void {
                $query->where('users.name', 'like', '%'.$search.'%')->orWhere('users.email', 'like', '%'.$search.'%');
            }))
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->where('company_user.role', $role))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('company_user.is_suspended', $status === 'suspended'))
            ->orderBy('users.'.($filters['sort'] ?? 'name'))->paginate(25)->withQueryString();
        $credential = $request->session()->get('team_credential');
        if (($credential['company_id'] ?? null) !== $company->id) {
            $credential = null;
        }
        $portalUrl = $company->slug ? route('portal.show', ['firmSlug' => $company->slug]) : null;
        $organizationAccess = (int) $company->organization_owner_id === (int) $request->user()->id;
        $organizationMemberIds = $organizationAccess ? DB::table('organization_members')->where('owner_id', $request->user()->id)->pluck('user_id')->all() : [];

        return view('team', [
            'teamCompany' => $company, 'section' => $section, 'members' => $members, 'organizationAccess' => $organizationAccess, 'organizationMemberIds' => $organizationMemberIds,
            'memberData' => $members->getCollection()->map(fn ($user): array => ['id' => $user->id, 'name' => $user->name, 'role' => $user->pivot->role, 'tabs' => TeamPermissions::allowed($user->pivot->role, $user->pivot->allowed_tabs), 'suspended' => (bool) $user->pivot->is_suspended, 'organization' => in_array($user->id, $organizationMemberIds)])->values(),
            'events' => $section === 'history' ? TeamEvent::query()->where('company_id', $companyId)->with('actor:id,name')->latest('id')->paginate(25) : null,
            'portalUrl' => $portalUrl, 'portalQr' => $portalUrl ? $this->qr($portalUrl) : null,
            'credential' => $credential, 'credentialQr' => isset($credential['url']) ? $this->qr($credential['url']) : null,
            'tabs' => TeamPermissions::TABS, 'templates' => TeamPermissions::templates(),
        ]);
    }

    public function store(ManageTeamMemberRequest $request, int $companyId, TeamAccessService $access): RedirectResponse
    {
        $created = $access->add($request->user(), $companyId, $request->validated());
        $scope = app(OrganizationTeamService::class)->contains($request->user(), $created['user']->id) ? 'all client books in your organization' : 'this company book';
        $response = to_route('team.index', ['companyId' => $companyId])->with('status', 'Member added to '.$scope.'.');
        if ($created['password'] !== null) {
            $response->with('team_credential', ['company_id' => $companyId, 'name' => $created['user']->name, 'email' => $created['user']->email, 'password' => $created['password'], 'url' => $created['url']]);
        }

        return $response;
    }

    public function update(ManageTeamMemberRequest $request, int $companyId, int $memberId, TeamAccessService $access): RedirectResponse
    {
        $access->update($request->user(), $companyId, $memberId, $request->validated());

        return to_route('team.index', ['companyId' => $companyId])->with('status', 'Member access updated. Outstanding setup links were revoked.');
    }

    public function remove(ManageTeamMemberRequest $request, int $companyId, int $memberId, TeamAccessService $access): RedirectResponse
    {
        $access->remove($request->user(), $companyId, $memberId);

        return to_route('team.index', ['companyId' => $companyId])->with('status', 'Membership removed. The user account and accounting records are preserved.');
    }

    public function issueLink(Request $request, int $companyId, int $memberId, TeamAccessService $access): RedirectResponse
    {
        $url = $access->setupLink($request->user(), $companyId, $memberId);

        return to_route('team.index', ['companyId' => $companyId])->with('team_credential', ['company_id' => $companyId, 'url' => $url]);
    }

    public function portal(UpdatePortalRequest $request, int $companyId, TeamAccessService $access): RedirectResponse
    {
        $access->changePortal($request->user(), $companyId, $request->validated('slug'));

        return to_route('team.index', ['companyId' => $companyId])->with('status', 'Company sign-in address updated. Old setup links no longer work.');
    }

    private function qr(string $value): string
    {
        $renderer = new ImageRenderer(new RendererStyle(260), new SvgImageBackEnd);

        return 'data:image/svg+xml;base64,'.base64_encode((new Writer($renderer))->writeString($value));
    }
}
