<?php

use App\SsoProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Support\TestSupports;

beforeEach(function () {
    setupAccountManagerDriver('db');
    (new TestSupports)->seed();
});

it('rejects SSO callback for eloquent driver when no matching user exists', function () {
    $provider = SsoProvider::create([
        'name' => 'fake-provider',
        'label' => 'Fake Provider',
        'driver' => 'fake-provider',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_url' => 'https://example.test/callback',
        'enabled' => true,
    ]);

    $socialUser = SocialiteUser::fake(['email' => 'no-such-user@example.com']);

    $driverMock = Mockery::mock(AbstractProvider::class);
    $driverMock->shouldReceive('user')->andReturn($socialUser);

    Socialite::shouldReceive('driver')->with($provider->driver)->andReturn($driverMock);

    $response = $this->get("/auth/{$provider->name}/callback");

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});
