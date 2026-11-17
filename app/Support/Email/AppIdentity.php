<?php

namespace App\Support\Email;

use App\AppInstance;
use App\Services\AppInstanceService;

/**
 * How an app tells its users apart, which decides what a change of a user's email does to
 * their account there: an app that identifies people by a stable id only shows the new
 * address, one that identifies them by email makes a second, empty account at their next
 * sign in.
 *
 * Only what the repository can see is declared. Everything else is `unknown` until the
 * per-app audit (plan 2d) says otherwise.
 */
class AppIdentity
{
    public const STABLE = 'stable';

    public const EMAIL = 'email';

    public const UNKNOWN = 'unknown';

    public static function for(AppInstance $app): string
    {
        if ($app->application?->slug === 'nextcloud') {
            // Signed in through LDAP it is the username; through OIDC it is whatever claim the app is set to read
            $claim = (new AppInstanceService($app))->configuration('oidc-mapping-user-id');

            return $claim === 'email' ? self::EMAIL : self::STABLE;
        }

        return self::UNKNOWN;
    }
}
