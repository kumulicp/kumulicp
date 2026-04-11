<?php

namespace App\Jobs\Applications;

use App\AppInstance;
use App\Ldap\Actions\Dn;
use App\Ldap\Models\Group;
use App\Support\Facades\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateLDAPGroups implements ShouldQueue
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

        $app_instance = Application::instance($this->app_instance);

        $enabled_groups = [];
        foreach ($app_instance->enabledGroups() as $role) {
            $enabled_groups[] = $role->app_slug($app_instance->get());
        }

        $app_name = $app_instance->parent ? $app_instance->parent->name : $app_instance->name;
        $ldapRoles = Group::in(Dn::create($app_instance->organization, 'applications', $app_name))->get();
        if (is_array($ldapRoles)) {
            foreach ($ldapRoles as $role) {
                if ($role->application()?->is($app_instance) && ! in_array($role->getFirstAttribute('cn'), $enabled_groups)) {
                    $role->member = Dn::create($app_instance->organization, 'applications', $app_name);
                    $role->save();
                }
            }
        }
    }
}
