# Billing drivers

A billing driver connects KumuliCP to the system that bills organizations
(Stripe today). The driver is chosen with `BILLING_DRIVER` and registered in
`BillingService`; modules can add their own with `Billing::register('name', Class::class)`.

## What a driver implements

| Interface | Required | Purpose |
|---|---|---|
| `App\Contracts\Billing\SubscriptionGateway` | yes | Keep a subscription in step with the organization's plans, and report on it (status, invoices, period end, discount). |
| `App\Contracts\Billing\PaymentMethodGateway` | no | Only for engines where the customer saves a card inside KumuliCP. |
| `App\Contracts\BillingContract` | no | Both of the above, for drivers that do both (Stripe, the fake test driver). |

A driver that only implements `SubscriptionGateway` (an engine with hosted
payment pages) is treated as having nothing to collect in the app:
`Billing::hasDefaultPaymentMethod()` is always `true`, `Billing::component()` is
empty, and the payment method screens have nothing to show.

## How a subscription is synced

A driver never reads plans or settings. `Billing::update()`:

1. asks `SubscriptionRequestBuilder` what the organization should be billed for
   (the parent organization when a child is invoiced with it),
2. skips deactivated organizations and requests with nothing to bill,
3. calls `syncSubscription(SubscriptionRequest $request)` on the driver.

The request holds the organization to bill, a list of `SubscriptionLine`s
(`externalId`, `quantity`, `component`, `amount`), the organization's
`discountId`, and `trialDays` / `firstSubscription` for a first subscription.
`syncSubscription` returns `true` when it created a new subscription, so the
superaccount admins can be told about it.

The trial length is `BILLING_TRIAL_DAYS` (default 30).

## Where prices live

Prices are rows in `plan_prices` (see `App\PlanPrice`, `App\Support\Billing\HasPlanPrices`):

- one row per plan, component and currency, with the display `amount`,
- `external_ids`: a map of driver to that driver's id for the price, e.g.
  `{"stripe": "price_123", "lago": "standard_cad"}`.

`$plan->priceFor('standard', 'CAD')` returns the `price_id` of the configured
driver, falling back to the default currency. A driver's `externalId` in a
`SubscriptionLine` is that value, so switching drivers only needs the new
driver's ids filled in; amounts are not entered twice.

Components are `base`, `standard`, `basic`, `storage`, `application`, `email`,
and `feature.{name}` for optional app features.
