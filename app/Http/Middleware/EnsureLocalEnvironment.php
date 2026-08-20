<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLocalEnvironment
{
    /**
     * Temporary gate until real admin authentication exists: blocks the
     * route outside the local environment. Remove once admin auth lands.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!app()->environment('local')) {
            abort(404);
        }

        return $next($request);
    }
}
