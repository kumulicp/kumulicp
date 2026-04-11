<?php

namespace App\Http\Middleware;

use App\ServerSetting;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        if (! $request->expectsJson()) {
            $organizations = ServerSetting::where('key', 'installed')
                ->where('value', 1)
                ->first();

            if ($organizations) {
                return route('login');
            } else {
                return route('initial.setup');
            }
        }
    }
}
