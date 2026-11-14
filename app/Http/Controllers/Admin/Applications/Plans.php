<?php

namespace App\Http\Controllers\Admin\Applications;

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\Http\Controllers\Controller;
use App\Organization;
use App\Server;
use App\Support\Facades\Application as ApplicationFacade;
use App\Support\Facades\Settings as SettingsFacade;
use App\Support\PlanBreadcrumbs;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class Plans extends Controller
{
    public function index(Application $app)
    {
        $organization = auth()->user()->organization;
        $plans = AppPlan::where('archive', 0)
            ->visible()
            ->where('application_id', $app->id)
            ->orderBy('display_order', 'asc')
            ->get();

        $archived = AppPlan::where('archive', 1)
            ->visible()
            ->where('application_id', $app->id)
            ->get();

        return inertia()->render('Admin/Applications/Plans/PlansList', [
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
                'can' => [
                    'shareable' => ApplicationFacade::profile($app)->isCompatible(['shareable']),
                ],
            ],
            'shared_apps' => $this->sharedApps($app),
            'plans' => $plans->map(function ($plan) {
                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'active_subscribers' => $plan->subscribers()->count(),
                    'is_default' => $plan->is_default,
                ];
            }),
            'archived' => $archived->map(function ($plan) {
                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'active_subscribers' => $plan->subscribers()->count(),
                    'is_default' => $plan->is_default,
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
                    'label' => __('admin.applications.plans.plans'),
                ],
            ],
        ]);
    }

    public function show(Application $app, AppPlan $plan)
    {
        $organization = auth()->user()->organization;

        // Checks if there are active subscribers of this plan
        $subscribers = $plan->subscribers()->count() == 0 ? false : true;

        $features = ApplicationFacade::profile($app->slug)->features();
        $parent_servers = Server::where('app_instance_id', '>', 0)->with('app_instance')->get();
        $application_id = $app->slug;
        $configs = ApplicationFacade::configurations($app, $plan, true);
        $settings = $plan->publicSettings();

        $plan_features = ApplicationFacade::plan($plan)->features()->all();
        $settings['features'] = $plan_features;

        return inertia()->render('Admin/Applications/Plans/PlanView', [
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
            ],
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'is_default' => $plan->is_default,
                'features' => json_encode($plan->features ?? []),
                'description' => $plan->description,
                'payment_enabled' => $plan->payment_enabled,
                'admin_access' => $plan->setting('admin_access'),
                'domain_enabled' => $plan->domain_enabled,
                'domain_max' => $plan->domain_max,
                'web_server' => $plan->web_server ? $plan->web_server->name : null,
                'database_server' => $plan->database_server ? $plan->database_server->name : null,
                'sso_server' => $plan->sso_server ? $plan->sso_server->name : null,
                'parent_server' => $plan->setting('parent_server_id'),
                'settings' => $settings,
                'archived' => $plan->archive,
                'hidden' => $plan->hidden,
                'full_settings' => $plan->hasFullSettings(),
                'tabs' => $plan->tabs(),
                'expires_after' => $plan->setting('expires_after'),
                'trial_for' => $plan->setting('trial_for'),
                'self_registration_enabled' => $plan->selfRegistrationEnabled(),
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
            'configs' => $configs,
            'breadcrumbs' => PlanBreadcrumbs::for($app, $plan),
        ]);
    }

    public function store(Request $request, Application $app)
    {
        // Only an app that can be shared asks which server type the plan uses;
        // every other app's plans activate their own app.
        $shareable = ApplicationFacade::profile($app)->isCompatible(['shareable']);

        /* Validate */
        $validated = $request->validate([
            'name' => 'required',
            'description' => 'required',
            'server_type' => ['nullable', Rule::in($shareable ? ['separate', 'shared'] : ['separate'])],
            'shared_app' => [
                Rule::requiredIf($request->input('server_type') === 'shared'),
                'nullable',
                'numeric',
                Rule::exists('app_instances', 'id')->where('application_id', $app->id),
            ],
        ]);

        $server_type = $validated['server_type'] ?? 'separate';

        // Get bottom display order number
        $order_num = AppPlan::where('display_order', '>', 0)->orderBy('display_order', 'desc')->first();
        $display_order = $order_num ? $order_num->display_order : 0;

        $plan = new AppPlan;
        $plan->name = $request->name;
        $plan->application_id = $app->id;
        $plan->description = $request->description;
        $plan->features = [];
        $plan->display_order = $display_order + 1;
        $plan->settings = [
            'server_type' => $server_type,
            'base' => [],
            'standard' => [],
            'basic' => [],
            'storage' => [],
            'application' => [],
        ];

        if ($server_type === 'shared') {
            $this->connectToSharedApp($plan, AppInstance::findOrFail($validated['shared_app']));
        }

        $plan->save();

        return redirect("/admin/apps/{$app->slug}/plans/{$plan->id}")->with('success', __('admin.applications.plans.added'));
    }

    public function clone(Request $request, Application $app, AppPlan $plan)
    {
        // Hidden plans belong to a shared app and can't be duplicated
        abort_if($plan->application_id !== $app->id || $plan->hidden, 404);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $order_num = AppPlan::where('application_id', $app->id)
            ->where('display_order', '>', 0)
            ->orderBy('display_order', 'desc')
            ->first();

        // Copies every attribute (settings, servers, pricing, secrets, ...)
        // apart from the id and timestamps; the copy starts as a non-default,
        // unarchived plan with no subscribers.
        $clone = $plan->replicate();
        $clone->name = $validated['name'];
        $clone->display_order = ($order_num ? $order_num->display_order : 0) + 1;
        $clone->is_default = false;
        $clone->archive = false;
        $clone->save();

        return redirect("/admin/apps/{$app->slug}/plans/{$clone->id}")->with('success', __('admin.applications.plans.cloned', ['plan' => $clone->name]));
    }

    public function edit(Application $app, AppPlan $plan)
    {
        $organization = auth()->user()->organization;

        // Checks if there are active subscribers of this plan
        $subscribers = $plan->subscribers()->count() == 0 ? false : true;

        $features = ApplicationFacade::features($app->slug);

        $web_servers = Server::where('type', 'web')->get();
        $database_servers = Server::where('type', 'database')->get();
        $sso_servers = Server::where('type', 'sso')->get();
        $parent_servers = Server::where('app_instance_id', '>', 0)->with('app_instance')->get();
        $application_id = $app->slug;
        $settings = $plan->publicSettings();

        $plan_features = ApplicationFacade::plan($plan)->features()->all();
        $profile = ApplicationFacade::profile($app);
        $settings['features'] = $plan_features;

        $enabledCurrencies = json_decode(SettingsFacade::get('enabled_currencies', '["USD"]'), true) ?: ['USD'];

        return inertia()->render('Admin/Applications/Plans/PlanEdit', [
            'enabled_currencies' => $enabledCurrencies,
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
                'can' => [
                    'sso' => $profile->isCompatible(['openid', 'oauth2', 'saml']),
                    'shareable' => $profile->isCompatible(['shareable']),
                    'additional_user_storage' => $profile->isCompatible('additional_user_storage'),
                    'additional_storage' => $profile->isCompatible('additional_user_storage'),
                ],
            ],
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'is_default' => $plan->is_default,
                'features' => $plan->features ?? [],
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
                'hidden' => $plan->hidden,
                'full_settings' => $plan->hasFullSettings(),
                'tabs' => $plan->tabs(),
                'expires_after' => $plan->setting('expires_after'),
                'trial_for' => $plan->setting('trial_for'),
                'self_registration_enabled' => $plan->selfRegistrationEnabled(),
            ],
            'web_servers' => $web_servers->map(function ($server) {
                return [
                    'value' => $server->id,
                    'text' => $server->name.' ('.$server->status.')',
                ];
            }),
            'database_servers' => $database_servers->map(function ($server) {
                return [
                    'value' => $server->id,
                    'text' => $server->name.' ('.$server->status.')',
                ];
            }),
            'sso_servers' => $sso_servers->map(function ($server) {
                return [
                    'value' => $server->id,
                    'text' => $server->name.' ('.$server->status.')',
                ];
            }),
            'shared_apps' => $this->sharedApps($app),
            'breadcrumbs' => PlanBreadcrumbs::for($app, $plan),
        ]);
    }

    public function update(Request $request, Application $app, AppPlan $plan)
    {
        $enabledCurrencies = json_decode(SettingsFacade::get('enabled_currencies', '["USD"]'), true) ?: ['USD'];
        $components = ['base', 'standard', 'basic', 'storage'];

        $currencyRules = [];
        foreach ($enabledCurrencies as $currency) {
            foreach ($components as $component) {
                $currencyRules["prices.{$component}.{$currency}.amount"] = 'numeric|nullable';
                $currencyRules["prices.{$component}.{$currency}.price_id"] = 'string|max:50|nullable';
            }
        }

        /* Validate */
        $validateConfigurations = ApplicationFacade::validateConfigurations($app);
        $validated = $request->validate(array_merge([
            'name' => 'required',
            'default' => 'nullable',
            'description' => 'required',
            'displayed_features' => 'nullable',
            'payment_enabled' => 'nullable',
            'admin_access' => 'nullable|bool',
            'base.storage' => 'numeric|nullable',
            'base.max' => 'numeric|nullable',
            'standard.max' => 'numeric|nullable',
            'standard.storage' => 'numeric|nullable',
            'basic.name' => 'string|nullable',
            'basic.amount' => 'numeric|nullable',
            'basic.max' => 'numeric|nullable',
            'basic.storage' => 'numeric|nullable',
            'storage.max' => 'numeric|nullable',
            'storage.amount' => 'numeric|nullable',
            'domain_enabled' => 'nullable',
            'domain_max' => 'numeric|nullable',
            'web_server' => 'numeric|nullable|exists:servers,id',
            'database_server' => 'numeric|nullable|exists:servers,id',
            'sso_server' => 'numeric|nullable|exists:servers,id',
            'expires_after' => 'nullable|numeric',
            'trial_for' => 'nullable|numeric',
            'shared_app' => 'numeric|nullable|exists:app_instances,id',
            // Chosen when the plan is created; the settings only carry it along.
            'server_type' => 'nullable|in:separate,shared',
            'self_registration_enabled' => 'nullable|bool',
        ], $currencyRules));
        // Get bottom display order number
        $order_num = AppPlan::where('display_order', '>', 0)->orderBy('display_order', 'desc')->first();

        $web_server = $request->web_server;
        $database_server = $request->database_server;
        $sso_server = $request->sso_server;

        // A plan's server type is fixed when it's created, so an update that
        // doesn't send one keeps the plan's own.
        $server_type = $request->input('server_type') ?: $plan->setting('server_type');

        if ($server_type === 'shared' && ($shared_app = AppInstance::find($request->shared_app)) && $shared_app->plan) {
            $web_server = $shared_app->plan->web_server_id;
            $database_server = $shared_app->plan->database_server_id;
            $sso_server = $shared_app->plan->sso_server_id;
        }

        $plan->name = $request->name;
        $plan->description = $request->description;
        $plan->features = $request->displayed_features ?? [];
        $plan->payment_enabled = $request->payment_enabled;
        $plan->web_server_id = $web_server;
        $plan->database_server_id = $database_server;
        $plan->sso_server_id = $sso_server;
        $plan->shared_app_id = $request->shared_app;
        $plan->domain_enabled = $request->domain_enabled;
        $plan->domain_max = $request->domain_max;
        $settingsToUpdate = [
            'admin_access' => $request->input('admin_access'),
            'base.storage' => (int) $request->input('base.storage'),
            'base.max' => (int) $request->input('base.max'),
            'standard.max' => (int) $request->input('standard.max'),
            'standard.storage' => (int) $request->input('standard.storage'),
            'basic.name' => $request->input('basic.name'),
            'basic.amount' => (int) $request->input('basic.amount'),
            'basic.max' => (int) $request->input('basic.max'),
            'basic.storage' => (int) $request->input('basic.storage'),
            'storage.max' => (int) $request->input('storage.max'),
            'storage.amount' => (int) $request->input('storage.amount'),
            'expires_after' => (int) $request->input('expires_after'),
            'trial_for' => (int) $request->input('trial_for'),
            'self_registration_enabled' => $request->boolean('self_registration_enabled'),
        ];

        foreach ($enabledCurrencies as $currency) {
            foreach ($components as $component) {
                $settingsToUpdate["{$component}.prices.{$currency}.amount"] = $request->input("prices.{$component}.{$currency}.amount");
                $settingsToUpdate["{$component}.prices.{$currency}.price_id"] = $request->input("prices.{$component}.{$currency}.price_id");
            }
        }

        if (! $plan->hidden && $server_type) {
            $settingsToUpdate['server_type'] = $server_type;
        }

        $plan->updateSettings($settingsToUpdate);
        $plan->save();

        // Update default plan
        if (Arr::get($validated, 'default', false)) {
            $plan_service = ApplicationFacade::plan($plan)->setDefault();
        } else {
            $plan->is_default = false;
            $plan->save();
        }

        Cache::flush();

        return redirect("/admin/apps/{$app->slug}/plans/{$plan->id}")->with('success', __('admin.applications.plans.added', ['plan' => $plan->name]));
    }

    public function remove(Application $app, AppPlan $plan)
    {
        if ($plan->subscribers->count() == 0) {
            $plan_name = $plan->name;
            $plan->delete();

            return redirect("/admin/apps/{$app->slug}/plans")->with('success', __('admin.applications.plans.added', ['plan' => $plan_name]));
        }

        return redirect("/admin/apps/{$app->slug}/plans")->with('error', __('admin.applications.plans.added'));

    }

    public function updateOrder(Request $request, Application $app)
    {
        /* Validate */
        $validated = $request->validate([
            'plans' => 'array|nullable',
        ]);

        $n = 1;
        foreach ($validated['plans'] as $plan) {
            $plan = AppPlan::find($plan['id']);
            $plan->display_order = $n;
            $plan->save();

            $n++;
        }

        return redirect("/admin/apps/{$app->slug}/plans")->with('success', __('admin.applications.plans.order_updated'));
    }

    public function archive(Application $app, AppPlan $plan)
    {
        $plan->archive = true;
        $plan->save();

        return redirect("/admin/apps/{$app->slug}/plans")->with('success', __('admin.applications.plans.archived'));
    }

    public function unarchive(Application $app, AppPlan $plan)
    {
        $plan->archive = false;
        $plan->save();

        return redirect("/admin/apps/{$app->slug}/plans")->with('success', __('admin.applications.plans.unarchived'));
    }

    /**
     * The running shared apps of this application a plan can connect to.
     */
    private function sharedApps(Application $app)
    {
        return Organization::where('type', 'shared')->first()
            ?->app_instances
            ->where('application_id', $app->id)
            ->map(fn ($instance) => [
                'id' => $instance->id,
                'name' => $instance->label,
            ])
            ->values();
    }

    /**
     * A plan connected to a shared app uses the servers that app runs on.
     */
    private function connectToSharedApp(AppPlan $plan, AppInstance $shared_app): void
    {
        $plan->shared_app_id = $shared_app->id;

        if ($shared_app->plan) {
            $plan->web_server_id = $shared_app->plan->web_server_id;
            $plan->database_server_id = $shared_app->plan->database_server_id;
            $plan->sso_server_id = $shared_app->plan->sso_server_id;
        }
    }

    public function retrieve(Application $app)
    {
        return response()->json($app->plans->where('hidden', false)->map(function ($plan) {
            return [
                'value' => $plan->id,
                'text' => $plan->name,
            ];
        }));
    }
}
