<?php

namespace App\Services\Organization;

use App\Application;
use App\AppPlan;
use App\Enums\PlanEntity;

/**
 * The base plan of the one organization in a single-organization install.
 *
 * There is nothing to subscribe to, so instead of reading which apps and how
 * many of them the plan allows, every app is available and nothing is limited.
 * Which plans an app offers comes from the app's own visible plans.
 */
class SingleTenantBasePlanService extends BasePlanService
{
    public function allAvailable()
    {
        return collect([$this]);
    }

    public function enabledApps()
    {
        return Application::orderBy('slug')->where('enabled', 1)->get();
    }

    public function appEnabled(Application $app)
    {
        return (bool) $this->plan;
    }

    /**
     * Every visible plan of the app, archived ones included: callers that
     * only want the live ones filter on archive themselves.
     */
    public function appPlansList(Application $app)
    {
        return AppPlan::where('application_id', $app->id)->visible()->pluck('id')->all();
    }

    public function appPlanEnabled(AppPlan $plan)
    {
        return ! $plan->getAttribute('hidden');
    }

    public function appPlanSelectable(AppPlan $plan): bool
    {
        return ! $plan->archive && $this->appPlanEnabled($plan) && $this->appEnabled($plan->application);
    }

    public function appMax(Application $app)
    {
        return null;
    }

    public function isMaxApps(Application $app)
    {
        return false;
    }

    public function isMaxAppsBroken()
    {
        return false;
    }

    public function isAnyMaxBroken()
    {
        return false;
    }

    /**
     * Every option a plan can switch on is on, whatever the stored plan says: the plan can't be edited
     * in a single-organization install, and it may have been saved before these options existed.
     */
    public function domainsEnabled()
    {
        return true;
    }

    public function emailEnabled()
    {
        return true;
    }

    /**
     * Bringing a domain you already own is on. Registering or transferring one is a paid service with
     * a payment method, which a single-organization install has none of, so those stay as saved.
     */
    public function setting($setting)
    {
        return $setting === 'domains.connect' ? true : $this->plan->setting($setting);
    }

    public function isDomainMax()
    {
        return false;
    }

    public function isMax($entity)
    {
        return false;
    }

    public function isMaxBroken(PlanEntity $entity)
    {
        return false;
    }
}
