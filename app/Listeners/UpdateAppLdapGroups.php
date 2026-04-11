<?php

namespace App\Listeners;

use App\Events\AppInstanceSubscriptionChanged;
use App\Ldap\Actions\Dn;
use App\Ldap\Models\Group;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateAppLdapGroups implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $app_instance;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct() {}

    /**
     * Handle the event.
     *
     * @param  ApplicationActivating  $event
     * @return void
     */
    public function handle(AppInstanceSubscriptionChanged $event)
    {
        $app_instance = $event->app_instance;
        $organization = $app_instance->organization;
        $app = $app_instance->application;
        $version = $app_instance->version;

        Organization::setOrganization($organization);

        if ($app->permissions_type == 'none') {
            return;
        }

        if ($app_instance->parent_id == 0) {
            /** @LDAP **/
            $LdapApp = Group::find(Dn::create($organization, 'applications', $app_instance->name));
            if (! $LdapApp) {
                $LdapApp = new Group;
                $LdapApp->inside(Dn::create($organization, 'applications'));
                $LdapApp->setAttribute('cn', $app->slug);
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
            $LdapGroupCheck = Group::find(Dn::create($organization, 'applications', [$role->app_slug($app_instance), $add_group_app->name]));
            if (! $LdapGroupCheck) {
                $LdapGroup = new Group;
                $LdapGroup->inside(Dn::create($organization, 'applications', $add_group_app->name));
                $LdapGroup->cn = $role->app_slug($app_instance);
                $LdapGroup->description = $role->label;
                $LdapGroup->member = Dn::create($organization);
                $LdapGroup->save();

                // If app has a default group, add cp admins to default admin group
                if (in_array($role->slug, $default_admin_roles)) {
                    foreach (AccountManager::users()->orgAdmins() as $org_admin) {
                        $org_admin->permissions()->addAppRole($app_instance, $role->slug);
                    }
                }
            }
        }
    }
}
