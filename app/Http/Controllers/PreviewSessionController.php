<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreviewSessionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()) {
            return to_route('account.dashboard');
        }

        $request->validate([
            'method' => ['required', Rule::in(['google', 'email', 'workspace'])],
            'workspace' => ['required_if:method,workspace', 'nullable', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'identifier' => ['required_if:method,email', 'nullable', 'string', 'max:254', 'regex:/^(?:[^\s@]+@[^\s@]+\.[^\s@]+|[A-Za-z0-9][A-Za-z0-9._-]{2,63})$/'],
        ]);

        $request->session()->regenerate();
        $request->session()->put('preview_access', true);

        return to_route($request->session()->has('company_setup') ? 'dashboard' : 'company.setup');
    }

    public function destroy(Request $request): RedirectResponse
    {
        if ($request->user()) {
            return to_route('account.dashboard');
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login');
    }
}
