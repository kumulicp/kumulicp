<?php

// An organization sees that its app is in maintenance mode, on the app's page and in the apps list.

use App\User;
use Tests\Support\TestSupports;

beforeEach(function () {
    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();

    $this->actingAs(User::where('username', 'demo')->firstOrFail());
    $this->demoApp = $support->demo_app->instances()->first();
});

it('shows an app in maintenance mode on its page and in the apps list', function () {
    $this->get('/apps/'.$this->demoApp->id.'/edit')
        ->assertInertia(fn ($page) => $page->where('app.maintenance', false));

    $this->get('/apps')
        ->assertInertia(fn ($page) => $page->where('apps.0.maintenance', false));

    $this->demoApp->updateSetting('maintenance.enabled', true);

    $this->get('/apps/'.$this->demoApp->id.'/edit')
        ->assertInertia(fn ($page) => $page->where('app.maintenance', true));

    $this->get('/apps')
        ->assertInertia(fn ($page) => $page->where('apps.0.maintenance', true));
});
