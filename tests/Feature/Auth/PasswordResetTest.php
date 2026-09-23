<?php

use Tests\Support\TestSupports;

it('requires email to request password reset', function () {
    (new TestSupports)->seed();

    $response = $this->post('/password/reset', ['email' => '']);

    $response->assertSessionHasErrors('email');
});

it('returns the same response whether or not the email exists', function () {
    (new TestSupports)->seed();

    $existingEmailResponse = $this->post('/password/email', ['email' => 'demo@example.com']);
    $existingEmailResponse->assertSessionHasNoErrors();
    $existingEmailStatus = session('status');

    $missingEmailResponse = $this->post('/password/email', ['email' => 'no-such-user@example.com']);
    $missingEmailResponse->assertSessionHasNoErrors();
    $missingEmailStatus = session('status');

    expect($existingEmailResponse->status())->toBe($missingEmailResponse->status());
    expect($existingEmailStatus)->not->toBeEmpty();
    expect($existingEmailStatus)->toBe($missingEmailStatus);
});
