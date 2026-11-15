<?php

use App\Application;
use App\AppRole;
use App\AppVersion;
use Illuminate\Database\Eloquent\Collection;

it('gives a collection of roles even when the version has none set up', function () {
    $application = Application::factory()->create();
    $version = AppVersion::factory()->create(['application_id' => $application->id, 'roles' => null]);

    $roles = $version->roles(all: false);

    // Callers map() and merge() it, which an array has neither of
    expect($roles)->toBeInstanceOf(Collection::class)
        ->and($roles->map(fn ($role) => $role->id)->all())->toBe([])
        ->and($roles)->toHaveCount(0);
});

it('gives the roles the version has set up', function () {
    $application = Application::factory()->create();
    $role = AppRole::factory()->create(['application_id' => $application->id]);
    $version = AppVersion::factory()->create(['application_id' => $application->id, 'roles' => ['order' => [$role->id]]]);

    expect($version->roles()->pluck('id')->all())->toBe([$role->id]);
});
