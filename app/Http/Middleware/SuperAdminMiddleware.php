<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SuperAdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user() || ! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Forbidden. Super Admin access only.'], 403);
        }

        return $next($request);
    }
}
