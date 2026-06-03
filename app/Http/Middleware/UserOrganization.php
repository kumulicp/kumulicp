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
            $organization = Organization::where('slug', $user->ldap()->organization())->first();

            if ($organization) {
                $user->organization_id = $organization->id;
                $user->save();
            } else {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect('/login')->withErrors([
                    'email' => __('auth.failed'),
                ]);
            }
        }

        return $next($request);
    }
}
