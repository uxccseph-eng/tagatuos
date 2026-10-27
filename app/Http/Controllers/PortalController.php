<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginAccountRequest;
use App\Models\Company;
use App\Models\TeamLoginToken;
use App\Models\User;
use App\Services\OrganizationTeamService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function show(string $firmSlug): View
    {
        return view('portal', ['portalCompany' => Company::query()->where('slug', $firmSlug)->firstOrFail()]);
    }

    public function login(LoginAccountRequest $request, string $firmSlug): RedirectResponse
    {
        $company = Company::query()->where('slug', $firmSlug)->firstOrFail();
        $request->authenticate();
        if (! $request->user()->companies()->where('companies.id', $company->id)->wherePivot('is_suspended', false)->exists()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            throw ValidationException::withMessages(['email' => 'Unable to sign in to this company with those credentials.']);
        }
        $request->session()->forget(['company_setup', 'preview_access', 'url.intended']);
        $request->session()->regenerate();
        $request->session()->put('active_company_id', $company->id);
        DB::transaction(fn (): bool => $request->user()->forceFill(['last_login_at' => now()])->save());

        return to_route($request->user()->hasApprovedAccess() && ! $request->user()->must_change_password ? 'dashboard' : $request->user()->accountHomeRoute());
    }

    public function access(Request $request, string $firmSlug, string $tokenId): Response
    {
        $token = $this->validToken($firmSlug, $tokenId);

        return response()->view('team-access', [
            'portalCompany' => Company::query()->findOrFail($token->company_id),
            'member' => User::query()->findOrFail($token->user_id), 'accessUrl' => $request->fullUrl(),
        ])->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }

    public function consume(Request $request, string $firmSlug, string $tokenId): RedirectResponse
    {
        if ($request->user()) {
            throw ValidationException::withMessages(['account' => 'Sign out before using an accountant setup link.']);
        }
        $token = DB::transaction(function () use ($firmSlug, $tokenId): TeamLoginToken {
            $token = $this->validToken($firmSlug, $tokenId, true);
            $token->update(['used_at' => now()]);

            return $token;
        });
        $user = User::query()->findOrFail($token->user_id);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(['active_company_id' => $token->company_id, 'onboarding_user_id' => $user->id]);
        DB::transaction(fn (): bool => $user->forceFill(['last_login_at' => now()])->save());

        return to_route('account.password.edit');
    }

    private function validToken(string $firmSlug, string $tokenId, bool $lock = false): TeamLoginToken
    {
        $token = TeamLoginToken::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($tokenId);
        $company = Company::query()->whereKey($token->company_id)->where('slug', $firmSlug)->firstOrFail();
        $member = $company->users()->where('users.id', $token->user_id)->wherePivot('is_suspended', false)->firstOrFail();
        $issuerIsOwner = $company->users()->where('users.id', $token->issued_by)->where('registration_status', 'approved')->wherePivot('role', 'owner')->wherePivot('is_suspended', false)->exists();
        abort_unless($member->hasApprovedAccess(), 410, 'This account has not been approved.');
        $issuer = User::query()->find($token->issued_by);
        abort_if($token->used_at || $token->expires_at->isPast() || ! $issuerIsOwner || ! $member->must_change_password || $member->type !== 'accountant' || $member->pivot->role === 'owner' || ! $issuer || ! app(OrganizationTeamService::class)->canSetUp($issuer, $member), 410, 'This setup link is no longer available. Ask the owner for a new link.');

        return $token;
    }
}
