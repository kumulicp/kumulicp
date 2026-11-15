<?php

namespace App\Http\Middleware;

use App\Support\Facades\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a route to the installation mode it belongs to: `tenancy:multi` for
 * what only a hosting provider uses (plans, subscriptions, billing,
 * self-registration), `tenancy:single` for what only a single-organization
 * install uses. Anything else gets a 404, as if the page did not exist.
 */
class EnsureTenancyMode
{
    public function handle(Request $request, Closure $next, string $mode): Response
    {
        if (Tenancy::mode()->value !== $mode) {
            abort(404);
        }

        return $next($request);
    }
}
