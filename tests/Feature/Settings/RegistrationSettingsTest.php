<?php

// /admin/settings/registration: the captcha that protects the public self-registration form.
// Finding 8 of docs/plans/self-registration.md: the secret key must stay on the server, and a
// provider without its keys must not be saved, because every registration would then fail.

use App\Support\Facades\Settings;
use App\User;
use Tests\Support\TestSupports;

beforeEach(function () {
    (new TestSupports)->seed();

    $this->actingAs(User::where('username', 'demo')->firstOrFail());
});

function savedCaptcha(array $overrides = []): array
{
    return array_merge([
        'captcha_provider' => 'turnstile',
        'captcha_site_key' => 'site-key',
        'captcha_secret_key' => 'secret-key',
    ], $overrides);
}

it('shows the provider and site key but never the secret key', function () {
    Settings::update('captcha_provider', 'turnstile');
    Settings::update('captcha_site_key', 'site-key');
    Settings::update('captcha_secret_key', 'secret-key');

    $this->get('/admin/settings/registration')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('settings.captcha_provider', 'turnstile')
            ->where('settings.captcha_site_key', 'site-key')
            ->where('settings.captcha_secret_key_set', true)
            ->missing('settings.captcha_secret_key'));
});

it('says no secret is stored when there is none', function () {
    $this->get('/admin/settings/registration')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('settings.captcha_secret_key_set', false)
            ->missing('settings.captcha_secret_key'));
});

it('saves a provider with both keys', function () {
    $this->put('/admin/settings/registration', savedCaptcha())->assertSessionHasNoErrors();

    expect(Settings::get('captcha_provider'))->toBe('turnstile')
        ->and(Settings::get('captcha_site_key'))->toBe('site-key')
        ->and(Settings::get('captcha_secret_key'))->toBe('secret-key');
});

it('keeps the stored secret when the field is left blank', function () {
    $this->put('/admin/settings/registration', savedCaptcha())->assertSessionHasNoErrors();

    $this->put('/admin/settings/registration', savedCaptcha(['captcha_provider' => 'hcaptcha', 'captcha_site_key' => 'new-site', 'captcha_secret_key' => '']))
        ->assertSessionHasNoErrors();

    expect(Settings::get('captcha_provider'))->toBe('hcaptcha')
        ->and(Settings::get('captcha_site_key'))->toBe('new-site')
        ->and(Settings::get('captcha_secret_key'))->toBe('secret-key');
});

it('replaces the secret when a new one is typed', function () {
    $this->put('/admin/settings/registration', savedCaptcha())->assertSessionHasNoErrors();

    $this->put('/admin/settings/registration', savedCaptcha(['captcha_secret_key' => 'another-secret']))
        ->assertSessionHasNoErrors();

    expect(Settings::get('captcha_secret_key'))->toBe('another-secret');
});

it('needs the keys when a provider is chosen', function (array $overrides, string $field) {
    $this->put('/admin/settings/registration', savedCaptcha($overrides))->assertSessionHasErrors($field);

    expect(Settings::get('captcha_provider'))->toBeEmpty();
})->with([
    'no site key' => [['captcha_site_key' => ''], 'captcha_site_key'],
    'no secret key the first time' => [['captcha_secret_key' => ''], 'captcha_secret_key'],
]);

it('rejects a provider it does not know', function () {
    $this->put('/admin/settings/registration', savedCaptcha(['captcha_provider' => 'recaptcha']))
        ->assertSessionHasErrors('captcha_provider');
});

it('clears the keys when the captcha is turned off', function () {
    $this->put('/admin/settings/registration', savedCaptcha())->assertSessionHasNoErrors();

    $this->put('/admin/settings/registration', ['captcha_provider' => null, 'captcha_site_key' => 'ignored', 'captcha_secret_key' => ''])
        ->assertSessionHasNoErrors();

    expect(Settings::get('captcha_provider'))->toBeEmpty()
        ->and(Settings::get('captcha_site_key'))->toBeEmpty()
        ->and(Settings::get('captcha_secret_key'))->toBeEmpty();
});
