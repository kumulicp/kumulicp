<?php

use App\AppInstance;
use App\Application;
use App\AppVersion;
use App\Enums\TenancyMode;
use App\Organization;
use App\Support\Facades\FastCache;
use App\Support\Facades\Settings;
use App\Support\Facades\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// The seeded database has one organization, the superaccount, and is installed.

beforeEach(function () {
    (new TestSupports)->seed();
});

it('shows the current mode when no mode is given', function () {
    $this->artisan('kumulicp:tenancy')
        ->expectsOutputToContain('This install is multi')
        ->assertExitCode(0);

    useSingleTenancy();

    $this->artisan('kumulicp:tenancy')
        ->expectsOutputToContain('This install is single')
        ->assertExitCode(0);
});

it('rejects a mode it does not know', function () {
    $this->artisan('kumulicp:tenancy', ['mode' => 'galaxy'])
        ->expectsOutputToContain('Unknown mode')
        ->assertExitCode(1);

    expect(Tenancy::isMulti())->toBeTrue();
});

it('has nothing to do when the install is already in that mode', function () {
    $this->artisan('kumulicp:tenancy', ['mode' => 'multi'])
        ->expectsOutputToContain('already multi')
        ->assertExitCode(0);
});

it('switches to single and clears what was cached for the old mode', function () {
    FastCache::shouldReceive('clearAll')->once();

    $this->artisan('kumulicp:tenancy', ['mode' => 'single', '--force' => true])
        ->expectsOutputToContain('now single')
        ->assertExitCode(0);

    expect(Tenancy::mode())->toBe(TenancyMode::Single);
    $this->assertDatabaseHas('server_settings', ['key' => 'tenancy_mode', 'value' => 'single']);
});

it('asks before switching, and changes nothing on no', function () {
    $this->artisan('kumulicp:tenancy', ['mode' => 'single'])
        ->expectsConfirmation('Switch this install to single?', 'no')
        ->expectsOutputToContain('Nothing was changed')
        ->assertExitCode(1);

    expect(Tenancy::isMulti())->toBeTrue();

    FastCache::shouldReceive('clearAll')->once();

    $this->artisan('kumulicp:tenancy', ['mode' => 'single'])
        ->expectsConfirmation('Switch this install to single?', 'yes')
        ->assertExitCode(0);

    expect(Tenancy::isSingle())->toBeTrue();
});

it('switches back to multi, with a warning about what it leaves behind', function () {
    useSingleTenancy();
    FastCache::shouldReceive('clearAll')->once();

    $this->artisan('kumulicp:tenancy', ['mode' => 'multi', '--force' => true])
        ->expectsOutputToContain('billing and sign-up still have to be set up')
        ->assertExitCode(0);

    expect(Tenancy::isMulti())->toBeTrue();
});

it('will not switch to single while there is more than one organization', function () {
    Organization::factory()->create(['name' => 'Acme Church', 'slug' => 'acme', 'type' => 'business']);

    // The count and the names are one line of output, and an expectation consumes the line it matches
    $this->artisan('kumulicp:tenancy', ['mode' => 'single', '--force' => true])
        ->expectsOutputToContain('Acme Church. Delete the others first.')
        ->assertExitCode(1);

    expect(Organization::notTest()->count())->toBe(2);

    expect(Tenancy::isMulti())->toBeTrue();
});

it('does not count the organizations that version tests make', function () {
    Organization::factory()->create(['slug' => 'a-test-org', 'type' => 'business', 'account_test_id' => 5]);

    expect(Tenancy::whySingleIsNotPossible())->toBe([]);
});

it('will not switch to single when the one organization is not the administrator account', function () {
    $organization = Organization::find(1);
    $organization->type = 'business';
    $organization->save();

    $this->artisan('kumulicp:tenancy', ['mode' => 'single', '--force' => true])
        ->expectsOutputToContain('is not the administrator account')
        ->assertExitCode(1);

    expect(Tenancy::isMulti())->toBeTrue();
});

it('will not switch to single while shared apps are running', function () {
    $shared = Organization::factory()->create(['slug' => 'shared', 'type' => 'shared']);
    $app = Application::factory()->create(['slug' => 'hub-app']);
    $version = AppVersion::factory()->create(['application_id' => $app->id]);
    AppInstance::factory()->create(['organization_id' => $shared->id, 'application_id' => $app->id, 'version_id' => $version->id, 'label' => 'Hub']);

    $this->artisan('kumulicp:tenancy', ['mode' => 'single', '--force' => true])
        ->expectsOutputToContain('Shared apps are running')
        ->assertExitCode(1);

    expect(Tenancy::isMulti())->toBeTrue();
});

it('is not available before the control panel is installed', function () {
    Settings::remove('installed');

    $this->artisan('kumulicp:tenancy', ['mode' => 'single', '--force' => true])
        ->expectsOutputToContain('not installed yet')
        ->assertExitCode(1);
});
