<?php

namespace App\Http\Controllers\Admin\Applications\Plans;

use App\Application;
use App\AppPlan;
use App\Http\Controllers\Controller;
use App\Support\Facades\Application as ApplicationFacade;
use App\Support\PlanBreadcrumbs;
use Illuminate\Http\Request;

class Features extends Controller
{
    public function edit(Application $app, AppPlan $plan)
    {
        $features = ApplicationFacade::profile($app->slug)->features();
        $settings = $plan->settings;

        $plan_features = ApplicationFacade::plan($plan)->features()->all();
        $settings['features'] = $plan_features;

        return inertia()->render('Admin/Applications/Plans/PlanFeatures', [
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
            ],
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'settings' => $settings,
                'hidden' => $plan->hidden,
                'shared_app_active' => $plan->isSharedAppActive(),
            ],
            'features' => $features->map(function ($feature) {
                return [
                    'label' => $feature->label,
                    'type' => $feature->type,
                    'input' => $feature->input,
                    'value' => $feature->name,
                    'description' => $feature->description,
                    'settings' => $feature->admin_settings(),
                ];
            }),
            'breadcrumbs' => PlanBreadcrumbs::for($app, $plan),
        ]);
    }

    public function update(Request $request, Application $app, AppPlan $plan)
    {
        $validated = $request->validate([
            'features' => 'array|nullable',
        ]);

        ApplicationFacade::plan($plan)->updateFeatures($request->features);

        return redirect("/admin/apps/{$app->slug}/plans/{$plan->id}/features")->with('success', __('admin.applications.plans.features_updated', ['plan' => $plan->name]));
    }
}
