<?php

namespace App\Ldap\Models;

use LdapRecord\Models\Model;

/**
 * @property string $cn
 * @property string $mail
 * @property string $uid
 * @property string $sn
 * @property string $displayName
 * @property string $mailQuota
 * @property string $mailHomeDirectory
 * @property string $mailStorageDirectory
 * @property string $userPassword
 */
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
