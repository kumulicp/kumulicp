<?php

namespace App\Services\AppInstance;

use App\Actions\Organizations\UpdateSubscriptionSettings;
use App\AppInstance;
use App\AppPlan;
use App\Enums\AccessType;
use App\Enums\PlanEntity;
use App\Support\AccountManager\UserManager;
use App\Support\Facades\Action;
use App\Support\Facades\Application;
use App\Support\Facades\FastCache;
use App\Support\Facades\Subscription;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AppInstancePlanService
{
    public $pricing_options = [PlanEntity::BASE, PlanEntity::ADDITIONAL_STORAGE, PlanEntity::STANDARD_USER, PlanEntity::BASIC_USER, PlanEntity::FEATURE];

    private $organization;

    private $application;

    private $cache = [];

    public function __construct(public AppPlan $plan, public AppInstance $app_instance)
    {
        $this->organization = $app_instance->organization;
    }

    public function model()
    {
        return $this->app_instance;
    }

    public function application()
    {
        if (! $this->application) {
            $this->application = $this->app_instance->application;
        }

        return $this->application;
    }

    private function app_instance()
    {
        return Application::instance($this->app_instance);
    }

    public function price($entity)
    {
        return $this->plan->setting("$entity.price");
    }

    public function itemName(): string
    {
        return $this->app_instance->application->slug;
    }

    public function itemLabel(): string
    {
        return $this->app_instance->label;
    }

    public function pricing(): array
    {
        $pricing = [];

        if ($this->plan->setting('base.price_id')) {
            $pricing[$this->plan->setting('base.price_id')]['quantity'] = 1;
        }

        foreach ($this->pricingOptions() as $name => $option) {
            if ($name != 'features') {
                if ($this->plan->setting("$name.price_id") && $this->countEntity($name) >= 0) {
                    $price_id = $this->plan->setting("$name.price_id");
                    $pricing[$price_id]['quantity'] = $this->countEntity($name);
                }
            }
        }
        foreach ($this->featureOptions() as $option) {
            if (Arr::get($option, 'status') === 'optional' && Arr::get($option, 'price_id')) {
                $pricing[$option['price_id']]['quantity'] = 1;
            }
        }

        return $pricing;
    }

    public function pricingOptions()
    {
        $pricing = [];
        $options = [];

        $entities = $this->pricing_options;

        foreach ($entities as $entity) {
            $price_id = "{$entity->value}.price_id";
            $price = "{$entity->value}.price";

            if ($this->plan->setting("{$entity->value}.price_id")) {
                $option = [
                    'name' => $entity->value,
                    'price_id' => $this->plan->setting("{$entity->value}.price_id"),
                    'price' => $this->plan->setting("{$entity->value}.price"),
                    'quantity' => $this->countEntity($entity->value),
                ];

                $options[$entity->value] = $option;
            }
        }

        if ($this->featureOptions()) {
            $options['features'] = $this->featureOptions();
        }

        return $options;
    }

    public function status(): string
    {
        $plan_id = $this->plan->id;

        if (in_array($this->app_instance->status, ['deactivating', 'deactivated'])) {
            return $this->app_instance->status;
        } elseif ($this->plan->archive) {
            return 'retired';
        } elseif ($plan_id == $this->app_instance->plan_id) {
            return 'active';
        }

        return 'unsubscribed';
    }

    public function stats(): array
    {
        $app_plan = $this;

        return FastCache::retrieve('app_plan_stats_'.$this->plan->id, function () use ($app_plan) {
            $stats = [];
            foreach ($app_plan->pricing_options as $option) {
                $option_stats = $app_plan->optionStats($option);
                if ($option_stats['price'] > 0) {
                    $stats[$option->value] = $option_stats;
                }
            }

            return $stats;
        });
    }

    public function optionStats(PlanEntity $type): array
    {
        $app_instance = $this->app_instance;
        $count = null;

        switch ($type->value) {
            case 'base':
                $count['label'] = __('labels.base');
                $count['quantity'] = $this->price('base') ? 1 : 0;
                $count['price'] = $this->price('base');
                $count['total_price'] = (float) number_format($this->price('base'), 2, '.', ', ');
                break;
            case 'standard':
                $count['label'] = __('labels.standard_users');
                $count['calculation'] = '$'.$this->price('standard').' per user';
                $count['quantity'] = $this->countEntity('standard');
                $count['price'] = $this->price('standard');
                $count['total_price'] = (float) number_format($this->standardPriceTotal(), 2, '.', ', ');
                $count['unit'] = 'user';
                break;
            case 'basic':
                $count['label'] = Str::plural($this->plan->setting('basic.name'));
                $count['calculation'] = '$'.$this->price('basic').' for every '.$this->plan->setting('basic.amount').' '.Str::plural(strtolower($this->plan->setting('basic.name')));
                $count['quantity'] = $this->countEntity('basic');
                $count['price'] = (float) $this->price('basic');
                $count['total_price'] = (float) number_format($this->basicPriceTotal(), 2, '.', ', ');
                $count['unit'] = $this->plan->setting('basic.amount').' '.Str::plural(strtolower($this->plan->setting('basic.name')));
                break;
            case 'storage':
                $count['label'] = __('labels.additional_storage');
                $count['calculation'] = '$'.$this->plan->setting('storage.price').' for every '.$this->plan->setting('storage.amount').'GB';
                $count['storage'] = $this->additionalStorageTotal();
                $count['quantity'] = $this->countEntity('storage');
                $count['price'] = $this->plan->setting('storage.price');
                $count['amount'] = $this->plan->setting('storage.amount');
                $count['total_price'] = number_format($this->additionalStoragePriceTotal(), 2, '.', ', ');
                $count['unit'] = $this->plan->setting('storage.amount').'GB';
                break;
            case 'feature':
                $features = [];
                foreach ($this->featureOptions() as $feature) {
                    $features[] = Str::ucfirst($feature['name']);
                }

                $count['label'] = __('labels.features');
                $count['calculation'] = implode(', ', $features);
                $count['quantity'] = '';
                $count['price'] = $this->featurePriceTotal();
                $count['total_price'] = number_format($this->featurePriceTotal(), 2, '.', ', ');
                $count['unit'] = '';
                break;
        }

        return $count;
    }

    public function accessTypeName(?AccessType $access_type = null): string
    {
        switch ($access_type) {
            case AccessType::STANDARD:
                return __('labels.standard_user');
            case AccessType::BASIC:
                return $this->setting('basic.name');
            case AccessType::MINIMAL:
                return $this->setting('base.minimal_label');
            case AccessType::NONE:
                return __('labels.none_user');
            default:
                return __('labels.unknown');
        }
    }

    public function refresh()
    {
        $this->app_instance->refresh();
        $this->app_instance->plan;
    }

    public function featureOptions()
    {
        $settings = $this->plan->settings;
        $pricing = [];

        $app_instance_service = Application::instance($this->app_instance);

        foreach (Arr::get($settings, 'features', []) as $name => $feature) {
            if (Arr::get($feature, 'price_id') && $app_instance_service->features()->isActive($name)) {
                $pricing[$name] = [
                    'name' => $name,
                    'price_id' => $feature['price_id'],
                    'price' => $feature['price'],
                    'quantity' => 1,
                    'status' => $feature['status'],
                ];
            }
        }

        return $pricing;
    }

    public function featureNames()
    {
        $features = [];

        foreach ($this->featureOptions() as $feature) {
            $features[] = $feature['name'];
        }

        return $features;
    }

    public function availableAccessTypes(): array
    {
        $app_roles = $this->app_instance->application->roles;
        $access_types = [
            [
                'value' => 'standard',
                'text' => 'Active User',
                'description' => $app_roles->filter(function ($role) {
                    return $role->slug === 'standard';
                })->first()?->description,
            ],
        ];

        if ($this->setting('basic.name')) {
            $access_types[] = [
                'value' => 'basic',
                'text' => $this->setting('basic.name'),
                'description' => $app_roles->filter(function ($role) {
                    return $role->slug === 'basic';
                })->first()?->description,
            ];
        }

        if (Subscription::base()->setting('base.minimal_label')) {
            $access_types[] = [
                'value' => 'minimal',
                'text' => Subscription::base()->setting('base.minimal_label'),
            ];
        }

        $access_types[] = [
            'value' => 'none',
            'text' => 'Disabled',
        ];

        return $access_types;
    }

    public function availableAccessTypesForUser(UserManager $user)
    {
        $access_types = $this->availableAccessTypes();
        $filtered_access_types = [];
        foreach ($access_types as $type) {
            switch ($type['value']) {
                case 'standard':
                    $type['disabled'] = ! Gate::allows('update-app-standard-user', [$user, $this->app_instance]);
                    break;
                case 'basic':
                    $type['disabled'] = ! Gate::allows('update-app-basic-user', [$user, $this->app_instance]);
                    break;
                default:
                    $type['disabled'] = false;
            }
            $filtered_access_types[] = $type;
        }

        return $filtered_access_types;
    }

    public function availableAccessTypesList(): array
    {
        $access_types = [];
        foreach ($this->availableAccessTypes() as $access_type) {
            $access_types[] = $access_type['value'];
        }

        return $access_types;
    }

    public function allAvailable()
    {
        $availabe_plans = Application::availablePlans($this->application(), $this->organization);
        $plans = [];

        foreach ($availabe_plans as $plan) {
            $app_instance_plan = new self($plan, $this->app_instance);
            if (! $app_instance_plan->isAnyMaxBroken() || $plan->id == $this->id) {
                $plans[] = $app_instance_plan;
            }
        }

        return collect($plans);
    }

    public function customizations()
    {
        return Application::instance($this->app_instance)->features()->all();
    }

    public function hasCustomizations()
    {
        $customizations = $this->customizations();

        return count($customizations) > 0;
    }

    public function countEntity($entity)
    {
        if ($entity == 'base') {
            return 1;
        }

        $method = 'count'.ucfirst($entity);

        return $this->app_instance()->$method();
    }

    public function setting($setting)
    {
        return $this->plan->setting($setting);
    }

    public function domainsEnabled(): bool
    {
        return $this->plan->domain_enabled;
    }

    public function emailEnabled(): bool
    {
        return $this->plan->email_enabled;
    }

    public function additionalStorageEnabled()
    {
        $storage_amount = $this->plan->setting('storage.amount');

        return $storage_amount && $storage_amount > 0;
    }

    public function isBasicUsersEnabled()
    {
        return ! is_null($this->plan->setting('basic.name'));
    }

    public function isAnyMaxBroken()
    {
        foreach ($this->pricing_options as $option) {
            if ($this->isMaxBroken($option)) {
                return true;
            }
        }

        return false;
    }

    public function totalPrice(): float
    {
        return $this->plan->setting('base.price')
            + $this->standardPriceTotal()
            + $this->basicPriceTotal()
            + $this->additionalStoragePriceTotal()
            + $this->featurePriceTotal();
    }

    public function isDomainMax(): bool
    {
        return (! $this->plan->domain_max || $this->app_instance->main_domains()->count() < $this->plan->domain_max) ? false : true;
    }

    public function isMaxBroken(PlanEntity $entity): bool
    {
        return $this->plan->setting("{$entity->value}.max") && $this->countEntity($entity->value) > (int) $this->plan->setting("{$entity->value}.max");
    }

    public function isMax($entity): bool
    {
        $max_amount = $this->plan->setting("$entity.max");

        return ($max_amount && $this->countEntity($entity) >= (int) $max_amount) || (! $max_amount && Subscription::base()->isMax($entity));
    }

    public function standardPriceTotal(): float
    {
        return $this->plan->setting('standard.price') ? $this->countEntity('standard') * $this->plan->setting('standard.price') : 0;
    }

    public function standardStorageTotal(): float
    {
        return ($this->plan->setting('standard.storage')) ? $this->countEntity('standard') * $this->plan->setting('standard.storage') : 0;
    }

    public function basicPriceTotal(): float
    {
        return ($this->countEntity('basic') && $this->plan->setting('basic.price')) ? ceil($this->countEntity('basic') / $this->plan->setting('basic.amount')) * $this->plan->setting('basic.price') : 0;
    }

    public function basicAmountTotal(): float
    {
        return ($this->plan->setting('basic.amount')) ? $this->countEntity('basic') * $this->plan->setting('basic.amount') : 0;
    }

    public function additionalStoragePriceTotal()
    {
        return $this->plan->setting('storage.price') ? $this->countEntity('storage') * $this->plan->setting('storage.price') : 0;
    }

    public function additionalStorageTotal()
    {
        return $this->plan->setting('storage.amount') ? $this->countEntity('storage') * $this->plan->setting('storage.amount') : 0;
    }

    public function featurePriceTotal()
    {
        $price = 0;
        foreach ($this->featureOptions() as $feature) {
            $price += $feature['price'];
        }

        return $price;
    }

    public function availableFeatures()
    {
        $features = [];

        foreach (Arr::get($this->plan->settings, 'features', []) as $feature) {
            if (in_array($feature['status'], ['enabled', 'optional'])) {
                $features[] = Application::profile($this->application()->slug)->feature($feature['name']);
            }
        }

        return $features;
    }

    public function availableFeatureNames()
    {
        $features = [];

        foreach ($this->availableFeatures() as $feature) {
            if ($feature) {
                $features[] = $feature->label;
            }
        }

        return $features;
    }

    public function featureSettings($name)
    {
        return Arr::get($this->plan->settings, "features.$name");
    }

    public function updateFeatures(array $features)
    {
        $plan_changed = false;

        foreach ($features as $name => $feature) {
            if (is_string($feature)) {
                $status = $feature == 'on' ? 'enabled' : 'disabled';
            } else {
                $status = $feature['status'];
            }

            $plan_settings[$name] = [
                'name' => $name,
                'status' => $status,
                'price' => Arr::get($feature, 'price'),
                'price_id' => Arr::get($feature, 'price_id'),
                'settings' => Arr::get($feature, 'settings', []),
            ];

            if ($plan_settings[$name] != $this->featureSettings($name)) {
                $plan_changed = true;
            }
        }

        $this->plan->updateSetting('features', $plan_settings);

        if ($plan_changed) {
            Action::execute(new UpdateSubscriptionSettings($this->plan));
        }

        return $plan_settings;
    }

    public function save(): void
    {
        $this->plan->save();
    }

    public function __get($property)
    {
        return $this->plan->$property;
    }

    public function __call($method, $args)
    {
        return $this->plan->$method(...$args);
    }
}
