<?php

namespace App\Support;

use App\Application;
use App\AppPlan;

class PlanBreadcrumbs
{
    /**
     * A hidden plan belongs to exactly one shared app (see SharedApps::store()),
     * so its breadcrumb links back there instead of through the normal app's plan list.
     */
    public static function for(Application $app, AppPlan $plan): array
    {
        if ($plan->hidden && $plan->instance) {
            return [
                [
                    'url' => '/admin/service/shared-apps',
                    'label' => __('admin.shared_apps.shared_apps'),
                ],
                [
                    'url' => '/admin/service/shared-apps/'.$plan->instance->id,
                    'label' => $plan->instance->label,
                ],
                [
                    'label' => __('admin.shared_apps.plan_settings'),
                ],
            ];
        }

        return [
            [
                'url' => '/admin/apps',
                'label' => __('admin.applications.apps'),
            ],
            [
                'label' => $app->name,
                'url' => '/admin/apps/'.$app->slug,
            ],
            [
                'url' => '/admin/apps/'.$app->slug.'/plans',
                'label' => __('admin.applications.plans.plans'),
            ],
            [
                'url' => '/admin/apps/'.$app->slug.'/plans/'.$plan->id,
                'label' => $plan->name,
            ],
        ];
    }
}
