<?php

namespace App\Http\Controllers\Admin\Applications\Plans;

use App\Application;
use App\AppPlan;
use App\Http\Controllers\Controller;
use App\Support\Facades\Application as ApplicationFacade;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class Configurations extends Controller
{
    public function edit(Application $app, AppPlan $plan)
    {
        $configs = ApplicationFacade::configurations($app, $plan, true);
        $additionalConfigs = $plan->additionalConfigs();

        return inertia()->render('Admin/Applications/Plans/PlanConfigurations', [
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
            ],
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
            ],
            'configs' => array_merge($configs, $additionalConfigs),
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
        $validateConfigurations = ApplicationFacade::validateConfigurations($app);

        $validationTypes = [
            'string' => 'nullable|string',
            'bool' => 'boolean',
            'textarea' => 'nullable|string',
            'password' => 'nullable|string',
            'int' => 'nullable|integer',
        ];

        $validateAdditionalConfigurations = [];
        $additionalConfigs = [];
        $mergedAdditionalConfigs = array_merge($request->additionalConfigs, $plan->additionalConfigs());

        foreach ($mergedAdditionalConfigs as $config) {
            if (array_key_exists($config['type'], $validationTypes) && Arr::has($request->configurations, $config['name'])) {
                $validateAdditionalConfigurations["configurations.{$config['name']}"] = $validationTypes[$config['type']];
                $additionalConfigs[$config['name']] = $config;
            }
        }

        $validated = $request->validate(array_merge($validateConfigurations, $validateAdditionalConfigurations));

        $plan->updateSettings([
            'configurations' => Arr::has($validated, 'configurations') ? ApplicationFacade::processConfigurations($app, $plan, $validated['configurations']) : [],
            'additionalConfigs' => $additionalConfigs ?? [],
        ]);
        $plan->save();

        return redirect("/admin/apps/{$app->slug}/plans/{$plan->id}/configurations")->with('success', 'Plan: '.$plan->name.' configurations updated!');
    }
}
