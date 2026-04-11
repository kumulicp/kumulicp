<?php

namespace App\Services;

use App\AppInstance;
use App\AppPlan;
use App\Organization;
use App\Plan;
use App\Services\AppInstance\AppInstancePlanService;
use App\Services\Organization\BasePlanService;
use App\Support\Facades\Application;
use App\Support\Facades\FastCache;
use App\Support\Facades\Organization as OrganizationFacade;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class SubscriptionService
{
    public $organization;

    public $plans = [];

    public $app_instance_plans = [];

    public function __construct(?Organization $organization = null)
    {
        $this->organization = $organization ?? OrganizationFacade::account();
    }

    public function all()
    {
        $subscription = $this;

        $this->plans = FastCache::retrieve('plans', function () use ($subscription) {
            $subscription->base();
            $subscription->appInstancePlans();

            return $subscription->plans;
        });

        return $this;
    }

    public function access()
    {
        return $this;
    }

    public function base()
    {
        if (! array_key_exists('base', $this->plans)) {
            $this->plans['base'] = new BasePlanService($this->organization);
        }

        return $this->plans['base'];
    }

    public function app_instance(AppInstance $app_instance)
    {
        if (! Arr::has($this->plans, $app_instance->id)) {
            $this->add($app_instance->id, $app_instance->plan, $app_instance);
        }

        return $this->plans[$app_instance->id];
    }

    public function get()
    {
        return $this->plans;
    }

    public function appInstancePlans()
    {
        $app_instances = $this->organization->app_instances()->with(['organization', 'plan'])->get();

        foreach ($app_instances as $app_instance) {
            if (! array_key_exists($app_instance->id, $this->plans)) {
                $this->add($app_instance->id, $app_instance->plan, $app_instance);
            }
        }

        return collect($this->app_instance_plans);
    }

    public function appInstanceSubscription(AppInstance $app_instance, AppPlan $plan)
    {
        return new AppInstancePlanService($plan, $app_instance);
    }

    public function add($name, $plan, $item)
    {
        if ($name == 'base') {
            $this->plans[$name] = new BasePlanService($item, $plan);
        } else {
            $app_instance_plan = new AppInstancePlanService($plan, $item);
            $this->plans[$name] = $app_instance_plan;
            $this->app_instance_plans[] = $app_instance_plan;
        }
    }

    public function dryBaseChange(Plan $plan)
    {
        $this->plans['base'] = new BasePlanService($this->organization, $plan);

        return $this;
    }

    public function dryAppChange(AppInstance $app_instance, AppPlan $plan)
    {
        $this->plans[$app_instance->id] = new AppInstancePlanService($plan, $app_instance);

        return $this;
    }

    public function updateBase(Plan $plan)
    {
        $this->organization->plan_id = $plan->id;
        $this->organization->save();

        $this->plans['base'] = new BasePlanService($this->organization, $plan);
        $this->updateAppInstancesFromBase($plan);

        return $this;
    }

    public function updateApp(AppPlan $plan, AppInstance $app_instance)
    {
        $app_instance->plan()->associate($plan);
        $app_instance->save();

        $this->plans[$app_instance->id] = new AppInstancePlanService($plan, $app_instance);

        return $this;
    }

    public function updateAppInstancesFromBase(Plan $plan)
    {
        foreach ($plan->app_plans as $name => $settings) {
            $plans = Arr::get($settings, 'plans');
            if (is_array($plans) && count($plans) === 1) {
                $app_plan_id = (int) $plans[0];
                $app_plan = AppPlan::find($app_plan_id);
                if ($app_plan) {
                    $app_instances = Application::instances($this->organization, $name);

                    foreach ($app_instances as $app_instance) {
                        $this->updateApp($app_plan, $app_instance);
                    }
                }
            }
        }

        return $this;
    }

    public function refresh()
    {
        $this->organization->refresh();
        $this->plans = [];
        $this->app_instance_plans = [];

        Cache::flush();

        $this->all();

        return $this;
    }

    public function paidSubscriptions()
    {
        $paid_plans = [];

        foreach ($this->plans as $name => $plan) {
            if ($plan?->payment_enabled && ! in_array($plan->model()->status, ['deactivating', 'deactivated', 'deleting'])) {
                $paid_plans[$name] = $plan;
            }
        }

        return $paid_plans;
    }

    public function compileAllSubscriptionInfo()
    {
        $data = [];

        foreach ($this->plans as $name => $plan) {
            if (count($plan->pricingOptions()) > 0) {
                $data[$name] = $plan->pricingOptions();
                $data[$name]['name'] = $plan->plan->name;
                $data[$name]['status'] = $plan->status();
            }
        }

        return $data;
    }

    public function compileAllStats()
    {
        $stats = [];

        foreach ($this->plans as $plan) {
            if (count($plan->stats()) > 0) {
                $stats[] = [
                    'name' => $plan->itemLabel(),
                    'stats' => $plan->stats(),
                ];
            }
        }

        return $stats;
    }

    public function compileCostStats()
    {
        $stats = [];

        foreach ($this->paidSubscriptions() as $plan) {
            if (count($plan->stats()) > 0) {
                $stats[] = [
                    'stats' => $plan->stats(),
                    'name' => $plan->itemLabel(),
                ];
            }
        }

        return $stats;
    }

    public function anyPending()
    {
        foreach ($this->plans as $plan) {
            if ($plan->status() == 'pending') {
                return true;
            }
        }

        return false;
    }

    public function isProrated()
    {
        return $this->base()->setting('base.prorated_enabled') === true;
    }

    public function domainsEnabled()
    {
        foreach ($this->plans as $plan) {
            if ($plan->domainsEnabled()) {
                return true;
            }
        }

        return false;
    }

    public function emailEnabled()
    {
        foreach ($this->plans as $plan) {
            if ($plan->emailEnabled()) {
                return true;
            }
        }

        return false;
    }

    public function totalPrice()
    {
        $total = 0;

        foreach ($this->paidSubscriptions() as $plan) {
            $total += $plan->totalPrice();
        }

        return $total;
    }

    public function clear()
    {
        $this->plans = [];
        $this->app_instance_plans = [];

        return $this;
    }

    public function plansWithHigherAppLimit(\App\Application $app)
    {
        $current_limit = $this->base()->appMax($app);
        $plans = collect();

        foreach ($this->base()->allAvailable() as $plan) {
            if ($plan->appMax($app) > $current_limit) {
                $plans->push($plan);
            }
        }

        return $plans;
    }

    public function isAppLimitReached(\App\Application $app)
    {
        return $this->plansWithHigherAppLimit($app)->count() > 0;
    }
}
