<?php

namespace App\Jobs\Applications;

use App\AppInstance;
use App\Ldap\Actions\Dn;
use App\Ldap\Models\Group;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RemoveLDAPGroups implements ShouldQueue
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

        if (! $app_instance->parent) {
            $LdapApp = Group::find(Dn::create($organization, 'applications', $app_instance->name));
            $LdapApp?->delete($recursive = true);
        } else {
            foreach ($app->roles as $role) {
                $LdapGroup = Group::find(Dn::create($organization, 'applications', [$role->app_slug($app_instance), $app_instance->parent->name]));
                $LdapGroup?->delete();
            }
        }
    }
}
