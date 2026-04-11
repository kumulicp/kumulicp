<?php

namespace App\Jobs\Applications;

use App\AppInstance;
use App\AppRole;
use App\Ldap\Actions\Dn;
use App\Ldap\Models\Group;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AddLdapGroups implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $app_instance;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(AppInstance $app_instance)
    {
        $this->app_instance = $app_instance;
    }

    /**
     * Handle the event.
     *
     * @param  ApplicationActivating  $event
     * @return void
     */
    public function handle()
    {
        if (env('ACCOUNTMANAGER_DRIVER') !== 'ldap') {
            return;
        }

        $app_instance = $this->app_instance;
        $organization = $app_instance->organization;
        $app = $app_instance->application;
        $version = $app_instance->version;

        Organization::setOrganization($organization);
        if ($app_instance->parent_id == 0) {
            /** @LDAP **/
            $LdapApp = Group::find(Dn::create($organization, 'applications', $app_instance->name));
            if (! $LdapApp) {
                $LdapApp = new Group;
                $LdapApp->inside(Dn::create($organization, 'applications'));
                $LdapApp->setAttribute('cn', $app_instance->name);
                $LdapApp->setAttribute('description', $app->name);
                $LdapApp->member = Dn::create($organization);
                $LdapApp->save();
            }

            $add_group_app = $app_instance;
        } else {
            $add_group_app = $app_instance->parent;
        }

        $default_admin_roles = [];

        foreach ($version->defaultAdminRoles() as $default_group) {
            $default_admin_roles[] = $default_group->slug;
        }

        $roles = $version->roles();
        foreach ($roles as $role) {
            $this->createRole($organization, $add_group_app, $role->app_slug($add_group_app));

            // If app has a default group, add cp admins to default admin group
            if (in_array($role->slug, $default_admin_roles)) {
                $this->addDefaultAdminRole($app_instance, $role->slug, 'role');
            }

            // Add implied groups
            foreach ($role->implied_roles as $implied_role) {
                $this->createRole($organization, $add_group_app, $implied_role->app_slug($add_group_app), $implied_role->label);

                // If app has a default group, add cp admins to default admin group
                if (in_array($role->slug, $default_admin_roles)) {
                    $this->addDefaultAdminRole($app_instance, $role->slug, 'role');
                }
            }
        }
    }

    private function createRole(\App\Organization $organization, AppInstance $app_instance, string $role_slug, ?string $role_description = null)
    {
        $role_exists = Group::find(Dn::create($organization, 'applications', [$role_slug, $app_instance->name]));
        if (! $role_exists) {
            $LdapGroup = new Group;
            $LdapGroup->inside(Dn::create($organization, 'applications', $app_instance->name));
            $LdapGroup->cn = $role_slug;
            $LdapGroup->description = $role_description;
            $LdapGroup->member = Dn::create($organization);
            $LdapGroup->save();
        }
    }

    private function addDefaultAdminRole(AppInstance $app_instance, AppRole $role, string $permission_type)
    {
        foreach (AccountManager::users()->orgAdmins() as $org_admin) {
            $org_admin->permissions()->addAppRole($app_instance, $role);
        }
    }
}
