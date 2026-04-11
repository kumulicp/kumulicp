<?php

namespace App\Ldap\Models;

class EmailUser extends User
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

    protected string $guidKey = 'entryUUID';

    private $organization;

    public function __construct()
    {
        $provider = config('auth.guards.web.provider');
        $object_classes = config("auth.providers.$provider.user_email_object_classes");
        if ($object_classes && $object_classes !== '') {
            self::$objectClasses = explode(',', $object_classes);
        }
    }

    /**
     * Route notifications for the mail channel.
     *
     * @param  Notification  $notification
     * @return array|string
     */
    public function routeNotificationForMail($notification)
    {
        // Return name and email address...
        return [$this->getFirstAttribute('mail') => $this->getFirstAttribute('displayName')];
    }
}
