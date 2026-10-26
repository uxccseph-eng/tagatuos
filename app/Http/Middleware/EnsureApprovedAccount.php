<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApprovedAccount
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->hasApprovedAccess()
            && ! $request->routeIs('account.registration-status', 'account.password.*', 'logout')) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Your registration has not been approved.', 'registration_status' => $request->user()->registration_status, 'status_url' => route('account.registration-status')], 403)
                : to_route('account.registration-status');
        }

        return $next($request);
    }
}
