<?php

use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('calls an app plan a profile in single mode and a plan in multi-tenant mode', function (string $mode, string $expected) {
    (new TestSupports)->seed();
    $mode === 'single' ? useSingleTenancy() : useMultiTenancy();

    $this->actingAs(User::where('username', 'demo')->firstOrFail())->get('/settings/organization');

    expect(__('organization.readiness.plan_missing.action'))->toBe($expected);
})->with([
    'single' => ['single', 'Add a profile'],
    'multi' => ['multi', 'Add a plan'],
]);

it('uses the profile wording for the flash messages of the app plan pages in single mode', function () {
    (new TestSupports)->seed();
    useSingleTenancy();

    $this->actingAs(User::where('username', 'demo')->firstOrFail())->get('/settings/organization');

    expect(__('admin.applications.plans.added'))->toBe('Profile added');
    expect(__('admin.applications.plans.first_plan_name'))->toBe('First Profile');
});
