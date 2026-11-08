<?php

namespace App\Updates\Steps;

use App\Actions\ActionResult;
use App\AppInstance;
use App\Jobs\Applications\AddLdapGroups;
use App\Jobs\Applications\UpdateLDAPGroups;
use App\Task;
use App\Updates\UpdateStep;

/** Creates the groups in LDAP for the roles of the app's version, and drops the ones that are off */
class LdapGroupsStep extends UpdateStep
{
    public function key(): string
    {
        return 'ldap-groups';
    }

    public function label(): string
    {
        return __('admin.update_steps.ldap_groups.label');
    }

    public function description(): string
    {
        return __('admin.update_steps.ldap_groups.description');
    }

    public function appliesTo(AppInstance $app_instance): bool
    {
        return config('account_manager.driver') === 'ldap';
    }

    // A new version may have roles the old one didn't
    public function afterUpgrade(AppInstance $app_instance): bool
    {
        return true;
    }

    public function handle(Task $task, AppInstance $app_instance): ActionResult
    {
        // Here, not queued: the step is finished when the groups are
        AddLdapGroups::dispatchSync($app_instance);
        UpdateLDAPGroups::dispatchSync($app_instance);

        return ActionResult::done();
    }
}
