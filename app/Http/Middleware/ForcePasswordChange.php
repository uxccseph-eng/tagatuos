<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->hasApprovedAccess() && $request->user()->must_change_password && ! $request->routeIs('account.password.*', 'logout')) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Change your temporary password before continuing.',
                    'password_change_url' => route('account.password.edit'),
                ], 403);
            }

            return to_route('account.password.edit');
        }

        $response = $next($request);
        if ($request->user()) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
