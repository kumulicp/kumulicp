<?php

use App\Organization;
use App\User;
use Tests\Support\TestSupports;

beforeEach(function () {
    (new TestSupports)->seed();
    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->organization = $this->user->organization;
});

it('gives new organizations the system default language', function () {
    config(['app.locale' => 'es']);

    expect(Organization::systemDefaultLocale())->toBe('es');

    $organization = new Organization;
    $organization->name = 'Locale Org';
    $organization->save();

    expect($organization->fresh()->default_locale)->toBe('es');
});

it('gives new users their organization default language', function () {
    $this->organization->update(['default_locale' => 'es']);

    $user = new User;
    $user->username = 'localeuser';
    $user->name = 'Locale User';
    $user->email = 'locale@example.com';
    $user->password = 'secret';
    $user->organization_id = $this->organization->id;
    $user->save();

    expect($user->fresh()->locale)->toBe('es');
});

it('does not change existing user languages when the organization default changes', function () {
    $this->user->update(['locale' => 'en']);

    $this->organization->default_locale = 'es';
    $this->organization->save();

    expect($this->user->fresh()->locale)->toBe('en');
});

it('requires a language on profile update', function () {
    $this->actingAs($this->user)->post('/profile', [
        'first_name' => 'Demo',
        'last_name' => 'User',
        'personal_email' => 'demo@example.com',
        'locale' => '',
    ])->assertSessionHasErrors('locale');
});
