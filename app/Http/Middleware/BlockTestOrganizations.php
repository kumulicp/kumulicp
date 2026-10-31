<?php

namespace App\Http\Middleware;

use App\AppInstance;
use App\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admins have no business in the pages of a test organization: it only lives
 * for as long as a tester needs it, and the test run page is where it is
 * followed. Answers as if it didn't exist.
 */
class BlockTestOrganizations
{
    public function handle(Request $request, Closure $next): Response
    {
        $organization = $request->route('organization');

        // Routes that don't type-hint the organization get the raw id or slug
        if ($organization !== null && ! $organization instanceof Organization) {
            $organization = Organization::where('slug', (string) $organization)
                ->when(ctype_digit((string) $organization), fn ($query) => $query->orWhere('id', (int) $organization))
                ->first();
        }

        abort_if($organization?->isTest(), 404);

        $app = $request->route('app');

        abort_if($app instanceof AppInstance && $app->organization?->isTest(), 404);

        return $next($request);
    }
}
