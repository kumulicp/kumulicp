<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OffloadProject\Toggle\Facades\Toggle;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (Toggle::inactive($feature)) {
            abort(404);
        }

        return $next($request);
    }
}
