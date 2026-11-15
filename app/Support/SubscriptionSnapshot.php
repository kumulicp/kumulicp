<?php

namespace App\Support;

use App\Services\Organization\BasePlanService;
use App\Services\SubscriptionService;

/**
 * A point-in-time summary of the parts of an organization's subscription that
 * the SubscriptionUpdate action cares about, so it can tell what actually
 * changed since the last time it ran instead of reacting to every call.
 *
 * - plans:   which plan is selected for the organization and each app instance
 *            (with names, so a change can be described to the admins)
 * - billing: hash of everything that affects what the customer is charged
 * - access:  hash of everything that affects user settings (storage, groups)
 */
class SubscriptionSnapshot
{
    /**
     * @param  array<string, array{id: int|null, name: string|null, label: string|null}>  $plans
     */
    public function __construct(
        public array $plans,
        public string $billing,
        public string $access,
    ) {}

    public static function capture(SubscriptionService $subscription): self
    {
        $organization = $subscription->organization;

        $plans = [];
        $billing = [
            'discount_id' => $organization->discount_id,
            'items' => [],
        ];
        $access = [];

        foreach ($subscription->get() as $key => $item) {
            $is_base = $item instanceof BasePlanService;
            $plan = $is_base ? $item->get() : $item->plan;
            $model = $item->model();

            $plans[$key] = [
                'id' => $plan?->id,
                'name' => $plan?->name,
                'label' => $is_base ? null : $model->label,
            ];

            $billing['items'][$key] = [
                'plan_id' => $plan?->id,
                'status' => $model->status,
                'trial_ends_at' => (string) data_get($model, 'trial_ends_at'),
                'pricing' => $plan ? $item->pricingOptions() : [],
            ];

            $access[$key] = [
                'plan_id' => $plan?->id,
                'status' => $model->status,
                'plan_settings' => $plan?->settings,
            ];
        }

        return new self($plans, self::hash($billing), self::hash($access));
    }

    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! isset($data['plans'], $data['billing'], $data['access'])) {
            return null;
        }

        return new self($data['plans'], $data['billing'], $data['access']);
    }

    public function toArray(): array
    {
        return [
            'plans' => $this->plans,
            'billing' => $this->billing,
            'access' => $this->access,
        ];
    }

    /**
     * Whether pricing, or anything else the billing provider has to be told
     * about (status, discount, trial, plan selection), differs from before.
     * With nothing to compare against it has to be assumed.
     */
    public function billingChanged(?self $previous): bool
    {
        return ! $previous || $previous->billing !== $this->billing;
    }

    /**
     * Whether the user settings that come from the plans (storage, access
     * types, which apps are active) differ from before.
     */
    public function accessChanged(?self $previous): bool
    {
        return ! $previous || $previous->access !== $this->access;
    }

    /**
     * The plans the organization or one of its apps moved to a different plan
     * on, oldest to newest. Apps that were added or removed aren't plan changes.
     *
     * @return array<int, array{type: string, app: string|null, from: string|null, to: string|null}>
     */
    public function planChanges(?self $previous): array
    {
        if (! $previous) {
            return [];
        }

        $changes = [];

        foreach ($this->plans as $key => $plan) {
            $before = $previous->plans[$key] ?? null;

            if (! $before || $before['id'] === $plan['id']) {
                continue;
            }

            $changes[] = [
                'type' => $key === 'base' ? 'base' : 'app',
                'app' => $plan['label'],
                'from' => $before['name'],
                'to' => $plan['name'],
            ];
        }

        return $changes;
    }

    private static function hash(array $data): string
    {
        return md5(json_encode($data));
    }
}
