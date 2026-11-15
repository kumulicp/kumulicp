<?php

use App\Notifications\SubscriptionUpdatedNotification;
use App\Support\SubscriptionSnapshot;

function snapshotWith(array $plans = [], string $billing = 'b1', string $access = 'a1'): SubscriptionSnapshot
{
    return new SubscriptionSnapshot($plans + [
        'base' => ['id' => 1, 'name' => 'Basic', 'label' => null],
        '7' => ['id' => 3, 'name' => 'Cloud S', 'label' => 'Nextcloud'],
    ], $billing, $access);
}

it('reports nothing changed when the snapshot matches the previous one', function () {
    $snapshot = snapshotWith();
    $previous = SubscriptionSnapshot::fromArray($snapshot->toArray());

    expect($snapshot->billingChanged($previous))->toBeFalse()
        ->and($snapshot->accessChanged($previous))->toBeFalse()
        ->and($snapshot->planChanges($previous))->toBe([]);
});

it('assumes billing and access changed when there is no previous snapshot', function () {
    $snapshot = snapshotWith();

    expect($snapshot->billingChanged(null))->toBeTrue()
        ->and($snapshot->accessChanged(null))->toBeTrue()
        ->and($snapshot->planChanges(null))->toBe([]);
});

it('ignores a stored snapshot it cannot read', function () {
    expect(SubscriptionSnapshot::fromArray(null))->toBeNull()
        ->and(SubscriptionSnapshot::fromArray('garbage'))->toBeNull()
        ->and(SubscriptionSnapshot::fromArray(['plans' => []]))->toBeNull();
});

it('tracks billing and access changes independently', function () {
    $previous = snapshotWith();

    // e.g. a user was added to a priced plan: charged differently, nothing to change for users
    $more_users = snapshotWith(billing: 'b2');
    expect($more_users->billingChanged($previous))->toBeTrue()
        ->and($more_users->accessChanged($previous))->toBeFalse()
        ->and($more_users->planChanges($previous))->toBe([]);

    // e.g. a payment-neutral change to a plan's storage settings
    $more_storage = snapshotWith(access: 'a2');
    expect($more_storage->billingChanged($previous))->toBeFalse()
        ->and($more_storage->accessChanged($previous))->toBeTrue();
});

it('describes each plan that changed, but not apps that were added or removed', function () {
    $previous = snapshotWith();

    $current = new SubscriptionSnapshot([
        'base' => ['id' => 2, 'name' => 'Pro', 'label' => null],
        '7' => ['id' => 4, 'name' => 'Cloud M', 'label' => 'Nextcloud'],
        '9' => ['id' => 5, 'name' => 'Blog', 'label' => 'WordPress'],
    ], 'b2', 'a2');

    expect($current->planChanges($previous))->toBe([
        ['type' => 'base', 'app' => null, 'from' => 'Basic', 'to' => 'Pro'],
        ['type' => 'app', 'app' => 'Nextcloud', 'from' => 'Cloud S', 'to' => 'Cloud M'],
    ]);
});

it('words the notification from what actually changed', function () {
    $notification = new SubscriptionUpdatedNotification([
        ['type' => 'base', 'app' => null, 'from' => 'Basic', 'to' => 'Pro'],
        ['type' => 'app', 'app' => 'Nextcloud', 'from' => null, 'to' => 'Cloud M'],
    ]);

    expect($notification->messages())->toBe([
        "Your organization's plan was changed from Basic to Pro.",
        'The plan for Nextcloud was changed from no plan to Cloud M.',
    ]);

    expect($notification->toPanel(null)['message'])
        ->toBe("Your organization's plan was changed from Basic to Pro. The plan for Nextcloud was changed from no plan to Cloud M.");
});
