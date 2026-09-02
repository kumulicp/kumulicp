<?php

namespace App\Services;

use App\Integrations\Billing\Fake\FakeBillingGateway;
use App\Integrations\Billing\Stripe\StripeGateway;
use App\Organization;
use Illuminate\Support\Arr;

class BillingService
{
    private $organization;

    private $current_driver;

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
        return $this->driver()?->component() ?? '';
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

    public function __call($method, $args = null)
    {
        if ($method === 'isBillable' && ! $this->driver()) {
            return false;
        }

        return $this->driver()?->$method(...$args);
    }
}
