<?php

namespace App\Http\Controllers\Admin\Applications\Plans;

use App\Application;
use App\AppPlan;
use App\Http\Controllers\Controller;
use App\Support\Facades\Application as ApplicationFacade;
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
            'breadcrumbs' => [
                [
                    'url' => '/admin/apps',
                    'label' => 'Apps',
                ],
                [
                    'label' => $app->name,
                    'url' => '/admin/apps/'.$app->slug,
                ],
                [
                    'url' => '/admin/apps/'.$app->slug.'/plans',
                    'label' => 'Plans',
                ],
                [
                    'url' => '/admin/apps/'.$app->slug.'/plans/'.$plan->id,
                    'label' => $plan->name,
                ],
            ],
        ]);
    }

    public function update(Request $request, Application $app, AppPlan $plan)
    {
        $validated = $request->validate([
            'features' => 'array|nullable',
        ]);

        ApplicationFacade::plan($plan)->updateFeatures($request->features);

        return redirect("/admin/apps/{$app->slug}/plans/{$plan->id}/features")->with('success', 'Plan: '.$plan->name.' features updated!');
    }
}
