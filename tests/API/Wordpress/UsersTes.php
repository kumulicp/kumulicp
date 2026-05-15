<?php

use App\AppInstance;
use App\Integrations\Applications\Wordpress\API\User;
use Illuminate\Support\Arr;
use Tests\Support\TestSupports;

it('manages wordpress users via API', function () {
    $support = new TestSupports;
    $support->seed();

    $wordpress = AppInstance::where('name', 'wordpress')->first();

    $user = new User($wordpress);
    $user->getUserID('support');
    $update_roles = $user->updateUserRoles('support', ['administrator']);
    expect(in_array('administrator', Arr::get($update_roles, 'content.roles', [])))->toBeTrue();
});
