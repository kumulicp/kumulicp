<?php

use App\User;
use Tests\Support\TestSupports;

beforeEach(function () {
    (new TestSupports)->seed();
    $this->user = User::where('username', 'demo')->firstOrFail();
});

it('redirects unauthenticated users from profile page', function () {
    $response = $this->get('/profile');
    $response->assertRedirect('/login');
});

it('redirects unauthenticated post to profile', function () {
    $response = $this->post('/profile', [
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'personal_email' => 'jane@example.com',
    ]);
    $response->assertRedirect('/login');
});

it('requires first name on profile update', function () {
    $response = $this->actingAs($this->user)->post('/profile', [
        'first_name' => '',
        'last_name' => 'User',
        'personal_email' => 'demo@example.com',
    ]);
    $response->assertSessionHasErrors('first_name');
});

it('requires last name on profile update', function () {
    $response = $this->actingAs($this->user)->post('/profile', [
        'first_name' => 'Demo',
        'last_name' => '',
        'personal_email' => 'demo@example.com',
    ]);
    $response->assertSessionHasErrors('last_name');
});

it('requires valid email on profile update', function () {
    $response = $this->actingAs($this->user)->post('/profile', [
        'first_name' => 'Demo',
        'last_name' => 'User',
        'personal_email' => 'not-an-email',
    ]);
    $response->assertSessionHasErrors('personal_email');
});

it('requires personal email on profile update', function () {
    $response = $this->actingAs($this->user)->post('/profile', [
        'first_name' => 'Demo',
        'last_name' => 'User',
        'personal_email' => '',
    ]);
    $response->assertSessionHasErrors('personal_email');
});

it('first name cannot exceed 100 characters', function () {
    $response = $this->actingAs($this->user)->post('/profile', [
        'first_name' => str_repeat('a', 101),
        'last_name' => 'User',
        'personal_email' => 'demo@example.com',
    ]);
    $response->assertSessionHasErrors('first_name');
});

it('last name cannot exceed 100 characters', function () {
    $response = $this->actingAs($this->user)->post('/profile', [
        'first_name' => 'Demo',
        'last_name' => str_repeat('a', 101),
        'personal_email' => 'demo@example.com',
    ]);
    $response->assertSessionHasErrors('last_name');
});

it('password update requires current password', function () {
    $response = $this->actingAs($this->user)->post('/profile/update/passwd', [
        'current_password' => '',
        'password' => 'NewPass123!',
        'password_confirmation' => 'NewPass123!',
    ]);
    $response->assertSessionHasErrors('current_password');
});

it('password update rejects wrong current password', function () {
    $response = $this->actingAs($this->user)->post('/profile/update/passwd', [
        'current_password' => 'wrongpassword',
        'password' => 'NewPass123!',
        'password_confirmation' => 'NewPass123!',
    ]);
    $response->assertSessionHasErrors('current_password');
});

it('password update requires matching confirmation', function () {
    $response = $this->actingAs($this->user)->post('/profile/update/passwd', [
        'current_password' => 'demouser',
        'password' => 'NewPass123!',
        'password_confirmation' => 'DifferentPass456!',
    ]);
    $response->assertSessionHasErrors('password');
});
