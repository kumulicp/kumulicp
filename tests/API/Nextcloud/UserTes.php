<?php

use App\AppInstance;
use App\Integrations\Applications\Nextcloud\API\Users;
use Illuminate\Support\Str;
use Tests\Support\TestSupports;

it('manages nextcloud users via API', function () {
    $support = new TestSupports;
    $support->seed();
    $nextcloud = AppInstance::where('name', 'nextcloud')->first();

    $userid = Str::lower(fake()->name());
    $users = new Users($nextcloud);
    $add = $users->add([
        'username' => $userid,
        'password' => Str::random(40),
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'email' => fake()->email(),
        'groups' => [],
        'subadmin' => [],
        'quota' => '1.5Gb',
        'language' => '',
    ]);
    expect((string) $add->id)->toBe($userid);

    $find = $users->find($userid);
    expect((int) $find->enabled)->toBe(1);
    expect(count($users->groups()))->toBe(0);

    $users->addToGroup('admin');
    expect(count($users->groups()))->toBe(1);
    expect($users->checkPermission('admin'))->toBeTrue();

    $users->removeFromGroup('admin');
    expect($users->checkPermission('admin'))->toBeFalse();
});
