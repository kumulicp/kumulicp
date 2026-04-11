<?php

namespace App\Http\Middleware;

use App\Providers\RouteServiceProvider;
use App\Support\Facades\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RedirectIfAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  string|null  $guard
     * @return mixed
     */
    public function handle($request, Closure $next, $guard = null)
    {
        if (Auth::guard($guard)->check()) {
            return redirect(RouteServiceProvider::HOME);
        } elseif (! $request->expectsJson()) {
            $installed = Settings::get('installed');

            if ($installed != 1) {
                return redirect(route('initial.setup'));
            }
        }

        return $next($request);
    }
}
