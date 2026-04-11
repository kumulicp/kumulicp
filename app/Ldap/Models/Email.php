<?php

namespace App\Ldap\Models;

use LdapRecord\Models\Model;

class Email extends Model
{
    /**
     * The object classes of the LDAP model.
     */
    public static array $objectClasses = [
        'top',
        'person',
        'organizationalperson',
        'inetorgperson',
        'PostfixBookMailAccount',
    ];
}
