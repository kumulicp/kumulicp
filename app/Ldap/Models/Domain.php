<?php

namespace App\Ldap\Models;

use LdapRecord\Models\Model;

/**
 * @property string $dc
 * @property string $mail
 */
class Domain extends Model
{
    /**
     * The object classes of the LDAP model.
     */
    public static array $objectClasses = [
        'top',
        'domain',
        'PostfixBookMailAccount',
    ];
}
