<?php

use App\Enums\TenancyMode;
use App\Organization;
use App\Support\Facades\Settings;
use App\Support\Facades\Tenancy;
use Tests\Support\TestSupports;

it('is multi-tenant when no mode was ever stored', function () {
    expect(Tenancy::mode())->toBe(TenancyMode::Multi);
    expect(Tenancy::isMulti())->toBeTrue();
    expect(Tenancy::isSingle())->toBeFalse();
});

it('is multi-tenant when the stored mode is not recognised', function () {
    Settings::update('tenancy_mode', 'nonsense');

    expect(Tenancy::mode())->toBe(TenancyMode::Multi);
});

it('stores the mode it is given', function (TenancyMode $mode) {
    Tenancy::set($mode);

    expect(Tenancy::mode())->toBe($mode);
    $this->assertDatabaseHas('server_settings', ['key' => 'tenancy_mode', 'value' => $mode->value]);
})->with([TenancyMode::Single, TenancyMode::Multi]);

it('can be switched from the test helpers', function () {
    useSingleTenancy();
    expect(Tenancy::isSingle())->toBeTrue();

    useMultiTenancy();
    expect(Tenancy::isMulti())->toBeTrue();
});

it('preselects multi in the installer unless TENANCY_MODE says otherwise', function () {
    expect(Tenancy::installerMode())->toBeNull();
    expect(Tenancy::installerDefault())->toBe(TenancyMode::Multi);

    config(['tenancy.mode' => 'single']);

    expect(Tenancy::installerMode())->toBe(TenancyMode::Single);
    expect(Tenancy::installerDefault())->toBe(TenancyMode::Single);

    config(['tenancy.mode' => 'garbage']);

    expect(Tenancy::installerMode())->toBeNull();
});

it('finds the superaccount as the single organization', function () {
    expect(Tenancy::organization())->toBeNull();

    (new TestSupports)->seed();
    $organization = Organization::where('slug', 'demo')->firstOrFail();
    $organization->type = 'superaccount';
    $organization->save();

    expect(Tenancy::organization()->is($organization))->toBeTrue();
});

it('gives each mode a translated label and description', function (TenancyMode $mode) {
    expect($mode->label())->not->toContain('setup.tenancy');
    expect($mode->description())->not->toContain('setup.tenancy');
})->with(TenancyMode::cases());
