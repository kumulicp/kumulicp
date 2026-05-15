<?php

use App\Organization;
use App\Support\Facades\AccountManager;
use App\User;
use Tests\Support\TestSupports;

function validRegistrationPayload(array $overrides = []): array
{
    return array_merge([
        'username' => 'validuser',
        'contact_email' => 'valid@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'contact_first_name' => 'Valid',
        'contact_last_name' => 'User',
        'contact_phone_number' => '1234567890',
        'subdomain' => 'validuser',
        'name' => 'Valid Org',
        'description' => 'desc',
        'email' => 'org@example.com',
        'phone_number' => '1234567890',
        'street' => '123 St',
        'zipcode' => '12345',
        'city' => 'City',
        'state' => 'AL',
        'country' => 'US',
        'type' => 'nonprofit',
        'terms_of_use' => true,
    ], $overrides);
}

it('renders registration screen', function () {
    (new TestSupports)->seed();

    $this->withoutExceptionHandling();

    $response = $this->get('/register');

    $response->assertStatus(200);
});

it('registers new organization account', function () {
    (new TestSupports)->seed();

    $this->withoutExceptionHandling();

    try {
        $response = $this->post('/register', [
            'username' => 'test2',
            'contact_email' => 'test2@example.com',
            'password' => 'Test1password!',
            'password_confirmation' => 'Test1password!',
            'contact_first_name' => 'test1',
            'contact_last_name' => 'user',
            'contact_phone_number' => '1234567890',
            'subdomain' => 'testing',
            'name' => 'test1',
            'description' => 'test1',
            'email' => 'test1@example.com',
            'phone_number' => '1234567890',
            'street' => 'test st',
            'zipcode' => 'zipcode',
            'city' => 'test city',
            'state' => 'AL',
            'country' => 'US',
            'type' => 'nonprofit',
            'terms_of_use' => true,
        ]);
    } catch (\Throwable $e) {
        if ($organization = Organization::where('slug', 'testing')->first()) {
            AccountManager::account($organization)->destroy();
        }
        throw new \Exception($e->getMessage().$e->getTraceAsString());
    }

    $this->assertAuthenticated();

    $organization = Organization::where('slug', 'testing')->first();
    expect($organization)->toBeInstanceOf(Organization::class);
    $response->assertRedirect('/registered');
    AccountManager::account($organization)->destroy();
});

it('rejects username that is too short', function () {
    (new TestSupports)->seed();

    $response = $this->post('/register', validRegistrationPayload(['username' => 'usr']));

    $response->assertSessionHasErrors('username');
});

it('rejects mismatched passwords on registration', function () {
    (new TestSupports)->seed();

    $response = $this->post('/register', validRegistrationPayload(['password_confirmation' => 'DifferentPass456!']));

    $response->assertSessionHasErrors('password');
});

it('rejects duplicate username on registration', function () {
    (new TestSupports)->seed();
    User::factory()->create(['username' => 'takenuser']);

    $response = $this->post('/register', validRegistrationPayload(['username' => 'takenuser']));

    $response->assertSessionHasErrors('username');
});

it('rejects already registered email on registration', function () {
    (new TestSupports)->seed();
    $user = User::factory()->create();

    $response = $this->post('/register', validRegistrationPayload(['contact_email' => $user->email]));

    $response->assertSessionHasErrors('contact_email');
});

it('requires terms to be accepted on registration', function () {
    (new TestSupports)->seed();

    $response = $this->post('/register', validRegistrationPayload(['terms_of_use' => false]));

    $response->assertSessionHasErrors('terms_of_use');
});
