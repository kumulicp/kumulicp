<?php

use App\Services\Billing\SubscriptionRequestBuilder;

/**
 * A stand-in for SubscriptionService / JointSubscriptionService: it only has to
 * list paid plans, each of which lists its pricing options.
 */
function subscriptionOf(array ...$plans): object
{
    return new class($plans)
    {
        public function __construct(private array $plans) {}

        public function paidSubscriptions(): array
        {
            return array_map(fn (array $options) => new class($options)
            {
                public function __construct(private array $options) {}

                public function pricingOptions(): array
                {
                    return $this->options;
                }
            }, $this->plans);
        }
    };
}

it('bills the optional features of an app, not just its own entities', function () {
    $lines = (new SubscriptionRequestBuilder)->linesFor(subscriptionOf([
        'base' => ['name' => 'base', 'price_id' => 'app_base', 'price' => 5, 'quantity' => 1],
        'standard' => ['name' => 'standard', 'price_id' => 'app_user', 'price' => 2, 'quantity' => 3],
        'features' => [
            'deck' => ['name' => 'deck', 'price_id' => 'feat_deck', 'price' => 1, 'quantity' => 1, 'status' => 'optional'],
            'spreed' => ['name' => 'spreed', 'price_id' => 'feat_spreed', 'price' => 4, 'quantity' => 1, 'status' => 'optional'],
        ],
    ]));

    expect($lines->pluck('externalId')->all())->toBe(['app_base', 'app_user', 'feat_deck', 'feat_spreed']);

    $deck = $lines->firstWhere('externalId', 'feat_deck');
    expect($deck->component)->toBe('feature.deck');
    expect($deck->quantity)->toBe(1);
    expect($deck->amount)->toBe(1);
});

it('adds up the same price used by several plans', function () {
    $lines = (new SubscriptionRequestBuilder)->linesFor(subscriptionOf(
        ['standard' => ['price_id' => 'user', 'quantity' => 2], 'features' => ['deck' => ['price_id' => 'feat_deck', 'quantity' => 1]]],
        ['standard' => ['price_id' => 'user', 'quantity' => 3], 'features' => ['deck' => ['price_id' => 'feat_deck', 'quantity' => 1]]],
    ));

    expect($lines)->toHaveCount(2);
    expect($lines->firstWhere('externalId', 'user')->quantity)->toBe(5);
    expect($lines->firstWhere('externalId', 'feat_deck')->quantity)->toBe(2);
});

it('skips features and entities with no price id or nothing to bill', function () {
    $lines = (new SubscriptionRequestBuilder)->linesFor(subscriptionOf([
        'base' => ['price_id' => null, 'quantity' => 1],
        'storage' => ['price_id' => 'storage', 'quantity' => 0],
        'features' => [
            'unpriced' => ['price_id' => null, 'quantity' => 1],
            'priced' => ['price_id' => 'feat_priced', 'quantity' => 1],
        ],
    ]));

    expect($lines->pluck('externalId')->all())->toBe(['feat_priced']);
});

it('copes with a plan that has no features', function () {
    $lines = (new SubscriptionRequestBuilder)->linesFor(subscriptionOf(['base' => ['price_id' => 'b', 'quantity' => 1], 'features' => []]));

    expect($lines->pluck('externalId')->all())->toBe(['b']);
});
