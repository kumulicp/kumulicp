<?php

namespace App\Services\Organization;

use App\Application;
use App\AppPlan;
use App\Enums\AccessType;
use App\Enums\PlanEntity;
use App\Organization as OrganizationModel;
use App\Plan;
use App\Services\OrganizationService;
use App\Support\AccountManager\UserManager;
use App\Support\Facades\Application as AppFacade;
use App\Support\Facades\FastCache;
use App\Support\Facades\Organization;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class BasePlanService
{
    private $plan;

    private $org_service;

    public $pricing_options = [PlanEntity::BASE, PlanEntity::ADDITIONAL_STORAGE, PlanEntity::STANDARD_USER, PlanEntity::BASIC_USER, PlanEntity::EMAIL, PlanEntity::APPLICATION];

    public function __construct(public OrganizationModel $organization, ?Plan $plan = null)
    {
        $this->plan = $plan ?? $organization->plan;
        $this->org_service = Organization::account()?->is($organization) ? Organization::getThis() : new OrganizationService($organization);
    }

    public function itemName()
    {
        return 'Base';
    }

    public function itemLabel()
    {
        return 'Base Plan';
    }

    public function model()
    {
        return $this->organization;
    }

    public function get()
    {
        return $this->plan;
    }

    public function allAvailable()
    {
        $base = $this;

        return FastCache::retrieve('available_app_plans', function () use ($base) {
            $availabe_plans = Plan::where('archive', 0)->where('org_type', $base->organization->type)->orderBy('display_order')->get()->all();
            $plans = [];

            foreach ($availabe_plans as $plan) {
                $subscription = new self($this->organization, $plan);
                if (! $subscription->isAnyMaxBroken() || $plan->id == $base->id) {
                    $plans[] = $subscription;
                }
            }

            return collect($plans);
        });
    }

    public function stripePricing()
    {
        $pricing = [];

        if ($this->plan->setting('base.price_id')) {
            $pricing[$this->plan->setting('base.price_id')]['quantity'] = 1;
        }

        foreach ($this->pricingOptions() as $name => $option) {
            if ($this->plan->setting("$name.price_id") && $this->org_service->countEntity($name) >= 0) {
                $string_price_id = (string) $this->plan->setting("$name.price_id");
                $pricing[$string_price_id]['quantity'] = $this->org_service->countEntity($name);
            }
        }

        return $pricing;
    }

    public function enabledApps()
    {
        $apps = [];

        $apps = Application::orderBy('slug');
        $app_plans = $this->app_plans;
        $apps->where(function ($query) use ($app_plans) {
            if ($app_plans) {
                foreach ($app_plans as $app_name => $settings) {
                    if ($settings['plans'] != 'disabled') {
                        $query->orWhere('slug', $app_name);
                    }
                }
            }
        });

        $get_apps = $apps->where('enabled', 1)->get();

        return $get_apps;
    }

    public function appEnabled(Application $app)
    {
        if ($plan = $this->plan) {
            $app_plans = $this->app_plans;
            if (Arr::get($app_plans, $app->slug) != 'disabled') {
                return true;
            }
        }

        return false;
    }

    public function appPlans(Application $app, $archived = true, $display_order = false)
    {
        $app_plans = $this->appPlansList($app);
        $plans = AppPlan::whereIn('id', $app_plans);

        if ($archived === false) {
            $plans->where('archive', false);
        }

        if ($display_order) {
            $plans->orderBy('display_order');
        }

        return $plans->get();
    }

    public function appPlanEnabled(AppPlan $plan)
    {
        return in_array($plan->id, $this->appPlansList($plan->application));
    }

    public function pricingOptions()
    {
        $pricing = [];
        $options = [];

        $entities = $this->pricing_options;

        foreach ($entities as $entity) {

            if ($this->plan->setting("{$entity->value}.price_id")) {
                $option = [
                    'name' => $entity,
                    'price_id' => $this->plan->setting("{$entity->value}.price_id"),
                    'price' => $this->plan->setting("{$entity->value}.price"),
                    'quantity' => $this->org_service->countEntity($entity->value),
                ];

                $options[$entity->value] = $option;
            }
        }

        return $options;
    }

    public function status()
    {
        return $this->organization->status;
    }

    public function stats()
    {
        $base = $this;

        return FastCache::retrieve('base_plan_stats_'.$this->plan->id, function () use ($base) {
            $stats = [];
            foreach ($base->pricing_options as $option) {
                $option_stats = $base->optionStats($option->value);
                if ($option_stats['price'] > 0) {
                    $stats[$option->value] = $option_stats;
                }
            }

            return $stats;
        });
    }

    public function optionStats($type)
    {
        $stats = $type.'Stats';

        return $this->$stats();
    }

    public function baseStats()
    {
        $price = $this->price('base');

        return [
            'label' => __('labels.base'),
            'quantity' => $price ? 1 : 0,
            'price' => $price,
            'total_price' => $price ? number_format($price, 2, '.', ', ') : null,
        ];
    }

    public function standardStats()
    {
        return [
            'label' => __('labels.standard_users'),
            'calculation' => '$'.$this->price('standard').' per user',
            'quantity' => $this->org_service->countEntity('standard'),
            'price' => $this->price('standard'),
            'total_price' => number_format($this->standardPriceTotal(), 2, '.', ', '),
            'unit' => 'user',
        ];
    }

    public function basicStats()
    {
        return [
            'label' => $this->plan->setting('basic.name').' User Price',
            'calculation' => '$'.$this->price('basic').' for every '.$this->plan->setting('basic.amount').' '.Str::plural(strtolower($this->plan->setting('basic.name'))),
            'quantity' => $this->org_service->countEntity('basic'),
            'price' => $this->price('basic'),
            'total_price' => number_format($this->basicPriceTotal(), 2, '.', ', '),
            'unit' => $this->plan->setting('basic.amount').' '.Str::plural(Str::lower($this->plan->setting('basic.name')), (int) $this->plan->setting('basic.amount')),
        ];
    }

    public function storageStats()
    {
        return [
            'label' => __('labels.additional_storage'),
            'calculation' => '$'.$this->plan->setting('storage.price').' for every '.$this->plan->setting('storage.amount').'GB',
            'storage' => $this->additionalStorageTotal(),
            'quantity' => $this->org_service->countEntity('storage'),
            'price' => $this->price('storage'),
            'amount' => $this->plan->setting('storage.amount'),
            'total_price' => number_format($this->additionalStoragePriceTotal(), 2, '.', ', '),
            'unit' => $this->plan->setting('storage.amount').'GB',
        ];
    }

    public function emailStats()
    {
        return [
            'label' => __('labels.email'),
            'calculation' => '$'.$this->price('email').' per email',
            'quantity' => $this->org_service->countEntity('email'),
            'price' => $this->price('email'),
            'total_price' => number_format($this->emailPriceTotal(), 2, '.', ', '),
            'unit' => 'email accounts',
        ];
    }

    public function applicationStats()
    {
        return [
            'label' => __('labels.app'),
            'calculation' => $this->org_service->countEntity('application').' x $'.$this->price('application'),
            'quantity' => $this->org_service->countEntity('application'),
            'price' => $this->price('application'),
            'total_price' => number_format($this->applicationPriceTotal(), 2, '.', ', '),
            'unit' => 'application',
        ];
    }

    public function price($entity)
    {
        return $this->plan->setting("$entity.price");
    }

    public function refresh()
    {
        $this->organization->refresh();
        $this->plan = $this->organization->plan;
    }

    public function availableAccessTypes()
    {
        $access_types = [
            [
                'value' => 'standard',
                'text' => 'Active User',
                // 'disabled' => ! Gate::allows('update-standard-user', $user),
            ],
        ];

        if ($this->setting('basic.name')) {
            $access_types[] = [
                'value' => 'basic',
                'text' => $this->setting('basic.name'),
                // 'disabled' => ! Gate::allows('update-basic-user', $user),
            ];
        }

        if ($this->setting('base.minimal_label')) {
            $access_types[] = [
                'value' => 'minimal',
                'text' => $this->setting('base.minimal_label'),
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
                    $type['disabled'] = ! Gate::allows('update-standard-user', $user);
                    break;
                case 'basic':
                    $type['disabled'] = ! Gate::allows('update-basic-user', $user);
                    break;
                default:
                    $type['disabled'] = false;
            }
            $filtered_access_types[] = $type;
        }

        return $filtered_access_types;
    }

    public function availableAccessTypesList()
    {
        $access_types = [];
        foreach ($this->availableAccessTypes() as $access_type) {
            $access_types[] = $access_type['value'];
        }

        return $access_types;
    }

    public function domainsEnabled()
    {
        return $this->plan->domain_enabled;
    }

    public function emailEnabled()
    {
        return $this->plan->email_enabled == 1;
    }

    public function isAnyMaxBroken()
    {
        $plan = $this;

        return FastCache::retrieve('base_plan_maxed', function () use ($plan) {
            foreach ($plan->pricing_options as $entity) {
                if ($plan->isMaxBroken($entity) || $plan->isMaxAppsBroken()) {
                    return true;
                }
            }

            return false;
        });
    }

    public function isMaxApps(Application $app)
    {
        $app_instances = AppFacade::instances($this->organization, $app->slug);
        $max_name = "{$app->slug}.max";
        $max_apps = (int) Arr::get($this->app_plans, $max_name, 1);

        return $this->isMax('application') || count($app_instances) >= $max_apps;
    }

    public function totalPrice()
    {
        $base = $this;

        return FastCache::retrieve('base_plan_total_price', function () use ($base) {
            return $base->plan->setting('base.price')
            + $base->standardPriceTotal()
            + $base->basicPriceTotal()
            + $base->additionalStoragePriceTotal()
            + $base->applicationPriceTotal()
            + $base->emailPriceTotal();
        });
    }

    public function isMaxAppsBroken()
    {
        foreach (Application::all() as $app) {
            if (Arr::get($this->plan->app_plans, "{$app->slug}.max", null) < $this->org_service->countAppInstances($app)) {
                return true;
            }
        }

        return false;
    }

    public function isDomainMax()
    {
        return ! is_null($this->plan->domain_max) && $this->organization->main_domains()->where('status', '!=', 'pending_registration')->count() >= $this->plan->domain_max;
    }

    public function isMax($entity)
    {
        return $this->plan->setting("$entity.max") && $this->org_service->countEntity($entity) >= (int) $this->plan->setting("$entity.max");
    }

    public function isMaxBroken(PlanEntity $entity)
    {
        return (! $this->plan->setting("{$entity->value}.max") || $this->org_service->countEntity($entity->value) <= $this->plan->setting("{$entity->value}.max")) ? false : true;
    }

    public function standardPriceTotal()
    {
        return $this->plan->setting('standard.price') ? $this->org_service->countEntity('standard') * $this->plan->setting('standard.price') : 0;
    }

    public function standardStorageTotal()
    {
        return ($this->plan->setting('standard.price')) ? $this->org_service->countEntity('standard') * $this->plan->setting('standard.storage') : 0;
    }

    public function basicPriceTotal()
    {
        return $this->plan->setting('basic.price')
                ? ceil($this->org_service->countEntity('basic') / $this->plan->setting('basic.amount')) * $this->plan->setting('basic.price')
                : 0;
    }

    public function basicAmountTotal()
    {
        return ($this->plan->setting('basic.amount')) ? $this->org_service->countEntity('basic') * $this->plan->setting('basic.amount') : 0;
    }

    public function additionalStoragePriceTotal()
    {
        return $this->plan->setting('storage.price') ? $this->org_service->countEntity('storage') * $this->plan->setting('storage.price') : 0;
    }

    public function additionalStorageTotal()
    {
        return $this->plan->setting('storage.amount') ? $this->org_service->countEntity('storage') * $this->plan->setting('storage.amount') : 0;
    }

    public function applicationPriceTotal()
    {
        return $this->plan->setting('application.price') ? $this->org_service->countEntity('application') * $this->plan->setting('application.price') : 0;
    }

    public function emailPriceTotal()
    {
        return $this->plan->setting('email.price') ? $this->org_service->countEntity('email') * $this->plan->setting('email.price') : 0;
    }

    public function accessTypeName(?AccessType $access_type = null): ?string
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

    public function save()
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
