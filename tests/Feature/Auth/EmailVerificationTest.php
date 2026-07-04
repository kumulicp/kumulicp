<?php

use App\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\Support\TestSupports;

function signedVerificationUrl(User $user): string
{
    return URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->emailForVerification())]
    );
}

it('verifies the email and logs the user in from a signed link with no prior session', function () {
    (new TestSupports)->seed();
    $user = User::factory()->create(['email_verified_at' => null]);

    Event::fake([Verified::class]);

    $this->assertGuest();

    $response = $this->get(signedVerificationUrl($user));

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class, fn ($event) => $event->user->is($user));
});

it('rejects a verification link whose hash does not match the users email', function () {
    (new TestSupports)->seed();
    $user = User::factory()->create(['email_verified_at' => null]);

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('not-the-real-email@example.com')]
    );

    $response = $this->get($url);

    $response->assertForbidden();
    $this->assertGuest();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('rejects an expired verification link', function () {
    (new TestSupports)->seed();
    $user = User::factory()->create(['email_verified_at' => null]);

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->subMinute(),
        ['id' => $user->id, 'hash' => sha1($user->emailForVerification())]
    );

    $response = $this->get($url);

    $response->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('does not re-fire the Verified event when an already-verified link is clicked again', function () {
    (new TestSupports)->seed();
    $user = User::factory()->create(['email_verified_at' => now()]);

    Event::fake([Verified::class]);

    $response = $this->get(signedVerificationUrl($user));

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    Event::assertNotDispatched(Verified::class);
});

it('logs in as the linked user even if a different user is already authenticated', function () {
    (new TestSupports)->seed();
    $user = User::factory()->create(['email_verified_at' => null]);
    $otherUser = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($otherUser);

    $response = $this->get(signedVerificationUrl($user));

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('still requires an authenticated session to view the verification notice or resend the email', function () {
    (new TestSupports)->seed();

    $this->get(route('verification.notice'))->assertRedirect(route('login'));
    $this->post(route('verification.resend'))->assertRedirect(route('login'));
});
