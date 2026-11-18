<?php

namespace App\Services;

use App\Contracts\Billing\PaymentMethodGateway;
use App\Contracts\Billing\SubscriptionGateway;
use App\Integrations\Billing\Fake\FakeBillingGateway;
use App\Integrations\Billing\Stripe\StripeGateway;
use App\Notifications\NewOrganizationSubscription;
use App\Notifications\OrganizationCancelledSubscription;
use App\Organization;
use App\Services\Billing\SubscriptionRequestBuilder;
use App\Support\Facades\Organization as OrganizationFacade;
use Illuminate\Support\Arr;

class BillingService
{
    private $organization;

    private $current_driver;

    /**
     * What the app does when the driver does not take payment methods in the
     * app (it does not implement PaymentMethodGateway): there is nothing to
     * collect, so a payment method is never missing.
     */
    private const NO_PAYMENT_METHOD_SUPPORT = [
        'intent' => [],
        'hasDefaultPaymentMethod' => true,
        'updateDefaultPaymentMethod' => null,
        'deleteDefaultPaymentMethod' => null,
        'defaultPaymentMethodBrand' => '',
        'defaultPaymentMethod' => [],
        'defaultPaymentMethodBrandImage' => null,
    ];

    private array $drivers = [
        'stripe' => StripeGateway::class,
        'fake' => FakeBillingGateway::class,
    ];

    public function __construct(?Organization $organization = null)
    {
        $this->organization = $organization;

        // Picks up drivers modules registered via their (one-time, boot-time)
        // service providers -- see BillingDriverRegistry for why this can't
        // just rely on those boot() calls registering directly on this
        // instance.
        foreach (app(BillingDriverRegistry::class)->all() as $driver => $class) {
            $this->drivers[$driver] = $class;
        }
    }

    private function driver()
    {
        $name = config('billing.default');
        if (Arr::has($this->drivers, $name)) {
            if (! $this->current_driver !== $this->drivers[$name]) {
                $this->current_driver = new $this->drivers[$name]($this->organization);
            }

            return $this->current_driver;
        }

        if (! empty($name)) {
            throw new \Exception(__('messages.exception.no_billing_driver'));
        }
    }

    public function component(): string
    {
        $driver = $this->driver();

        return $driver instanceof PaymentMethodGateway ? $driver->component() : '';
    }

    /**
     * Whether the driver has the customer save a payment method in the app.
     */
    public function supportsPaymentMethods(): bool
    {
        return $this->driver() instanceof PaymentMethodGateway;
    }

    /**
     * Bring the organization's subscription in line with its plans.
     *
     * The same for every driver: work out the billable lines once, then hand
     * them to the driver to apply.
     */
    public function update(): void
    {
        $driver = $this->driver();
        if (! $driver instanceof SubscriptionGateway) {
            return;
        }

        $request = app(SubscriptionRequestBuilder::class)
            ->build($this->organization ?? OrganizationFacade::account());

        if ($request->organization->status === 'deactivated' || $request->lines->isEmpty()) {
            return;
        }

        $created = $driver->syncSubscription($request);

        if ($created && $request->firstSubscription) {
            Organization::where('type', 'superaccount')->first()?->notifyAdmins(new NewOrganizationSubscription);
        }
    }

    /**
     * Cancel the organization's subscription, and tell the superaccount admins
     * when there was one to cancel.
     */
    public function cancel(): void
    {
        $driver = $this->driver();
        if (! $driver instanceof SubscriptionGateway) {
            return;
        }

        if ($driver->cancel()) {
            Organization::where('type', 'superaccount')->first()?->notifyAdmins(
                new OrganizationCancelledSubscription($this->organization ?? OrganizationFacade::account())
            );
        }
    }

    public function register(string $driver, $class)
    {
        if (class_exists($class)) {
            $this->drivers[$driver] = $class;
            app(BillingDriverRegistry::class)->register($driver, $class);
        }
    }

    public function organization(Organization $organization)
    {
        return new self($organization);
    }

    /**
     * Whether the organization has ever had a billing subscription, active or not. History
     * stays worth showing after payments stop. Drivers without any history report false.
     */
    public function hasBillingHistory(): bool
    {
        $driver = $this->driver();

        return $driver && method_exists($driver, 'hasBillingHistory') && $driver->hasBillingHistory();
    }

    public function __call($method, $args = null)
    {
        $driver = $this->driver();

        if ($method === 'isBillable' && ! $driver) {
            return false;
        }

        if ($driver && ! $driver instanceof PaymentMethodGateway && array_key_exists($method, self::NO_PAYMENT_METHOD_SUPPORT)) {
            return self::NO_PAYMENT_METHOD_SUPPORT[$method];
        }

        return $driver?->$method(...$args);
    }
}
