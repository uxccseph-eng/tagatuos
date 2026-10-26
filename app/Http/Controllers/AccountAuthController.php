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
            return to_route('account.dashboard');
        }

        return view('account-auth', ['registering' => $request->routeIs('register')]);
    }

    public function register(RegisterAccountRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = new User($request->safe()->only(['name', 'email', 'password']));
            $user->type = $request->validated('type');
            $user->must_change_password = false;
            $user->save();

            return $user;
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::login($user);
        $request->session()->regenerate();

        return to_route('account.dashboard')->with('status', 'Your account is ready. Create your first company book below.');
    }

    public function login(LoginAccountRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->forget(['preview_access', 'company_setup', 'active_company_id', 'url.intended']);
        $request->session()->regenerate();

        return to_route($request->user()->must_change_password ? 'account.password.edit' : 'account.dashboard');
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
        $user = DB::transaction(function () use ($request): User {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->getKey());
            if (! Hash::check($request->validated('current_password'), $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Your current password has changed. Sign in again.']);
            }
            $user->password = $request->validated('password');
            $user->must_change_password = false;
            $user->remember_token = Str::random(60);
            $user->save();

            return $user;
        });

        Auth::setUser($user);
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', $user->password);

        return to_route('account.dashboard')->with('status', 'Password updated. You can now access your assigned company books.');
    }
}
