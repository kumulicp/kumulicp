<?php

namespace App\Ldap\Rules;

use App\Ldap\Models\User;
use Illuminate\Database\Eloquent\Model as Eloquent;
use LdapRecord\Laravel\Auth\Rule;
use LdapRecord\Models\Model as LdapRecord;

class ControlPanelAccess implements Rule
{
    /**
     * Check if the rule passes validation.
     */
    public function passes(LdapRecord $user, ?Eloquent $model = null): bool
    {
        return $user instanceof User && $user->hasControlPanelAccess();
    }
}
