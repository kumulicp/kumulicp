<?php

namespace App\Http\Controllers\Admin;

use App\Application;
use App\Http\Controllers\Controller;
use App\Plan;
use App\Server;
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
                    'label' => 'Plans',
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
        ]);

        // Get bottom display order number
        $order_num = Plan::where('display_order', '>', 0)->orderBy('display_order', 'desc')->first();
        $display_order = $order_num ? $order_num->display_order : 0;
        $apps = Application::all();
        foreach ($apps as $app) {
            $app_plans[$app->slug] = [];
        }

        $plan = new Plan;
        $plan->name = $request->name;
        $plan->description = $request->description;
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
        $plan->app_plans = $app_plans;
        $plan->status = 'hidden';
        $plan->save();

        return redirect('/admin/service/plans/'.$plan->id)->with('success', 'Plan added!');
    }

    public function edit(Plan $plan)
    {
        $apps = Application::all();

        // Checks if there are active subscribers of this plan
        $subscribers = $plan->subscribers()->count() == 0 ? false : true;
        $email_servers = Server::where('type', 'email')->get();

        $app_plans = [];
        foreach ($apps as $app) {
            $app_plans[$app->slug] = [
                'max' => Arr::get($plan->app_plans, "{$app->slug}.max") ?? 0,
                'plans' => Arr::get($plan->app_plans, "{$app->slug}.plans"),
            ];
        }

        return inertia()->render('Admin/Plans/PlanEdit', [
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
                'web_server' => $plan->web_server ? $plan->web_server->id : null,
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
            'apps' => $apps->map(function ($app) use ($plan) {
                return [
                    'id' => $app->id,
                    'name' => $app->name,
                    'slug' => $app->slug,
                    'plans' => $app->plans->map(function ($plan) {
                        return [
                            'id' => $plan->id,
                            'name' => $plan->name,
                        ];
                    }),
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
                    'label' => 'Plans',
                ],
                [
                    'label' => $plan->name,
                ],
            ],
        ]);
    }

    public function update(Request $request, $plan_id)
    {

        /* Validate */
        $validated = $request->validate([
            'name' => 'required',
            'status' => 'nullable',
            'default' => 'nullable',
            'type' => 'required|in:package,app',
            'description' => 'required',
            'org_type' => 'required|string|in:nonprofit,business,none',
            'displayed_features' => 'nullable',
            'payment_enabled' => 'nullable',
            'base.price' => 'numeric|nullable',
            'base.price_id' => 'string|max:50|nullable',
            'base.minimal_label' => 'nullable|string|max:100',
            'standard.price' => 'numeric|nullable',
            'standard.max' => 'numeric|nullable',
            'standard.price_id' => 'string|max:50|nullable',
            'standard.storage' => 'numeric|nullable',
            'basic.name' => 'nullable|string',
            'basic.price' => 'numeric|nullable',
            'basic.amount' => 'numeric|nullable',
            'basic.max' => 'numeric|nullable',
            'basic.price_id' => 'string|max:50|nullable',
            'basic.storage' => 'numeric|nullable',
            'storage.price' => 'numeric|nullable',
            'storage.max' => 'numeric|nullable',
            'storage.price_id' => 'string|max:50|nullable',
            'storage.amount' => 'numeric|nullable',
            'application.price' => 'numeric|nullable',
            'application.price_id' => 'string|max:50|nullable',
            'application.max' => 'numeric|nullable',
            'email.price' => 'numeric|nullable',
            'email.max' => 'numeric|nullable',
            'email.price_id' => 'string|max:50|nullable',
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
        ]);
        // Get bottom display order number
        $order_num = Plan::where('display_order', '>', 0)->orderBy('display_order', 'desc')->first();

        $plan = Plan::where('id', $plan_id)->first();
        $plan->name = $request->name;
        $plan->description = $request->description;
        $plan->org_type = $request->org_type;
        $plan->type = $request->type;
        $plan->features = $request->displayed_features;
        $plan->payment_enabled = $request->payment_enabled;
        $plan->email_server_id = $request->email_server;
        $plan->app_plans = $request->app_plans;
        $plan->domain_enabled = $request->domain_enabled;
        $plan->email_enabled = $request->email_enabled;
        $plan->domain_max = $request->domain_max;
        $plan->updateSettings([
            'suborganizations.enabled' => $request->input('suborganizations.enabled'),
            'base.price' => $request->input('base.price'),
            'base.price_id' => $request->input('base.price_id'),
            'base.minimal_label' => $request->input('base.minimal_label'),
            'standard.price' => $request->input('standard.price'),
            'standard.max' => $request->input('standard.max'),
            'standard.price_id' => $request->input('standard.price_id'),
            'standard.storage' => $request->input('standard.storage'),
            'basic.name' => $request->input('basic.name'),
            'basic.price' => $request->input('basic.price'),
            'basic.amount' => $request->input('basic.amount'),
            'basic.max' => $request->input('basic.max'),
            'basic.price_id' => $request->input('basic.price_id'),
            'basic.storage' => $request->input('basic.storage'),
            'application.price' => $request->input('application.price'),
            'application.max' => $request->input('application.max'),
            'application.price_id' => $request->input('application.price_id'),
            'email.price' => $request->input('email.price'),
            'email.max' => $request->input('email.max'),
            'email.price_id' => $request->input('email.price_id'),
            'email.storage' => $request->input('email.storage'),
            'storage.price' => $request->input('storage.price'),
            'storage.max' => $request->input('storage.max'),
            'storage.price_id' => $request->input('storage.price_id'),
            'storage.amount' => $request->input('storage.amount'),
            'domains.connect' => $request->input('domains.connect'),
            'domains.register' => $request->input('domains.register'),
            'domains.transfer' => $request->input('domains.transfer'),
        ]);
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

        return redirect('/admin/service/plans')->with('success', 'Plan: '.$plan->name.' updated!');
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

            return redirect('/admin/service/plans')->with('success', 'Plan: '.$plan_name.' was deleted.');
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

        return redirect('/admin/service/plans')->with('success', 'Plan order updated!');
    }

    public function archive(Plan $plan)
    {
        $plan->archive = true;
        $plan->save();

        return redirect('/admin/service/plans')->with('success', 'Plan: '.$plan->name.' was archived!');
    }

    public function unarchive(Plan $plan)
    {
        $plan->archive = false;
        $plan->save();

        return redirect('/admin/service/plans')->with('success', 'Plan: '.$plan->name.' is back on display!');
    }
}
