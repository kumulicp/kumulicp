<?php

use App\Support\Facades\Settings;
use Composer\InstalledVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nnjeim\World\Models\Country;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    (new TestSupports)->seed();
});

// nnjeim/world 2.x tracks every synced record in world_data_records and refuses to
// run when a tracked record has been deleted. To simulate "Canada was never loaded",
// remove the country together with its tracking rows (the country ISO is the scope
// of its states/timezones/currencies, and its own source key).
function forgetCountry(string $iso2): void
{
    $country = Country::where('iso2', $iso2)->firstOrFail();
    $table = config('world.migrations.data_records.table_name', 'world_data_records');

    DB::table($table)->where('scope', $iso2)->delete();
    DB::table($table)->where('module', 'countries')->where('record_id', $country->id)->delete();
    $country->delete();
}

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

    forgetCountry('CA');
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
