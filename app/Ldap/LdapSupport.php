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
        // Kept distinct from $app_instance below: the group DN must stay
        // scoped under the child's own org (matching AddLdapGroups::createRole())
        // so each org gets its own group -- reassigning $app_instance to the
        // shared-app parent before building the DN would put every child's
        // group under the hub's org instead, collapsing them into one shared
        // group across all orgs.
        $organization = $app_instance->organization;
        $app_instance_id = self::getAppInstanceID($app_instance);
        $is_shared_child = (bool) $app_instance->sharedPlanParent();
        $app_label = $app_instance->application->name;

        if ($is_shared_child && ! $app_instance->usesMultisitePermissions()) {
            $app_instance = $app_instance->parent;
        }
        $role_slug = $role->app_slug($app_instance);
        $role_dn = Dn::create($organization, 'applications', [$role_slug, $app_instance_id]);
        $group = Group::find($role_dn);

        if (! $group) {
            // The hub's own activation only ever creates the app-level
            // container (cn=$app_instance_id,ou=applications,...) under its
            // own org -- a shared-app child's org never gets one, so the
            // very first role group for a new org would otherwise fail to
            // save with "No such object".
            self::ensureAppContainer($organization, $app_instance_id, $app_label);

            $group = new Group;
            $group->inside(Dn::create($organization, 'applications', $app_instance_id));
            $group->setAttribute('cn', $role_slug);
            // For a shared-app child, the org name (not the generic role
            // name) is what lets this group be told apart from every other
            // org's group in the shared app's own group picker/sharing UI.
            $group->setAttribute('description', $is_shared_child ? $organization->name : $role->name);
            $group->setAttribute('member', Dn::create($organization));
            $group->save();
        }

        return $group;
    }

    public static function ensureAppContainer(Organization $organization, string $app_instance_id, ?string $description = null): Group
    {
        $dn = Dn::create($organization, 'applications', $app_instance_id);
        $container = Group::find($dn);

        if (! $container) {
            $container = new Group;
            $container->inside(Dn::create($organization, 'applications'));
            $container->setAttribute('cn', $app_instance_id);
            $container->setAttribute('description', $description ?? $app_instance_id);
            $container->setAttribute('member', Dn::create($organization));
            $container->save();
        }

        return $container;
    }

    // One per shared-app hub -- every group folder a shared Nextcloud
    // provisions is nominally attached to this (Nextcloud requires a group
    // folder to have *some* group), and it's what LOGIN_FILTER/USER_FILTER
    // gate basic access to the shared instance on. It is NOT what enforces
    // per-org exclusivity -- that's each folder's own ACL, scoped to that
    // org's users. Callers keep this group's membership in sync with each
    // child's own per-org role group (see AddLdapGroups, Permissions).
    public static function centralAccessGroup(AppInstance $hub)
    {
        $dn = Dn::create($hub->organization, 'applications', ['access', $hub->name]);
        $group = Group::find($dn);

        if (! $group) {
            $group = new Group;
            $group->inside(Dn::create($hub->organization, 'applications', $hub->name));
            $group->setAttribute('cn', 'access');
            $group->setAttribute('description', $hub->label);
            $group->setAttribute('member', Dn::create($hub->organization));
            $group->save();
        }

        return $group;
    }

    public static function getAppInstanceID(AppInstance $app_instance)
    {
        if (! $app_instance->parent || $app_instance->usesMultisitePermissions()) {
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
