<?php

namespace App\Http\Controllers\Admin;

use App\Application;
use App\Http\Controllers\Controller;
use App\Plan;
use App\Server;
use App\Support\Facades\Settings as SettingsFacade;
use App\Support\Organizations;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class Plans extends Controller
{
    public function index()
    {
        $organization = auth()->user()->organization;
        $plans = Plan::where('archive', 0)->orderBy('display_order', 'asc')->get();
        $archived = Plan::where('archive', 1)->get();

        return inertia()->render('Admin/Plans/PlansList', [
            'plans' => $plans->map(function ($plan) {
                $org_types = Organizations::types();

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'active_subscribers' => $plan->subscribers()->count(),
                    'is_default' => $plan->is_default,
                    'org_type' => $plan->org_type ? $org_types[$plan->org_type] : '',
                    'type' => $plan->type,
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
                    'label' => __('admin.plans.plans'),
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        /* Validate */
        $validated = $request->validate([
            'name' => 'required',
            'description' => 'required',
            'type' => 'required|in:package,app',
        ]);

        // Get bottom display order number
        $order_num = Plan::where('display_order', '>', 0)->orderBy('display_order', 'desc')->first();
        $display_order = $order_num ? $order_num->display_order : 0;
        $apps = Application::all();
        $app_plans = [];
        foreach ($apps as $app) {
            $app_plans[$app->slug] = [];
        }

        $plan = new Plan;
        $plan->name = $request->name;
        $plan->description = $request->description;
        $plan->type = $request->type;
        $plan->app_plans = [];
        $plan->display_order = $display_order + 1;
        $plan->settings = [
            'suborganizations' => [],
            'base' => [],
            'standard' => [],
            'basic' => [],
            'storage' => [],
            'email' => [],
            'application' => [],
            'domains' => [],
        ];
        $plan->org_type = 'none';
        $plan->app_plans = $app_plans;
        $plan->status = 'hidden';
        $plan->save();

        return redirect('/admin/service/plans/'.$plan->id)->with('success', __('admin.plans.added', ['plan' => $plan->name]));
    }

    public function edit(Plan $plan)
    {
        $apps = Application::all();

        // Checks if there are active subscribers of this plan
        $subscribers = $plan->subscribers()->count() == 0 ? false : true;
        $email_servers = Server::where('type', 'email')->get();

        $app_plans = [];
        foreach ($apps as $app) {
            $plans = Arr::get($plan->app_plans, "{$app->slug}.plans");
            $app_plans[$app->slug] = [
                'max' => Arr::get($plan->app_plans, "{$app->slug}.max") ?? 0,
                'plans' => is_array($plans) ? ($plans[0] ?? null) : null,
            ];
        }

        $enabledCurrencies = json_decode(SettingsFacade::get('enabled_currencies', '["USD"]'), true) ?: ['USD'];

        return inertia()->render('Admin/Plans/PlanEdit', [
            'enabled_currencies' => $enabledCurrencies,
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'is_default' => $plan->is_default,
                'features' => $plan->features ?? [],
                'type' => $plan->type,
                'description' => $plan->description,
                'payment_enabled' => $plan->payment_enabled,
                'domain_enabled' => $plan->domain_enabled,
                'domain_max' => $plan->domain_max,
                'email_enabled' => $plan->email_enabled,
                'email_server' => $plan->email_server ? $plan->email_server->id : null,
                'settings' => $plan->settings ?? [],
                'app_plans' => $app_plans,
                'archived' => $plan->archive,
                'org_type' => $plan->org_type,
                'domains' => [
                    'connect' => $plan->setting('domains.connect'),
                    'register' => $plan->setting('domains.register'),
                    'transfer' => $plan->setting('domains.transfer'),
                ],
            ],
            'apps' => $apps->map(function (\App\Application $app) {
                $plans = [];
                foreach ($app->plans as $plan) {
                    $plans[] = [
                        'id' => $plan->id,
                        'name' => $plan->name,
                    ];
                }

                return [
                    'id' => $app->id,
                    'name' => $app->name,
                    'slug' => $app->slug,
                    'plans' => $plans,
                ];
            }),
            'org_types' => collect(Organizations::types())->map(function ($label, $name) {
                return [
                    'value' => $name,
                    'name' => $label,
                ];
            })->values(),
            'email_servers' => $email_servers->map(function ($server) {
                return [
                    'value' => $server->id,
                    'text' => $server->name." ({$server->status})",
                ];
            }),
            'control_panel' => [
                'can' => [
                    'register_domains' => config('domains.default') !== null,
                ],
            ],
            'breadcrumbs' => [
                [
                    'url' => '/admin/service/plans',
                    'label' => __('admin.plans.plans'),
                ],
                [
                    'label' => $plan->name,
                ],
            ],
        ]);
    }

    public function update(Request $request, $plan_id)
    {

        $enabledCurrencies = json_decode(SettingsFacade::get('enabled_currencies', '["USD"]'), true) ?: ['USD'];
        $components = ['base', 'standard', 'basic', 'storage', 'application', 'email'];

        $currencyRules = [];
        foreach ($enabledCurrencies as $currency) {
            foreach ($components as $component) {
                $currencyRules["prices.{$component}.{$currency}.amount"] = 'numeric|nullable';
                $currencyRules["prices.{$component}.{$currency}.price_id"] = 'string|max:50|nullable';
            }
        }

        /* Validate */
        $validated = $request->validate(array_merge([
            'name' => 'required',
            'status' => 'nullable',
            'default' => 'nullable',
            'type' => 'required|in:package,app',
            'description' => 'required',
            'org_type' => 'required|string|in:nonprofit,business,none,superaccount',
            'displayed_features' => 'nullable',
            'payment_enabled' => 'nullable',
            'base.minimal_label' => 'nullable|string|max:100',
            'standard.max' => 'numeric|nullable',
            'standard.storage' => 'numeric|nullable',
            'basic.name' => 'nullable|string',
            'basic.amount' => 'numeric|nullable',
            'basic.max' => 'numeric|nullable',
            'basic.storage' => 'numeric|nullable',
            'storage.max' => 'numeric|nullable',
            'storage.amount' => 'numeric|nullable',
            'application.max' => 'numeric|nullable',
            'email.max' => 'numeric|nullable',
            'email.storage' => 'numeric|nullable',
            'app_plans' => 'array|nullable',
            'domains.connect' => 'boolean|nullable',
            'domains.register' => 'boolean|nullable',
            'domains.transfer' => 'boolean|nullable',
            'domain_enabled' => 'boolean',
            'suborganizations.enabled' => 'boolean',
            'email_enabled' => 'boolean',
            'domain_max' => 'numeric|nullable',
            'email_server' => 'required_if_accepted:email_enabled|numeric|nullable|exists:servers,id',
        ], $currencyRules));
        // Get bottom display order number
        $order_num = Plan::where('display_order', '>', 0)->orderBy('display_order', 'desc')->first();

        $plan = Plan::where('id', $plan_id)->first();
        $plan->name = $request->name;
        $plan->description = $request->description;
        $plan->org_type = $request->org_type;
        $plan->type = $request->type;
        $plan->features = $request->displayed_features;
        $plan->payment_enabled = $request->boolean('payment_enabled');
        $plan->email_server_id = $request->email_server;
        $rawAppPlans = $request->app_plans ?? [];
        foreach ($rawAppPlans as $slug => &$config) {
            if (array_key_exists('plans', $config) && ! is_array($config['plans'])) {
                $config['plans'] = $config['plans'] !== null ? [$config['plans']] : [];
            }
        }
        unset($config);
        $plan->app_plans = $rawAppPlans;
        $plan->domain_enabled = $request->boolean('domain_enabled');
        $plan->email_enabled = $request->boolean('email_enabled');
        $plan->domain_max = $request->domain_max;
        $isAppType = $request->type === 'app';

        $settingsToUpdate = [
            'suborganizations.enabled' => $request->input('suborganizations.enabled'),
            'base.minimal_label' => $request->input('base.minimal_label'),
            'standard.max' => (int) $isAppType ? null : $request->input('standard.max'),
            'standard.storage' => (int) $isAppType ? null : $request->input('standard.storage'),
            'basic.name' => $isAppType ? null : $request->input('basic.name'),
            'basic.amount' => (int) $isAppType ? null : $request->input('basic.amount'),
            'basic.max' => (int) $isAppType ? null : $request->input('basic.max'),
            'basic.storage' => (int) $isAppType ? null : $request->input('basic.storage'),
            'application.max' => (int) $isAppType ? null : $request->input('application.max'),
            'storage.max' => (int) $isAppType ? null : $request->input('storage.max'),
            'storage.amount' => (int) $isAppType ? null : $request->input('storage.amount'),
            'email.max' => (int) $request->input('email.max'),
            'email.storage' => (int) $request->input('email.storage'),
            'domains.connect' => $request->input('domains.connect'),
            'domains.register' => $request->input('domains.register'),
            'domains.transfer' => $request->input('domains.transfer'),
        ];

        foreach ($enabledCurrencies as $currency) {
            foreach ($components as $component) {
                if ($isAppType && in_array($component, ['base', 'standard', 'basic', 'storage', 'application'])) {
                    $settingsToUpdate["{$component}.prices.{$currency}.amount"] = null;
                    $settingsToUpdate["{$component}.prices.{$currency}.price_id"] = null;
                } else {
                    $settingsToUpdate["{$component}.prices.{$currency}.amount"] = $request->input("prices.{$component}.{$currency}.amount");
                    $settingsToUpdate["{$component}.prices.{$currency}.price_id"] = $request->input("prices.{$component}.{$currency}.price_id");
                }
            }
        }

        $plan->updateSettings($settingsToUpdate);
        $plan->status = $request->status ? 'available' : 'hidden';
        if (Arr::get($validated, 'default') && (! $plan->is_default || $plan->isDirty('org_type'))) {
            // Replace old default with new one
            $current_default_plan = Plan::where('is_default', true)->where('org_type', $plan->org_type)->first();
            if ($current_default_plan) {
                $current_default_plan->is_default = false;
                $current_default_plan->save();
            }

            $plan->is_default = true;
        } elseif (! Arr::get($validated, 'default') && $plan->is_default) {
            $plan->is_default = false;
        }

        $plan->save();

        Cache::flush();

        return redirect('/admin/service/plans')->with('success', __('admin.plans.updated', ['plan' => $plan->name]));
    }

    public function remove($plan_id)
    {
        $plan = Plan::where('id', $plan_id)->first();

        if ($plan->subscribers->count() == 0) {
            if ($plan) {
                $plan_name = $plan->name;
                $plan->delete();
            } else {
                $plan_name = '';
            }

            return redirect('/admin/service/plans')->with('success', __('admin.plans.deleted', ['plan' => $plan->name]));
        }

        return redirect('/admin/service/plans')->with('error', "Plan can't be deleted as organizations are currently subscribed to it. Please consider archiving for now.");

    }

    public function updateOrder(Request $request)
    {
        /* Validate */
        $validated = $request->validate([
            'plans' => 'array|nullable',
        ]);

        $n = 1;
        foreach ($validated['plans'] as $plan) {
            $plan = Plan::find($plan['id']);
            $plan->display_order = $n;
            $plan->save();

            $n++;
        }

        return redirect('/admin/service/plans')->with('success', __('admin.plans.order_updated'));
    }

    public function archive(Plan $plan)
    {
        $plan->archive = true;
        $plan->save();

        return redirect('/admin/service/plans')->with('success', __('admin.plans.archived', ['plan' => $plan->name]));
    }

    public function unarchive(Plan $plan)
    {
        $plan->archive = false;
        $plan->save();

        return redirect('/admin/service/plans')->with('success', __('admin.plans.unarchived', ['plan' => $plan->name]));
    }
}
