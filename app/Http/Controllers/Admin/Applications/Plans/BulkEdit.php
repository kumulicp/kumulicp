<?php

namespace App\Http\Controllers\Admin\Applications\Plans;

use App\Application;
use App\AppPlan;
use App\Http\Controllers\Controller;
use App\Organization;
use App\Server;
use App\Support\Facades\Application as ApplicationFacade;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class BulkEdit extends Controller
{
    private function getPlans(Request $request, Application $app)
    {
        $planIds = $request->input('plans', []);

        return AppPlan::whereIn('id', $planIds)
            ->where('application_id', $app->id)
            ->where('archive', 0)
            ->get();
    }

    private function getAppData(Application $app): array
    {
        $profile = ApplicationFacade::profile($app);

        return [
            'id' => $app->id,
            'name' => $app->name,
            'slug' => $app->slug,
            'can' => [
                'sso' => $profile->isCompatible(['openid', 'oauth2', 'saml']),
                'shareable' => $profile->isCompatible(['shareable']),
                'additional_user_storage' => $profile->isCompatible('additional_user_storage'),
                'additional_storage' => $profile->isCompatible('additional_user_storage'),
            ],
        ];
    }

    private function getServerData(): array
    {
        $web_servers = Server::where('type', 'web')->get();
        $database_servers = Server::where('type', 'database')->get();
        $sso_servers = Server::where('type', 'sso')->get();

        return [
            'web_servers' => $web_servers->map(fn ($s) => ['value' => $s->id, 'text' => $s->name.' ('.$s->status.')']),
            'database_servers' => $database_servers->map(fn ($s) => ['value' => $s->id, 'text' => $s->name.' ('.$s->status.')']),
            'sso_servers' => $sso_servers->map(fn ($s) => ['value' => $s->id, 'text' => $s->name.' ('.$s->status.')']),
            'shared_apps' => Organization::where('type', 'shared')->first()?->app_instances->map(fn ($a) => ['id' => $a->id, 'name' => $a->label]),
        ];
    }

    private function formatPlan(AppPlan $plan): array
    {
        $settings = $plan->settings;
        $plan_features = ApplicationFacade::plan($plan)->features()->all();
        $settings['features'] = $plan_features;

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'is_default' => $plan->is_default,
            'features' => $plan->features,
            'description' => $plan->description,
            'payment_enabled' => $plan->payment_enabled,
            'admin_access' => $plan->setting('admin_access'),
            'domain_enabled' => $plan->domain_enabled,
            'domain_max' => $plan->domain_max,
            'web_server' => $plan->web_server ? $plan->web_server->id : null,
            'database_server' => $plan->database_server ? $plan->database_server->id : null,
            'sso_server' => $plan->sso_server ? $plan->sso_server->id : null,
            'shared_app' => $plan->shared_app_id,
            'settings' => $settings,
            'archived' => $plan->archive,
            'expires_after' => $plan->setting('expires_after'),
            'trial_for' => $plan->setting('trial_for'),
        ];
    }

    private function breadcrumbs(Application $app): array
    {
        return [
            ['url' => '/admin/apps', 'label' => __('admin.applications.apps')],
            ['label' => $app->name, 'url' => '/admin/apps/'.$app->slug],
            ['url' => '/admin/apps/'.$app->slug.'/plans', 'label' => __('admin.applications.plans.plans')],
            ['label' => __('admin.plans.bulkEdit')],
        ];
    }

    public function view(Request $request, Application $app)
    {
        $plans = $this->getPlans($request, $app);
        $features = ApplicationFacade::profile($app->slug)->features();
        $planIds = $plans->pluck('id')->toArray();

        $formattedPlans = $plans->map(fn ($plan) => $this->formatPlan($plan))->values();

        $firstPlanConfigs = [];
        if ($plans->isNotEmpty()) {
            $configs = ApplicationFacade::configurations($app, $plans->first(), true);
            $additionalConfigs = $plans->first()->additionalConfigs();
            $firstPlanConfigs = array_merge($configs, $additionalConfigs);
        }

        return inertia()->render('Admin/Applications/Plans/BulkEdit/BulkEditView', array_merge($this->getServerData(), [
            'app' => $this->getAppData($app),
            'plans' => $formattedPlans,
            'plan_ids' => $planIds,
            'features' => $features->map(fn ($f) => [
                'label' => $f->label,
                'value' => $f->name,
                'description' => $f->description,
                'settings' => $f->admin_settings(),
            ]),
            'configs' => $firstPlanConfigs,
            'breadcrumbs' => $this->breadcrumbs($app),
        ]));
    }

    public function edit(Request $request, Application $app)
    {
        $plans = $this->getPlans($request, $app);
        $planIds = $plans->pluck('id')->toArray();
        $formattedPlans = $plans->map(fn ($plan) => $this->formatPlan($plan))->values();

        return inertia()->render('Admin/Applications/Plans/BulkEdit/BulkEditSettings', array_merge($this->getServerData(), [
            'app' => $this->getAppData($app),
            'plans' => $formattedPlans,
            'plan_ids' => $planIds,
            'breadcrumbs' => $this->breadcrumbs($app),
        ]));
    }

    public function update(Request $request, Application $app)
    {
        $planIds = $request->input('plan_ids', []);
        $plans = AppPlan::whereIn('id', $planIds)->where('application_id', $app->id)->get();

        $request->validate([
            'plan_ids' => 'array',
            'plans.*.name' => 'required|string',
            'plans.*.description' => 'required|string',
            'plans.*.server_type' => 'required|in:separate,shared',
            'plans.*.default' => 'nullable',
            'plans.*.payment_enabled' => 'nullable',
            'plans.*.admin_access' => 'nullable|bool',
            'plans.*.base.price' => 'numeric|nullable',
            'plans.*.base.price_id' => 'string|max:50|nullable',
            'plans.*.base.storage' => 'numeric|nullable',
            'plans.*.base.max' => 'numeric|nullable',
            'plans.*.standard.price' => 'numeric|nullable',
            'plans.*.standard.max' => 'numeric|nullable',
            'plans.*.standard.price_id' => 'string|max:50|nullable',
            'plans.*.standard.storage' => 'numeric|nullable',
            'plans.*.basic.name' => 'string|nullable',
            'plans.*.basic.price' => 'numeric|nullable',
            'plans.*.basic.amount' => 'numeric|nullable',
            'plans.*.basic.max' => 'numeric|nullable',
            'plans.*.basic.price_id' => 'string|max:50|nullable',
            'plans.*.basic.storage' => 'numeric|nullable',
            'plans.*.storage.price' => 'numeric|nullable',
            'plans.*.storage.max' => 'numeric|nullable',
            'plans.*.storage.price_id' => 'string|max:50|nullable',
            'plans.*.storage.amount' => 'numeric|nullable',
            'plans.*.domain_enabled' => 'nullable',
            'plans.*.domain_max' => 'numeric|nullable',
            'plans.*.web_server' => 'numeric|nullable|exists:servers,id',
            'plans.*.database_server' => 'numeric|nullable|exists:servers,id',
            'plans.*.sso_server' => 'numeric|nullable|exists:servers,id',
            'plans.*.shared_app' => 'numeric|nullable|exists:app_instances,id',
            'plans.*.expires_after' => 'nullable|numeric',
            'plans.*.trial_for' => 'nullable|numeric',
        ]);

        foreach ($plans as $plan) {
            $d = $request->input("plans.{$plan->id}", []);
            if (empty($d)) {
                continue;
            }

            $plan->name = Arr::get($d, 'name');
            $plan->description = Arr::get($d, 'description');
            $plan->features = Arr::get($d, 'displayed_features', []);
            $plan->payment_enabled = Arr::get($d, 'payment_enabled');
            $plan->web_server_id = Arr::get($d, 'web_server');
            $plan->database_server_id = Arr::get($d, 'database_server');
            $plan->sso_server_id = Arr::get($d, 'sso_server');
            $plan->shared_app_id = Arr::get($d, 'shared_app');
            $plan->domain_enabled = Arr::get($d, 'domain_enabled');
            $plan->domain_max = Arr::get($d, 'domain_max');
            $plan->updateSettings([
                'server_type' => Arr::get($d, 'server_type'),
                'admin_access' => Arr::get($d, 'admin_access'),
                'base.price' => (int) Arr::get($d, 'base.price'),
                'base.price_id' => Arr::get($d, 'base.price_id'),
                'base.storage' => (int) Arr::get($d, 'base.storage'),
                'base.max' => (int) Arr::get($d, 'base.max'),
                'standard.price' => (int) Arr::get($d, 'standard.price'),
                'standard.max' => (int) Arr::get($d, 'standard.max'),
                'standard.price_id' => Arr::get($d, 'standard.price_id'),
                'standard.storage' => (int) Arr::get($d, 'standard.storage'),
                'basic.name' => Arr::get($d, 'basic.name'),
                'basic.price' => (int) Arr::get($d, 'basic.price'),
                'basic.amount' => (int) Arr::get($d, 'basic.amount'),
                'basic.max' => (int) Arr::get($d, 'basic.max'),
                'basic.price_id' => Arr::get($d, 'basic.price_id'),
                'basic.storage' => (int) Arr::get($d, 'basic.storage'),
                'storage.price' => (int) Arr::get($d, 'storage.price'),
                'storage.max' => (int) Arr::get($d, 'storage.max'),
                'storage.price_id' => Arr::get($d, 'storage.price_id'),
                'storage.amount' => (int) Arr::get($d, 'storage.amount'),
                'expires_after' => (int) Arr::get($d, 'expires_after'),
                'trial_for' => (int) Arr::get($d, 'trial_for'),
            ]);
            $plan->save();

            if (Arr::get($d, 'default', false)) {
                ApplicationFacade::plan($plan)->setDefault();
            } else {
                $plan->is_default = false;
                $plan->save();
            }
        }

        Cache::flush();

        $queryString = http_build_query(['plans' => $planIds]);

        return redirect("/admin/apps/{$app->slug}/plans/bulk-edit/edit?{$queryString}")
            ->with('success', __('admin.applications.plans.bulk_updated'));
    }

    public function editFeatures(Request $request, Application $app)
    {
        $plans = $this->getPlans($request, $app);
        $features = ApplicationFacade::profile($app->slug)->features();
        $planIds = $plans->pluck('id')->toArray();

        $formattedPlans = $plans->map(function ($plan) {
            $settings = $plan->settings;
            $plan_features = ApplicationFacade::plan($plan)->features()->all();
            $settings['features'] = $plan_features;

            return [
                'id' => $plan->id,
                'name' => $plan->name,
                'settings' => $settings,
            ];
        })->values();

        return inertia()->render('Admin/Applications/Plans/BulkEdit/BulkEditFeatures', [
            'app' => $this->getAppData($app),
            'plans' => $formattedPlans,
            'plan_ids' => $planIds,
            'features' => $features->map(fn ($f) => [
                'label' => $f->label,
                'type' => $f->type,
                'input' => $f->input,
                'value' => $f->name,
                'description' => $f->description,
                'settings' => $f->admin_settings(),
            ]),
            'breadcrumbs' => $this->breadcrumbs($app),
        ]);
    }

    public function updateFeatures(Request $request, Application $app)
    {
        $planIds = $request->input('plan_ids', []);
        $plans = AppPlan::whereIn('id', $planIds)->where('application_id', $app->id)->get();

        $request->validate([
            'plan_ids' => 'array',
            'plans.*.features' => 'array|nullable',
        ]);

        foreach ($plans as $plan) {
            $planFeatures = $request->input("plans.{$plan->id}.features");
            if ($planFeatures !== null) {
                ApplicationFacade::plan($plan)->updateFeatures($planFeatures);
            }
        }

        $queryString = http_build_query(['plans' => $planIds]);

        return redirect("/admin/apps/{$app->slug}/plans/bulk-edit/features?{$queryString}")
            ->with('success', __('admin.applications.plans.features_updated', ['plan' => 'all plans']));
    }

    public function editConfigurations(Request $request, Application $app)
    {
        $plans = $this->getPlans($request, $app);
        $planIds = $plans->pluck('id')->toArray();

        $formattedPlans = $plans->map(function ($plan) use ($app) {
            $configs = ApplicationFacade::configurations($app, $plan, true);
            $additionalConfigs = $plan->additionalConfigs();

            return [
                'id' => $plan->id,
                'name' => $plan->name,
                'configs' => array_merge($configs, $additionalConfigs),
            ];
        })->values();

        // Use first plan's config keys as the schema for table rows
        $configSchema = $formattedPlans->first()['configs'] ?? [];

        return inertia()->render('Admin/Applications/Plans/BulkEdit/BulkEditConfigurations', [
            'app' => $this->getAppData($app),
            'plans' => $formattedPlans,
            'plan_ids' => $planIds,
            'config_schema' => $configSchema,
            'breadcrumbs' => $this->breadcrumbs($app),
        ]);
    }

    public function updateConfigurations(Request $request, Application $app)
    {
        $planIds = $request->input('plan_ids', []);
        $plans = AppPlan::whereIn('id', $planIds)->where('application_id', $app->id)->get();

        $validateConfigurations = ApplicationFacade::validateConfigurations($app);

        $validationTypes = [
            'string' => 'nullable|string',
            'bool' => 'boolean',
            'textarea' => 'nullable|string',
            'password' => 'nullable|string',
            'int' => 'nullable|integer',
        ];

        foreach ($plans as $plan) {
            $planData = $request->input("plans.{$plan->id}", []);
            if (empty($planData)) {
                continue;
            }

            $configurations = Arr::get($planData, 'configurations', []);
            $additionalConfigsInput = Arr::get($planData, 'additionalConfigs', []);

            $additionalConfigs = [];
            $mergedAdditionalConfigs = array_merge($additionalConfigsInput, $plan->additionalConfigs());

            foreach ($mergedAdditionalConfigs as $config) {
                if (array_key_exists($config['type'], $validationTypes) && Arr::has($configurations, $config['name'])) {
                    $additionalConfigs[$config['name']] = $config;
                }
            }

            $plan->updateSettings([
                'configurations' => ! empty($configurations)
                    ? ApplicationFacade::processConfigurations($app, $plan, $configurations)
                    : [],
                'additionalConfigs' => $additionalConfigs,
            ]);
            $plan->save();
        }

        Cache::flush();

        $queryString = http_build_query(['plans' => $planIds]);

        return redirect("/admin/apps/{$app->slug}/plans/bulk-edit/configurations?{$queryString}")
            ->with('success', __('admin.applications.plans.configurations_updated', ['plan' => 'all plans']));
    }
}
