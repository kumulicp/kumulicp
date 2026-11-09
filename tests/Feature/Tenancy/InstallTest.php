<?php

use App\Enums\TenancyMode;
use App\Organization;
use App\Support\Facades\Settings;
use App\Support\Facades\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    setupFakeServerInterfaces();
});

function installPayload(array $overrides = []): array
{
    return array_merge([
        'username' => 'admin',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'contact_first_name' => 'Ada',
        'contact_last_name' => 'Admin',
        'contact_email' => 'ada@example.com',
        'slug' => 'acme',
    ], $overrides);
}

it('installs a single organization with the name it was given', function () {
    $this->post('/install', installPayload([
        'tenancy_mode' => 'single',
        'organization_name' => 'Acme Church',
    ]))->assertSessionHasNoErrors();

    expect(Tenancy::isSingle())->toBeTrue();
    expect(Settings::get('installed'))->toBe('1');

    $organization = Organization::where('type', 'superaccount')->sole();
    expect($organization->name)->toBe('Acme Church');
    expect($organization->status)->toBe('active');
});

it('installs as multi-tenant when no mode is sent', function () {
    $this->post('/install', installPayload())->assertSessionHasNoErrors();

    expect(Tenancy::mode())->toBe(TenancyMode::Multi);
    expect(Organization::where('type', 'superaccount')->sole()->name)->toBe(__('setup.first_org'));
});

it('requires an organization name for a single organization install', function () {
    $this->post('/install', installPayload(['tenancy_mode' => 'single']))
        ->assertSessionHasErrors('organization_name');

    expect(Organization::count())->toBe(0);
});

it('rejects an unknown mode', function () {
    $this->post('/install', installPayload(['tenancy_mode' => 'galaxy']))
        ->assertSessionHasErrors('tenancy_mode');
});

it('lets TENANCY_MODE override what the form sends', function () {
    config(['tenancy.mode' => 'single']);

    $this->post('/install', installPayload([
        'tenancy_mode' => 'multi',
        'organization_name' => 'Acme Church',
    ]))->assertSessionHasNoErrors();

    expect(Tenancy::isSingle())->toBeTrue();
    expect(Organization::where('type', 'superaccount')->sole()->name)->toBe('Acme Church');
});

it('does not reinstall over an existing install', function () {
    $this->post('/install', installPayload(['tenancy_mode' => 'single', 'organization_name' => 'First']));
    $this->post('/install', installPayload(['tenancy_mode' => 'multi', 'username' => 'other', 'contact_email' => 'o@example.com']))
        ->assertRedirect('/');

    expect(Tenancy::isSingle())->toBeTrue();
    expect(Organization::count())->toBe(1);
});
