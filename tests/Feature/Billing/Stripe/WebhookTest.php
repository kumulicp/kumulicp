<?php

use App\Organization;
use Illuminate\Support\Str;

function signStripeSignatureHeader(array $payload, string $secret): string
{
    $body = json_encode($payload);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    return "t={$timestamp},v1={$signature}";
}

beforeEach(function () {
    config(['cashier.webhook.secret' => 'whsec_test_secret']);
});

it('rejects an invalid signature', function () {
    $organization = Organization::factory()->create(['stripe_id' => 'cus_'.Str::random(14)]);

    $payload = [
        'id' => 'evt_'.Str::random(10),
        'type' => 'invoice.payment_failed',
        'data' => [
            'object' => [
                'id' => 'in_'.Str::random(10),
                'customer' => $organization->stripe_id,
            ],
        ],
    ];

    $response = $this->postJson('/api/stripe/webhook', $payload, [
        'Stripe-Signature' => 't='.time().',v1=not-a-real-signature',
    ]);

    $response->assertStatus(403);
});

it('marks organization past_due on invoice.payment_failed', function () {
    $organization = Organization::factory()->create(['stripe_id' => 'cus_'.Str::random(14)]);
    $organization->status = 'active';
    $organization->save();

    $payload = [
        'id' => 'evt_'.Str::random(10),
        'type' => 'invoice.payment_failed',
        'data' => [
            'object' => [
                'id' => 'in_'.Str::random(10),
                'customer' => $organization->stripe_id,
                'status' => 'open',
            ],
        ],
    ];

    $response = $this->postJson('/api/stripe/webhook', $payload, [
        'Stripe-Signature' => signStripeSignatureHeader($payload, 'whsec_test_secret'),
    ]);

    $response->assertStatus(200);
    expect($organization->refresh()->status)->toBe('past_due');
});

it('deactivates organization on customer.subscription.deleted', function () {
    $organization = Organization::factory()->create(['stripe_id' => 'cus_'.Str::random(14)]);
    $organization->status = 'active';
    $organization->save();

    $payload = [
        'id' => 'evt_'.Str::random(10),
        'type' => 'customer.subscription.deleted',
        'data' => [
            'object' => [
                'id' => 'sub_'.Str::random(10),
                'customer' => $organization->stripe_id,
                'status' => 'canceled',
            ],
        ],
    ];

    $response = $this->postJson('/api/stripe/webhook', $payload, [
        'Stripe-Signature' => signStripeSignatureHeader($payload, 'whsec_test_secret'),
    ]);

    $response->assertStatus(200);
    expect($organization->refresh()->status)->toBe('deactivated');
});
