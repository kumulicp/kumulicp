<?php

use App\AppInstance;
use App\Integrations\Applications\Nextcloud\API\Apps;
use Tests\Support\TestSupports;

it('enables, disables, and finds nextcloud apps via API', function () {
    $support = new TestSupports;
    $support->seed();
    $nextcloud = AppInstance::where('name', 'nextcloud')->first();

    $apps = new Apps($nextcloud);

    $apps->enable('contacts');
    expect($apps->isEnabled('contacts'))->toBeTrue();

    $apps->disable('contacts');
    expect($apps->isEnabled('contacts'))->toBeFalse();

    $apps->find('contacts');
    expect($apps->data->id)->toBe('contacts');
});
