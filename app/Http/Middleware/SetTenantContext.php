<?php

namespace App\Http\Middleware;

use App\Models\ChartAccount;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);
        $previous = $context->snapshot();
        $context->restore([null, null, false]);
        $projected = false;
        $resolvedCompanyId = null;
        $hadCompany = $request->session()->has('active_company_id');
        $hadProfile = $request->session()->has('company_setup');
        $previousCompany = $request->session()->get('active_company_id');
        $previousProfile = $request->session()->get('company_setup');
        try {
            $bookRoute = $request->is('company/setup', 'dashboard', 'sheets', 'sheets/*', 'books', 'books/*', 'masters', 'masters/*', 'reports', 'reports/*', 'tax-reports', 'tax-reports/*', 'settings', 'settings/*', 'lapsing', 'lapsing/*');
            if (! $request->user()) {
                if (app()->environment(['local', 'testing']) && is_array($previousProfile)) {
                    $context->previewWorkspace = ChartAccount::workspaceKey($previousProfile);
                }

                return $next($request);
            }
            if (! $bookRoute && $request->route('companyId') === null) {
                return $next($request);
            }
            $ids = array_filter([$request->route('companyId'), $request->query('company_id'), $bookRoute ? $request->header('X-Company-ID') : null, $request->input('_company_id'), $request->request->get('company_id')], fn (mixed $id): bool => $id !== null);
            if ($request->request->get('company_id') !== null && $request->route('companyId') === null && $request->query('company_id') === null && $request->header('X-Company-ID') === null && $request->input('_company_id') === null && $previousCompany !== null) {
                $ids[] = $previousCompany;
            }
            foreach ($ids as $id) {
                abort_unless(is_scalar($id) && preg_match('/^[1-9][0-9]*$/D', (string) $id), 422, 'Choose a valid company.');
            }
            abort_if(count(array_unique(array_map('strval', $ids))) > 1, 409, 'The request contains conflicting company identifiers. Reopen the intended company book.');
            $companyId = $ids === [] ? $previousCompany : reset($ids);
            if (! $companyId) {
                return $next($request);
            }
            $company = $request->user()->companies()->where('companies.id', $companyId)->wherePivot('is_suspended', false)->first();
            if (! $company) {
                if ($ids === [] || $request->route('companyId') !== null) {
                    return $next($request);
                }
                abort($request->route('companyId') !== null ? 404 : ($request->query('company_id') !== null || (string) $companyId === (string) $previousCompany ? 403 : 409), 'This company book is not assigned to your account.');
            }
            $resolvedCompanyId = $company->id;
            $projected = $bookRoute;
            if ($projected) {
                $request->session()->put('active_company_id', $company->id);
            }
            $request->attributes->set('tenant_company', $company);

            $response = $context->run($company, fn (): Response => $next($request));
            if ($ids !== [] && $response instanceof RedirectResponse) {
                $target = $response->getTargetUrl();
                $parts = parse_url($target);
                if ((! isset($parts['host']) || $parts['host'] === $request->getHost()) && preg_match('#^/(?:company/setup|dashboard|sheets|books|masters|reports|tax-reports|settings|lapsing)(?:/|$)#', $parts['path'] ?? '')) {
                    parse_str($parts['query'] ?? '', $parameters);
                    if (! isset($parameters['company_id'])) {
                        $base = explode('#', $target, 2)[0];
                        $response->setTargetUrl($base.(isset($parts['query']) ? '&' : '?').'company_id='.$company->id.(isset($parts['fragment']) ? '#'.$parts['fragment'] : ''));
                    }
                }
            }

            return $response;
        } finally {
            if ($projected) {
                $hadCompany ? $request->session()->put('active_company_id', $previousCompany) : $request->session()->forget('active_company_id');
                if ((string) $previousCompany !== (string) $resolvedCompanyId) {
                    $hadProfile ? $request->session()->put('company_setup', $previousProfile) : $request->session()->forget('company_setup');
                }
            }
            $context->restore($previous);
        }
    }
}
