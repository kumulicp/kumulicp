<?php

namespace App\Http\Controllers\Admin\Applications\Plans;

use App\Application;
use App\AppPlan;
use App\Http\Controllers\Controller;
use App\Support\ExampleAppInstance;
use App\Support\Facades\Application as ApplicationFacade;
use App\Support\PlanBreadcrumbs;
use App\Support\PlanValuesPreview;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class Configurations extends Controller
{
    public function edit(Application $app, AppPlan $plan)
    {
        abort_unless($plan->hasFullSettings(), 404);

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
                'hidden' => $plan->hidden,
                'full_settings' => $plan->hasFullSettings(),
                'tabs' => $plan->tabs(),
            ],
            'configs' => array_merge($configs, $additionalConfigs),
            'breadcrumbs' => PlanBreadcrumbs::for($app, $plan),
        ]);
    }

    // Renders the Helm values the plan's charts would produce with the form's
    // unsaved values, for the live example beside the editor. Nothing is saved.
    public function preview(Request $request, Application $app, AppPlan $plan, PlanValuesPreview $preview)
    {
        $validated = $request->validate([
            'configurations' => 'nullable|array',
            'additionalConfigs' => 'nullable|array',
        ]);

        return response()->json($preview->render(
            $app,
            $plan,
            $validated['configurations'] ?? [],
            $validated['additionalConfigs'] ?? [],
        ));
    }

    // Makes the example instance (on the app's latest version) the preview borrows
    // when the app has no instance yet, then renders the preview from it.
    public function generatePreview(Request $request, Application $app, AppPlan $plan, PlanValuesPreview $preview)
    {
        $validated = $request->validate([
            'configurations' => 'nullable|array',
            'additionalConfigs' => 'nullable|array',
        ]);

        if (! ExampleAppInstance::generate($app, $plan)) {
            return response()->json(['status' => PlanValuesPreview::NO_VERSION, 'charts' => [], 'instance' => null]);
        }

        return response()->json($preview->render(
            $app,
            $plan,
            $validated['configurations'] ?? [],
            $validated['additionalConfigs'] ?? [],
        ));
    }

    public function update(Request $request, Application $app, AppPlan $plan)
    {
        abort_unless($plan->hasFullSettings(), 404);

        $validateConfigurations = ApplicationFacade::validateConfigurations($app, false, $plan);

        $validationTypes = [
            'string' => 'nullable|string',
            'bool' => 'boolean',
            'textarea' => 'nullable|string',
            'password' => 'nullable|string',
            'int' => 'nullable|integer',
            'enum' => 'nullable|string',
        ];

        $validateAdditionalConfigurations = [];
        $additionalConfigs = [];
        // The request wins so array items renumbered after a removal take their new definition
        $mergedAdditionalConfigs = array_merge($plan->additionalConfigs(), $request->input('additionalConfigs', []));

        foreach ($mergedAdditionalConfigs as $config) {
            if (array_key_exists($config['type'], $validationTypes) && Arr::has($request->configurations, $config['name'])) {
                $validateAdditionalConfigurations["configurations.{$config['name']}"] = $validationTypes[$config['type']];
                $additionalConfigs[$config['name']] = $config;
            }
        }

        $validated = $request->validate(array_merge($validateConfigurations, $validateAdditionalConfigurations));

        $plan->updateSettings([
            'configurations' => Arr::has($validated, 'configurations') ? ApplicationFacade::processConfigurations($app, $plan, $validated['configurations']) : [],
            'additionalConfigs' => $additionalConfigs,
        ]);
        $plan->save();

        return redirect("/admin/apps/{$app->slug}/plans/{$plan->id}/configurations")->with('success', __('admin.applications.plans.configurations_updated', ['plan' => $plan->name]));
    }
}
