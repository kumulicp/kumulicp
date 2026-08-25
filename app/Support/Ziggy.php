<?php

namespace App\Support;

use App\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

class Ziggy
{
    private static ?array $adminOnlyRouteNames = null;

    // Route names gated by the 'can:admin' middleware (the whole
    // Route::prefix('admin') block in routes/web.php) - computed from the
    // actual middleware registration rather than a hand-maintained list of
    // name patterns, since routes in that block use inconsistent naming
    // (app.*, admin.*, organizations.*, service.*, tasks, ...) that a
    // pattern list would be easy to miss one of.
    private static function adminOnlyRouteNames(): array
    {
        if (self::$adminOnlyRouteNames !== null) {
            return self::$adminOnlyRouteNames;
        }

        $names = [];

        foreach (Route::getRoutes() as $route) {
            if ($route->getName() && in_array('can:admin', $route->gatherMiddleware())) {
                $names[] = '!'.$route->getName();
            }
        }

        return self::$adminOnlyRouteNames = $names;
    }

    // Ziggy route group to pass to @routes(): null (every route) for
    // control-panel admins, 'restricted' (everything except the admin-only
    // routes) for everyone else, including guests - so the client-side route
    // list doesn't hand out the platform's admin surface for free.
    public static function groupFor(?User $user): ?string
    {
        if ($user && Gate::forUser($user)->allows('admin')) {
            return null;
        }

        config(['ziggy.groups.restricted' => self::adminOnlyRouteNames()]);

        return 'restricted';
    }
}
