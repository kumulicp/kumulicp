<?php

namespace App\Support\Billing;

/**
 * One billable item of a subscription, independent of any billing engine:
 * the engine's own id for the price, and how many of it to bill.
 */
final class SubscriptionLine
{
    public function __construct(
        /** The billing driver's id for the price (a Stripe price id, a Lago plan code, ...) */
        public readonly string $externalId,
        public readonly int $quantity,
        /** What is being counted: base, standard, basic, storage, application or email */
        public readonly string $component,
        public readonly int|float|string|null $amount = null,
    ) {}

    public function withQuantity(int $quantity): self
    {
        return new self($this->externalId, $quantity, $this->component, $this->amount);
    }
}
