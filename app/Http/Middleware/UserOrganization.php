<?php

namespace App\Http\Middleware;

use App\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserOrganization
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if (! $user->organization && $user->ldap()) {
            $organization = $user->ldap()->organization();

            $organization = Organization::where('slug', $organization)->first();

            $user->organization_id = $organization->id;
            $user->save();

        }

        return $next($request);
    }
}
