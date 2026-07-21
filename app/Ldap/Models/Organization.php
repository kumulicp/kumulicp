<?php

namespace App\Ldap\Models;

use LdapRecord\Models\Model;

/**
 * @property array|string|null $o
 * @property array|string|null $description
 * @property array|string|null $postalCode
 * @property array|string|null $st
 * @property array|string|null $street
 * @property array|string|null $telephoneNumber
 */
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
