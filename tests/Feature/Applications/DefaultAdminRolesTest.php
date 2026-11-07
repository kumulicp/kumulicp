<?php

use App\Application;
use App\AppRole;
use App\AppVersion;

/**
 * The roles an organization's admins are given in an app are the ones set on
 * the version page, which stores them under default_admin_groups. The slugs
 * the activation hands out must come from the same place.
 */
it('gives the admins the default admin roles set on the version', function () {
    $application = Application::factory()->create();
    $administrator = AppRole::factory()->create(['application_id' => $application->id, 'slug' => 'administrator', 'label' => 'Administrator', 'ignore_role' => false, 'required_features' => [], 'access_type' => 'standard']);
    $employee = AppRole::factory()->create(['application_id' => $application->id, 'slug' => 'employee', 'label' => 'Employee', 'ignore_role' => false, 'required_features' => [], 'access_type' => 'standard']);
    $version = AppVersion::factory()->create(['application_id' => $application->id]);
    $version->roles = ['order' => [$administrator->id, $employee->id], 'default_user_groups' => [], 'default_admin_groups' => [$administrator->id]];
    $version->save();

    expect($version->defaultAdminRoleSlugs())->toBe(['administrator'])
        ->and(collect($version->defaultAdminRoles())->pluck('slug')->all())->toBe(['administrator']);
});

it('has no default admin roles when none are set', function () {
    $application = Application::factory()->create();
    $version = AppVersion::factory()->create(['application_id' => $application->id]);
    $version->roles = ['order' => [], 'default_user_groups' => [], 'default_admin_groups' => []];
    $version->save();

    expect($version->defaultAdminRoleSlugs())->toBe([]);
});
