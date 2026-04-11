<?php

namespace App\Ldap\Models;

use LdapRecord\Models\Model;
use LdapRecord\Models\OpenLDAP\Entry;

class Admin extends Entry
{
    /**
     * The object classes of the LDAP model.
     */
    public static array $objectClasses = [
        'top',
        'organizationalRole',
        'simpleSecurityObject',
    ];
}
