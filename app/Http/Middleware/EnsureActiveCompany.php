<?php

namespace App\Http\Middleware;

use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\TeamPermissions;
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
        if (! $request->is('company/setup', 'dashboard', 'sheets', 'sheets/*', 'books', 'books/*', 'masters', 'masters/*', 'reports', 'reports/*', 'tax-reports', 'tax-reports/*', 'settings', 'settings/*', 'lapsing', 'lapsing/*')) {
            return $next($request);
        }

        if (! $request->user()) {
            if (! app()->environment(['local', 'testing'])) {
                $request->session()->forget(['active_company_id', 'company_setup', 'preview_access']);

                return $request->expectsJson()
                    ? response()->json(['message' => 'Sign in to an assigned company book.'], 401)
                    : to_route('login');
            }

            return $next($request);
        }

        $company = $request->user()->companies()->where('companies.id', $request->session()->get('active_company_id'))->wherePivot('is_suspended', false)->first();
        if (! $company) {
            $request->session()->forget(['active_company_id', 'company_setup', 'preview_access']);

            return $request->expectsJson()
                ? response()->json(['message' => 'Select an assigned company book before continuing.'], 403)
                : to_route('account.dashboard');
        }

        $expectedCompany = $request->header('X-Company-ID', $request->input('_company_id'));
        if ($expectedCompany !== null && (! is_scalar($expectedCompany) || (string) $expectedCompany !== (string) $company->id)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'The active company changed in another tab. Reload this page before continuing.'], 409)
                : to_route('account.dashboard')->withErrors(['company' => 'The active company changed. Reopen the intended company book before saving.']);
        }

        $canEditSettings = TeamPermissions::canEditBookSettings($company->pivot->role, $company->pivot->allowed_tabs);
        if (($request->is('company/setup') || ($request->is('settings', 'settings/*') && ! $request->isMethod('GET'))) && ! $canEditSettings) {
            abort(403, 'Book details and settings require an owner, manager or accountant role with Settings access.');
        }

        $allowed = TeamPermissions::allowed($company->pivot->role, $company->pivot->allowed_tabs);
        $modules = match (true) {
            $request->routeIs('sheets.database.show') => ['sheets', 'books', 'reports', 'tax-reports', 'lapsing'],
            $request->routeIs('masters.accounts.catalog', 'masters.accounts.relief-catalog') => ['sheets', 'books', 'lapsing', 'masters', 'reports', 'tax-reports', 'settings'],
            $request->routeIs('reports.form2307') => ['sheets', 'tax-reports'],
            $request->routeIs('reports.form2316', 'reports.sales.dat', 'reports.purchases.dat', 'reports.sawt.dat', 'reports.qap.dat', 'reports.qap.annual.dat') => ['tax-reports'],
            isset(TeamPermissions::TABS[$request->segment(1)]) => [$request->segment(1)],
            default => [],
        };
        abort_if($modules !== [] && array_intersect($modules, $allowed) === [], 403, 'Your company book owner has not enabled this module for your account.');
        $request->attributes->set('company_allowed_tabs', $allowed);
        if ($request->isMethod('POST') && $request->is('reports/*', 'tax-reports/*')) {
            $revision = (string) (CompanyLedger::query()->where('company_id', $company->id)->value('revision') ?? 0);
            abort_unless($request->header('X-Ledger-Revision') === $revision, 409, 'The company records changed or this page is outdated. Reload the latest records before exporting.');
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
                ? response()->json(['message' => 'An authorized owner, manager or accountant must complete company setup first.'], 409)
                : to_route($canEditSettings ? 'company.setup' : 'account.dashboard')
                    ->with('status', 'An authorized owner, manager or accountant must complete company setup before opening this book.');
        }

        return $next($request);
    }
}
