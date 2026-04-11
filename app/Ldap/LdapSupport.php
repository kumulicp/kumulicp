<?php

namespace App\Ldap;

use App\AppInstance;
use App\AppRole;
use App\Ldap\Actions\Dn;
use App\Ldap\Models\Group;
use App\Ldap\Models\User;
use App\Organization;
use App\Support\Facades\Settings;
use App\User as DatabaseUser;
use LdapRecord\Container;
use LdapRecord\Laravel\Import\Importer;
use LdapRecord\Query\Collection;

class LdapSupport
{
    public static function orgAdminGroup()
    {
        return Group::find(Dn::create('server', 'controlPanelAccess', 'orgAdmin'));
    }

    public static function guid(User $user)
    {
        return $user->getFirstAttribute($user->getGuidKey());
    }

    public static function billingManagersGroup(Organization $organization)
    {
        $dn = Dn::create($organization, 'controlcenter', 'Billing Managers');
        $billing_managers = Group::find($dn);

        if (! $billing_managers) {
            $billing_managers = new Group;
            $billing_managers->setDn($dn);
            $billing_managers->cn = 'Billing Managers';
            $billing_managers->description = 'Billing Managers';
            $billing_managers->member = Dn::create($organization);
            $billing_managers->save();
        }

        return $billing_managers;
    }

    public static function getAppRoleGroup(AppInstance $app_instance, AppRole $role)
    {
        $app_instance_id = self::getAppInstanceID($app_instance);
        if ($app_instance->plan->setting('server_type') === 'shared') {
            $app_instance = $app_instance->parent;
        }
        $role_slug = $role->app_slug($app_instance);
        $role_dn = Dn::create($app_instance->organization, 'applications', [$role_slug, $app_instance_id]);
        $group = Group::find($role_dn);

        if (! $group) {
            $group = new Group;
            $group->inside(Dn::create($app_instance->organization, 'applications', $app_instance_id));
            $group->setAttribute('cn', $role_slug);
            $group->setAttribute('description', $role->name);
            $group->setAttribute('member', Dn::create($app_instance->organization));
            $group->save();
        }

        return $group;
    }

    public static function getAppInstanceID(AppInstance $app_instance)
    {
        if (! $app_instance->parent) {
            return $app_instance->name;
        } else {
            return $app_instance->parent->name;
        }
    }

    public static function cleanupControlPanelAccess(string $email)
    {
        // Find the user in LDAP
        $user = User::where(Settings::get('ldap_personal_email', 'mail'), '=', $email)->firstOrFail();
        $web_provider = config('auth.guards.web.provider');

        if (config("auth.providers.$web_provider.driver") !== 'ldap') {
            return;
        }

        $sync_attributes = config("auth.providers.$web_provider.database.sync_attributes");

        // Import the user
        (new Importer)
            ->setLdapObjects(Collection::make([$user]))
            ->setEloquentModel(DatabaseUser::class)
            ->setSyncAttributes($sync_attributes)
            ->execute();
    }

    public static function testConnection(string $connection = 'default')
    {
        $connected = false;
        try {
            // Get the default connection from config/ldap.php
            $connection = Container::getConnection($connection);

            // Attempt to bind/connect
            $connection->connect();

            $connected = true;
        } catch (\Throwable $e) {
        }

        return $connected;
    }
}
