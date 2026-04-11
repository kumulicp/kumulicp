<?php

namespace App\Ldap\Models;

use LdapRecord\Models\Model;

class Organization extends Model
{
    /**
     * The object classes of the LDAP model.
     */
    public static array $objectClasses = [
        'top',
        'organization',
    ];
}
