<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\ClientDatabase;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class UseClientDatabase
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $bookRoute = $request->is('company/setup', 'dashboard', 'sheets', 'sheets/*', 'books', 'books/*', 'masters', 'masters/*', 'reports', 'reports/*', 'tax-reports', 'tax-reports/*', 'settings', 'settings/*', 'lapsing', 'lapsing/*');
        $companyId = $request->route('companyId') ?? ($bookRoute ? $request->session()->get('active_company_id') : null);
        if (! $companyId || ! $request->user()?->hasApprovedAccess() || $request->user()->must_change_password) {
            return $next($request);
        }
        $databases = app(ClientDatabase::class);

        return $databases->central()->transaction(function () use ($request, $next, $companyId, $databases): Response {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->first();
            if (! $company || ! $company->users()->whereKey($request->user()->id)->wherePivot('is_suspended', false)->exists()) {
                return $next($request);
            }

            return $databases->run($company, fn (): Response => $company->tenancy_activated_at !== null && ! $request->isMethodSafe()
                ? DB::transaction(fn (): Response => $next($request))
                : $next($request));
        });
    }
}
