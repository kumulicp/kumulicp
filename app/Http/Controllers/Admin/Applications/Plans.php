<?php

namespace App\Http\Controllers\Admin\Applications;

use App\Application;
use App\AppPlan;
use App\Http\Controllers\Controller;
use App\Organization;
use App\Server;
use App\Support\Facades\Application as ApplicationFacade;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class Plans extends Controller
{
    public function index(Application $app)
    {
        $organization = auth()->user()->organization;
        $plans = AppPlan::where('archive', 0)
            ->where('application_id', $app->id)
            ->orderBy('display_order', 'asc')
            ->get();

        $archived = AppPlan::where('archive', 1)
            ->where('application_id', $app->id)
            ->get();

        return inertia()->render('Admin/Applications/Plans/PlansList', [
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
            ],
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
                    'label' => 'Apps',
                ],
                [
                    'label' => $app->name,
                    'url' => '/admin/apps/'.$app->slug,
                ],
                [
                    'label' => 'Plans',
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
        $settings = $plan->settings;

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
                'features' => json_encode($plan->features),
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
                'expires_after' => $plan->setting('expires_after'),
                'trial_for' => $plan->setting('trial_for'),
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

    public function store(Request $request, Application $app)
    {
        /* Validate */
        $validated = $request->validate([
            'name' => 'required',
            'description' => 'required',
        ]);

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
            'base' => [],
            'standard' => [],
            'basic' => [],
            'storage' => [],
            'application' => [],
        ];
        $plan->save();

        return redirect("/admin/apps/{$app->slug}/plans/{$plan->id}")->with('success', __('admin.applications.plans.added'));
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
        $settings = $plan->settings;

        $plan_features = ApplicationFacade::plan($plan)->features()->all();
        $profile = ApplicationFacade::profile($app);
        $settings['features'] = $plan_features;

        return inertia()->render('Admin/Applications/Plans/PlanEdit', [
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
            'shared_apps' => Organization::where('type', 'shared')->first()?->app_instances->map(function ($app) {
                return [
                    'id' => $app->id,
                    'name' => $app->label,
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
        /* Validate */
        $validateConfigurations = ApplicationFacade::validateConfigurations($app);
        $validated = $request->validate([
            'name' => 'required',
            'default' => 'nullable',
            'description' => 'required',
            'displayed_features' => 'nullable',
            'payment_enabled' => 'nullable',
            'admin_access' => 'nullable|bool',
            'base.price' => 'numeric|nullable',
            'base.price_id' => 'string|max:50|nullable',
            'base.storage' => 'numeric|nullable',
            'base.max' => 'numeric|nullable',
            'standard.price' => 'numeric|nullable',
            'standard.max' => 'numeric|nullable',
            'standard.price_id' => 'string|max:50|nullable',
            'standard.storage' => 'numeric|nullable',
            'basic.name' => 'string|nullable',
            'basic.price' => 'numeric|nullable',
            'basic.amount' => 'numeric|nullable',
            'basic.max' => 'numeric|nullable',
            'basic.price_id' => 'string|max:50|nullable',
            'basic.storage' => 'numeric|nullable',
            'storage.price' => 'numeric|nullable',
            'storage.max' => 'numeric|nullable',
            'storage.price_id' => 'string|max:50|nullable',
            'storage.amount' => 'numeric|nullable',
            'domain_enabled' => 'nullable',
            'domain_max' => 'numeric|nullable',
            'web_server' => 'numeric|nullable|exists:servers,id',
            'database_server' => 'numeric|nullable|exists:servers,id',
            'sso_server' => 'numeric|nullable|exists:servers,id',
            'expires_after' => 'nullable|numeric',
            'trial_for' => 'nullable|numeric',
            'shared_app' => 'numeric|nullable|exists:app_instances,id',
            'server_type' => 'required|in:separate,shared',
        ]);
        // Get bottom display order number
        $order_num = AppPlan::where('display_order', '>', 0)->orderBy('display_order', 'desc')->first();

        $plan->name = $request->name;
        $plan->description = $request->description;
        $plan->features = $request->displayed_features;
        $plan->payment_enabled = $request->payment_enabled;
        $plan->web_server_id = $request->web_server;
        $plan->database_server_id = $request->database_server;
        $plan->sso_server_id = $request->sso_server;
        $plan->shared_app_id = $request->shared_app;
        $plan->domain_enabled = $request->domain_enabled;
        $plan->domain_max = $request->domain_max;
        $plan->updateSettings([
            'server_type' => $request->input('server_type'),
            'admin_access' => $request->input('admin_access'),
            'base.price' => (int) $request->input('base.price'),
            'base.price_id' => $request->input('base.price_id'),
            'base.storage' => (int) $request->input('base.storage'),
            'base.max' => (int) $request->input('base.max'),
            'standard.price' => (int) $request->input('standard.price'),
            'standard.max' => (int) $request->input('standard.max'),
            'standard.price_id' => $request->input('standard.price_id'),
            'standard.storage' => (int) $request->input('standard.storage'),
            'basic.name' => $request->input('basic.name'),
            'basic.price' => (int) $request->input('basic.price'),
            'basic.amount' => (int) $request->input('basic.amount'),
            'basic.max' => (int) $request->input('basic.max'),
            'basic.price_id' => $request->input('basic.price_id'),
            'basic.storage' => (int) $request->input('basic.storage'),
            'storage.price' => (int) $request->input('storage.price'),
            'storage.max' => (int) $request->input('storage.max'),
            'storage.price_id' => $request->input('storage.price_id'),
            'storage.amount' => (int) $request->input('storage.amount'),
            'expires_after' => (int) $request->input('expires_after'),
            'trial_for' => (int) $request->input('trial_for'),
        ]);
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
            if ($plan) {
                $plan_name = $plan->name;
                $plan->delete();
            } else {
                $plan_name = '';
            }

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

    public function retrieve(Application $app)
    {
        return response()->json($app->plans->map(function ($plan) {
            return [
                'value' => $plan->id,
                'text' => $plan->name,
            ];
        }));
    }
}
