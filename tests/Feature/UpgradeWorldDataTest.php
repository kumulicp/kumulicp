<?php

use App\Support\Facades\Settings;
use Composer\InstalledVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Nnjeim\World\Models\Country;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    (new TestSupports)->seed();
});

it('loads the world data on first run and records the package version', function () {
    Country::query()->delete();

    $this->artisan('upgrade')->assertSuccessful();

    expect(Country::where('iso2', 'CA')->exists())->toBeTrue();
    expect(Settings::get('world_data_version'))->toBe(InstalledVersions::getPrettyVersion('nnjeim/world'));
});

it('does not reload the world data when the package version is unchanged', function () {
    $this->artisan('upgrade')->assertSuccessful();

    Country::where('iso2', 'CA')->delete();

    $this->artisan('upgrade')->assertSuccessful();

    expect(Country::where('iso2', 'CA')->exists())->toBeFalse();
});

it('reloads the world data when the package version changes', function () {
    $this->artisan('upgrade')->assertSuccessful();

    Country::where('iso2', 'CA')->delete();
    Settings::update('world_data_version', '0.0.1');

    $this->artisan('upgrade')->assertSuccessful();

    expect(Country::where('iso2', 'CA')->exists())->toBeTrue();
});

it('clears the cached location lists after reloading', function () {
    Cache::put('locations.countries', ['stale'], now()->addDay());
    Country::query()->delete();

    $this->artisan('upgrade')->assertSuccessful();

    expect(Cache::has('locations.countries'))->toBeFalse();
});
