<?php

use App\Http\Middleware\EnsureActiveCompany;
use App\Http\Middleware\EnsureApprovedAccount;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        foreach ([EnsureApprovedAccount::class, ForcePasswordChange::class, SetTenantContext::class, EnsureActiveCompany::class] as $companyMiddleware) {
            $middleware->prependToPriorityList(SubstituteBindings::class, $companyMiddleware);
        }
        $middleware->web(append: [
            AuthenticateSession::class,
            EnsureApprovedAccount::class,
            ForcePasswordChange::class,
            SetTenantContext::class,
            EnsureActiveCompany::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['app_secret']);
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request): ?Response {
            if ($exception->getStatusCode() !== 403 || ! $request->user()?->hasApprovedAccess() || $request->routeIs('portal.*')) {
                return null;
            }
            $company = $request->user()->companies()
                ->where('companies.id', $request->attributes->get('tenant_company')?->id ?? $request->route('companyId') ?? $request->session()->get('active_company_id'))
                ->wherePivot('is_suspended', false)->first();
            $feedback = [
                'permission_denied' => true,
                'role' => ucfirst($company?->pivot?->role ?? $request->user()->type),
                'message' => $exception->getMessage() ?: 'Your role does not allow this action. Ask the company owner to review your access.',
            ];

            return ($request->expectsJson() || $request->is('api/*')
                ? response()->json($feedback, 403)
                : response()->view('permission-denied', ['feedback' => $feedback], 403))
                ->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
