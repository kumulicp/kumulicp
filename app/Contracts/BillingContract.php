<?php

namespace App\Contracts;

use App\Contracts\Billing\PaymentMethodGateway;
use App\Contracts\Billing\SubscriptionGateway;

/**
 * A billing driver that does both: syncs subscriptions and takes payment
 * methods in the app (Stripe, the fake test driver). A driver for an engine
 * with hosted payment pages can implement SubscriptionGateway alone.
 */
interface BillingContract extends PaymentMethodGateway, SubscriptionGateway {}
