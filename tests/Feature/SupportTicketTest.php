<?php

use App\Mail\SupportTicket;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    (new TestSupports)->seed();
    $this->user = User::find(1);
    $this->actingAs($this->user);

    app('settings')->update('support_email', 'support@example.com');
});

it('queues the support ticket mailable on valid submission', function () {
    Mail::fake();

    $this->postJson('/support/ticket/submit', [
        'subject' => 'Test subject',
        'body' => '<p>Hello <b>world</b></p>',
        'request' => 'question',
    ])->assertOk();

    Mail::assertQueued(SupportTicket::class);
});

it('strips dangerous html from the ticket body', function () {
    Mail::fake();

    $this->postJson('/support/ticket/submit', [
        'subject' => 'XSS test',
        'body' => '<p>Hello</p><script>alert("xss")</script><img src="x" onerror="evil()">',
        'request' => 'bug',
    ])->assertOk();

    Mail::assertQueued(SupportTicket::class, function (SupportTicket $mail) {
        return ! str_contains($mail->body, '<script>')
            && ! str_contains($mail->body, 'onerror')
            && str_contains($mail->body, '<p>Hello</p>');
    });
});

it('rejects submissions with missing subject or body', function () {
    $this->postJson('/support/ticket/submit', [
        'subject' => '',
        'body' => '',
        'request' => 'question',
    ])->assertUnprocessable();
});

it('rejects an invalid request type', function () {
    $this->postJson('/support/ticket/submit', [
        'subject' => 'Test',
        'body' => '<p>Body</p>',
        'request' => 'invalid_type',
    ])->assertUnprocessable();
});

it('rejects unauthenticated ticket submissions', function () {
    auth()->logout();

    $this->postJson('/support/ticket/submit', [
        'subject' => 'Test',
        'body' => '<p>Body</p>',
        'request' => 'question',
    ])->assertUnauthorized();
});
