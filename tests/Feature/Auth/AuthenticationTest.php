<?php

use App\Providers\RouteServiceProvider;
use App\ServerSetting;
use Tests\Support\TestSupports;

it('renders login screen', function () {
    $setting = new ServerSetting;
    $setting->key = 'installed';
    $setting->value = 1;
    $setting->save();

    $response = $this->get('/login');

    $response->assertStatus(200);
});

it('authenticates users via login screen', function (string $driver) {
    setupAccountManagerDriver($driver);
    (new TestSupports)->seed();

    $response = $this->post('/login', [
        'email' => 'demo@example.com',
        'password' => 'demouser',
    ]);

    $response->assertRedirect(RouteServiceProvider::HOME);
    $this->assertAuthenticated();
})->with('account_manager_drivers');

it('rejects invalid password on login', function (string $driver) {
    setupAccountManagerDriver($driver);
    (new TestSupports)->seed();

    $this->post('/login', [
        'email' => 'demo@example.com',
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
})->with('account_manager_drivers');
