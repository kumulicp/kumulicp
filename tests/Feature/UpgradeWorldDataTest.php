<?php

use App\Support\Facades\Settings;
use Composer\InstalledVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Nnjeim\World\Models\Country;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// nnjeim/world refuses to seed below 512M, and exits the whole PHP process when it is lower. Whatever ran
// earlier in the same process may have lowered the limit (laravel-filemanager's ini overrides set 256M),
// so this test makes sure it has what the seeder needs instead of depending on test order.
function ensureWorldSeederMemory(): void
{
    $limit = ini_get('memory_limit');

    if ($limit === '-1') {
        return;
    }

    $bytes = (int) $limit * match (strtoupper(substr($limit, -1))) {
        'G' => 1024 ** 3,
        'M' => 1024 ** 2,
        'K' => 1024,
        default => 1,
    };

    if ($bytes < 512 * 1024 ** 2) {
        ini_set('memory_limit', '512M');
    }
}

beforeEach(function () {
    ensureWorldSeederMemory();
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
