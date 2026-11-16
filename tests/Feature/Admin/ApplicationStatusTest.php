<?php

use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// An app is never turned on or off by hand: it is ready or not, and the admin pages say which.
// The seeded demo user is an admin and the seeded database has a default web server.

beforeEach(function () {
    (new TestSupports)->seed();
    test()->actingAs(User::where('username', 'demo')->firstOrFail());
});

function statusApp(string $slug, bool $ready): Application
{
    $app = Application::factory()->create(['slug' => $slug, 'name' => ucfirst($slug), 'enabled' => false]);

    if ($ready) {
        AppVersion::factory()->create(['application_id' => $app->id]);
        AppPlan::factory()->create(['application_id' => $app->id]);
    }

    return $app;
}

it('has no way to turn an app on or off by hand', function (string $mode) {
    $mode === 'single' ? useSingleTenancy() : useMultiTenancy();
    statusApp('some-app', ready: false);

    $this->post('/admin/apps/some-app/enable')->assertNotFound();
    $this->post('/admin/apps/some-app/disable')->assertNotFound();
})->with(['multi', 'single']);

it('says whether each app is ready on the apps list', function (string $mode) {
    $mode === 'single' ? useSingleTenancy() : useMultiTenancy();
    statusApp('ready-app', ready: true);
    statusApp('bare-app', ready: false);

    $this->get('/admin/apps')
        ->assertInertia(fn ($page) => $page->where('apps', function ($apps) {
            $apps = collect($apps)->keyBy('slug');

            return $apps['ready-app']['status'] === __('labels.ready')
                && $apps['bare-app']['status'] === __('labels.not_ready');
        }));
})->with(['multi', 'single']);

it('shows ready or not ready, and why, on the edit page instead of a button', function (string $mode) {
    $mode === 'single' ? useSingleTenancy() : useMultiTenancy();
    statusApp('ready-app', ready: true);
    statusApp('bare-app', ready: false);

    $this->get('/admin/apps/ready-app/edit')
        ->assertInertia(fn ($page) => $page
            ->where('app.enabled', true)
            ->where('app.status_label', __('labels.ready'))
            ->missing('app.toggle')
            ->where('readiness.status', 'warning'));

    $this->get('/admin/apps/bare-app/edit')
        ->assertInertia(fn ($page) => $page
            ->where('app.enabled', false)
            ->where('app.status_label', __('labels.not_ready'))
            ->where('readiness.status', 'blocked')
            ->where('readiness.items.0.key', 'version'));
})->with(['multi', 'single']);

it('shows the checklist on the app page in every mode', function (string $mode) {
    $mode === 'single' ? useSingleTenancy() : useMultiTenancy();
    statusApp('bare-app', ready: false);

    $this->get('/admin/apps/bare-app')
        ->assertInertia(fn ($page) => $page->where('readiness.status', 'blocked'));
})->with(['multi', 'single']);
