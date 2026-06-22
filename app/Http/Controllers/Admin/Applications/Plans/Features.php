<?php

namespace App\Http\Controllers\Admin\Applications\Plans;

use App\Application;
use App\AppPlan;
use App\Http\Controllers\Controller;
use App\Server;
use App\Support\Facades\Application as ApplicationFacade;
use Illuminate\Http\Request;

class Features extends Controller
{
    private function withServerOptions(array $settings): array
    {
        foreach ($settings as $name => $setting) {
            if (($setting['type'] ?? null) === 'server') {
                $settings[$name]['servers'] = Server::where('type', $setting['server_type'])
                    ->where('status', 'active')
                    ->get(['id', 'name'])
                    ->map(fn ($server) => ['id' => $server->id, 'name' => $server->name]);
            }
        }

        return $settings;
    }

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
                    'settings' => $this->withServerOptions($feature->admin_settings()),
                ];
            }),
            'breadcrumbs' => [
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
            ],
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
