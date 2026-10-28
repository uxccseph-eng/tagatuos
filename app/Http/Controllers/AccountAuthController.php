<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeAccountPasswordRequest;
use App\Http\Requests\LoginAccountRequest;
use App\Http\Requests\RegisterAccountRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountAuthController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return to_route($request->user()->accountHomeRoute());
        }

        return view('account-auth', ['registering' => $request->routeIs('register')]);
    }

    public function register(RegisterAccountRequest $request): RedirectResponse
    {
        $user = DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($request): User {
            $user = new User($request->safe()->only(['name', 'email', 'password']));
            $user->type = $request->validated('type');
            $user->must_change_password = false;
            $user->registration_status = 'pending';
            $user->is_system_owner = false;
            $user->save();

            return $user;
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::login($user);
        $request->session()->regenerate();

        return to_route('account.registration-status')->with('status', 'Registration submitted. The system owner will review your application and assign your username.');
    }

    public function login(LoginAccountRequest $request): RedirectResponse
    {
        $request->authenticate();
        DB::connection(config('tenancy.database.central_connection'))->transaction(fn (): bool => $request->user()->forceFill(['last_login_at' => now()])->save());
        $request->session()->forget(['preview_access', 'company_setup', 'active_company_id', 'url.intended', 'onboarding_user_id']);
        $request->session()->regenerate();

        return to_route($request->user()->accountHomeRoute());
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login');
    }

    public function editPassword(): View
    {
        return view('account-password');
    }

    public function updatePassword(ChangeAccountPasswordRequest $request): RedirectResponse
    {
        $user = DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($request): User {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->getKey());
            if (! ($request->hasOnboardingProof() && $user->must_change_password) && ! Hash::check($request->validated('current_password') ?? '', $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Your current password has changed. Sign in again.']);
            }
            $user->password = $request->validated('password');
            $user->must_change_password = false;
            $user->remember_token = Str::random(60);
            $user->save();

            return $user;
        });

        Auth::setUser($user);
        $request->session()->forget('onboarding_user_id');
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', $user->password);

        return to_route($user->accountHomeRoute())->with('status', 'Password updated.');
    }

    public function registrationStatus(Request $request): View|RedirectResponse
    {
        if ($request->user()->hasApprovedAccess()) {
            return to_route($request->user()->accountHomeRoute());
        }

        return view('registration-status');
    }
}
