<?php

use App\Organization;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;

/**
 * Base lifecycle test using the DemoApp — always runs in CI with no external
 * infrastructure required. Covers activate → update → upgrade → delete using
 * FakeServerManager and FakeSSO via setupFakeServerInterfaces().
 *
 * This test is the contract every new Application integration must satisfy.
 * WordPress, Nextcloud, and other apps have their own tests that reuse the
 * TestsApplicationLifecycle trait and add app-specific assertions.
 */

uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
});

afterEach(function () {
    $this->restoreServerInterfaces();
});

it('completes the full application lifecycle with DemoApp', function () {
    $support = new TestSupports;
    $support->seed();

    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();

    $org = Organization::find(1);

    $instance = $this->runActivate($plan, $org, $app);
    $this->runUpdate($instance);
    $this->runUpgrade($instance);
    $this->runDelete($instance);
});
