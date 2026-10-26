<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSystemOwner
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_system_owner && $request->user()->hasApprovedAccess(), 403, 'Only the system owner can review registrations.');

        return $next($request);
    }
}
