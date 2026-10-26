<?php

namespace App\Http\Middleware;

use App\Models\CompanySetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveCompany
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->is('company/setup', 'dashboard', 'sheets', 'sheets/*', 'books', 'books/*', 'masters', 'masters/*', 'reports', 'reports/*', 'tax-reports', 'tax-reports/*', 'settings', 'settings/*', 'lapsing', 'lapsing/*')) {
            return $next($request);
        }

        $company = $request->user()->companies()->where('companies.id', $request->session()->get('active_company_id'))->first();
        if (! $company) {
            $request->session()->forget(['active_company_id', 'company_setup', 'preview_access']);

            return $request->expectsJson()
                ? response()->json(['message' => 'Select an assigned company book before continuing.'], 403)
                : to_route('account.dashboard');
        }

        if (($request->is('company/setup') || ($request->is('settings', 'settings/*') && ! $request->isMethod('GET'))) && $company->pivot->role !== 'owner') {
            abort(403, 'Only the company book owner can change its registration details and settings.');
        }

        $settings = CompanySetting::query()->where('workspace_key', $company->workspaceKey())->first();
        $request->attributes->set('active_company', $company);
        $request->session()->put('preview_access', true);
        $request->session()->forget('company_setup');

        if ($settings) {
            $request->session()->put('company_setup', [
                ...$settings->profile,
                'company_id' => $company->getKey(),
                'workspace_key' => $company->workspaceKey(),
                'browser_key' => 'company-'.hash_hmac('sha256', 'company-browser:'.$company->getKey().'|'.$request->user()->getKey(), config('app.key')),
                'sheet_settings' => $settings->preferences,
            ]);
        } elseif (! $request->is('company/setup')) {
            return $request->expectsJson()
                ? response()->json(['message' => 'The company owner must complete company setup first.'], 409)
                : to_route($company->pivot->role === 'owner' ? 'company.setup' : 'account.dashboard')
                    ->with('status', 'The company owner must complete company setup before opening this book.');
        }

        return $next($request);
    }
}
