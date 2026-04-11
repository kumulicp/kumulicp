<?php

namespace App\Ldap\Models;

use LdapRecord\Models\Model;

class AccountGroup extends Model
{
    /**
     * The object classes of the LDAP model.
     *
     * @var array
     */
    public static $objectClasses = [
        'top',
        'posixGroup',
    ];
}
