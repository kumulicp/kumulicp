<?php

use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Server;
use App\Services\Application\ApplicationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// The seeded database has one default web server, so servers are fine unless a test removes it.

beforeEach(function () {
    (new TestSupports)->seed();
});

/**
 * An app that is ready by default; each argument takes one thing away.
 */
function readinessApp(array $app = [], bool $version = true, bool $plan = true, bool $edited = true): Application
{
    $application = Application::factory()->create(array_merge(['enabled' => true], $app));

    if ($version) {
        AppVersion::factory()->create(['application_id' => $application->id]);
    }

    if ($plan) {
        $app_plan = AppPlan::factory()->create(['application_id' => $application->id]);

        if ($edited) {
            test()->travel(1)->minute();
            $app_plan->update(['name' => 'Edited']);
            test()->travelBack();
        }
    }

    return $application;
}

function readinessIssue(ApplicationReadiness $readiness, string $key): ?array
{
    return collect($readiness->issues())->firstWhere('key', $key);
}

it('has nothing to say about an app that is ready', function () {
    $readiness = new ApplicationReadiness(readinessApp());

    expect($readiness->issues())->toBe([]);
    expect($readiness->isReady())->toBeTrue();
    expect($readiness->status())->toBe('ready');
});

it('blocks an app that is turned off and links to where it is turned on', function () {
    $app = readinessApp(['enabled' => false]);
    $readiness = new ApplicationReadiness($app);

    $issue = readinessIssue($readiness, 'enabled');
    expect($issue['blocking'])->toBeTrue();
    expect($issue['actions'][0]['url'])->toBe("/admin/apps/{$app->slug}/edit");
    expect($readiness->isReady())->toBeFalse();
    expect($readiness->status())->toBe('blocked');
});

it('blocks an app with no version and links to adding one', function () {
    $app = readinessApp(version: false);

    $issue = readinessIssue(new ApplicationReadiness($app), 'version');

    expect($issue['blocking'])->toBeTrue();
    expect($issue['actions'][0]['url'])->toBe("/admin/apps/{$app->slug}/versions/create");
});

it('blocks an app whose versions are all disabled and links to the newest one', function () {
    $app = readinessApp(version: false);
    AppVersion::factory()->create(['application_id' => $app->id, 'name' => '1.0', 'status' => 'deactivated']);
    AppVersion::factory()->create(['application_id' => $app->id, 'name' => '2.0', 'status' => 'deactivated']);

    $issue = readinessIssue(new ApplicationReadiness($app), 'version');

    expect($issue['blocking'])->toBeTrue();
    expect($issue['message'])->toContain('2.0');
    expect($issue['actions'][0]['url'])->toBe("/admin/apps/{$app->slug}/versions/2.0");
});

it('blocks an app with no plan and offers to create a default one', function () {
    $app = readinessApp(plan: false);

    $issue = readinessIssue(new ApplicationReadiness($app), 'plan');

    expect($issue['blocking'])->toBeTrue();
    expect($issue['actions'][0])->toMatchArray(['url' => "/admin/apps/{$app->slug}/plans/default", 'method' => 'post']);
    expect($issue['actions'][1])->toMatchArray(['url' => "/admin/apps/{$app->slug}/plans/create", 'method' => 'get']);
});

it('does not count archived or hidden plans', function () {
    $app = readinessApp(plan: false);
    AppPlan::factory()->create(['application_id' => $app->id, 'archive' => true]);
    AppPlan::factory()->create(['application_id' => $app->id, 'hidden' => true]);

    expect(readinessIssue(new ApplicationReadiness($app), 'plan'))->not->toBeNull();
});

it('only warns about a plan nobody has edited, and does not block on it', function () {
    $app = readinessApp(edited: false);
    $plan = $app->plans()->first();

    $readiness = new ApplicationReadiness($app);
    $issue = readinessIssue($readiness, 'plan_configured');

    expect($issue['blocking'])->toBeFalse();
    expect($issue['actions'][0]['url'])->toBe("/admin/apps/{$app->slug}/plans/{$plan->id}/edit");
    expect($readiness->isReady())->toBeTrue();
    expect($readiness->status())->toBe('warning');
});

it('stops warning once the plan has been edited', function () {
    $app = readinessApp(edited: false);
    $plan = $app->plans()->first();

    test()->travel(1)->minute();
    $plan->update(['description' => 'Changed']);
    test()->travelBack();

    expect(readinessIssue(new ApplicationReadiness($app), 'plan_configured'))->toBeNull();
});

it('leaves a plan that connects to a shared app out of the unedited warning', function () {
    $app = readinessApp(plan: false);
    AppPlan::factory()->create([
        'application_id' => $app->id,
        'settings' => ['server_type' => 'shared', 'base' => [], 'standard' => [], 'basic' => [], 'storage' => [], 'application' => []],
    ]);

    $readiness = new ApplicationReadiness($app);

    expect(readinessIssue($readiness, 'plan'))->toBeNull();
    expect(readinessIssue($readiness, 'plan_configured'))->toBeNull();
});

it('blocks when there is no server to run apps on', function () {
    Server::query()->update(['default_web_server' => 0]);
    $app = readinessApp();

    $issue = readinessIssue(new ApplicationReadiness($app), 'servers');

    expect($issue['blocking'])->toBeTrue();
    expect($issue['actions'][0]['url'])->toBe('/admin/server/servers');
});

it('does not need a default server when the plan names its own', function () {
    Server::query()->update(['default_web_server' => 0]);
    $app = readinessApp();
    $app->plans()->update(['web_server_id' => Server::query()->value('id')]);

    expect(readinessIssue(new ApplicationReadiness($app), 'servers'))->toBeNull();
});

it('lists every problem at once, in the order they are fixed', function () {
    Server::query()->update(['default_web_server' => 0]);
    $app = readinessApp(['enabled' => false], version: false, plan: false);

    expect(collect((new ApplicationReadiness($app))->issues())->pluck('key')->all())
        ->toBe(['enabled', 'version', 'plan', 'servers']);
});
