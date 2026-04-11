<?php

namespace App\Http\Controllers\Account;

use App\Actions\Apps\ApplicationActivate;
use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Http\Controllers\Controller;
use App\OrgDomain;
use App\OrgSubdomain;
use App\Rules\OrgAppInstance;
use App\Rules\OrgDomainName;
use App\Rules\OrgSuborganization;
use App\Support\Facades\Action;
use App\Support\Facades\Application as ApplicationFacade;
use App\Support\Facades\Billing;
use App\Support\Facades\Domain;
use App\Support\Facades\Organization;
use App\Support\Facades\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class Discover extends Controller
{
    public function index()
    {
        $this->authorize('active');
        $organization = Organization::account();
        $apps = Subscription::base()->enabledApps();

        return inertia('Organization/Discover/DiscoverApps', [
            'apps' => $apps->map(function ($app) use ($organization) {
                $is_installed = $app->is_installed($organization);

                return [
                    'name' => $app->name,
                    'slug' => $app->slug,
                    'description' => $app->short_description,
                    'category' => $app->category,
                    'installed' => $is_installed,
                    'count' => $organization->app_instances()->where('application_id', $app->id)->count(),
                ];
            }),
        ]);
    }

    public function show(Request $request, Application $app)
    {
        $this->authorize('view-app', $app);

        $organization = Organization::account();
        $version = AppVersion::where('application_id', $app->id)->orderBy('name', 'desc')->first();
        $parent_app = $app->parent_app;
        $is_installed = $app->is_installed($organization);
        $app_instance_count = $organization->app_instances()->where('application_id', $app->id)->count();

        $plans = ApplicationFacade::availablePlans($app, $organization);
        $plan = null;
        if (count($plans) == 1) {

            $plan = $plans->map(function ($plan) use ($app) {
                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'descriptions' => $plan->description,
                    'features' => $plan->displayFeatures(),
                    'url' => "/discover/{$app->slug}/plans/{$plan->id}/review",
                ];
            })->first();
        }

        $can_activate = Gate::inspect('activate-app', $app);

        return inertia('Organization/Discover/DiscoverApp', [
            'organization' => [
                'id' => $organization->id,
            ],
            'app' => [
                'name' => $app->name,
                'slug' => $app->slug,
                'description' => $app->description,
                'plan_count' => count($plans),
                'plan_id' => $plan ? $plan['id'] : null,
                'activated' => $is_installed,
                'count' => $app_instance_count,
                'parent_app' => $parent_app ? [
                    'name' => $parent_app->name,
                    'slug' => $parent_app->slug,
                ] : null,
            ],
            'plan' => $plan,
            'can' => [
                'activate' => $can_activate->allowed(),
            ],
            'authorization' => [
                'deny' => [
                    'message' => $can_activate->message(),
                    'code' => $can_activate->code(),
                ],
            ],
        ]);
    }

    public function plans(Application $app)
    {
        $this->authorize('activate-app', $app);

        $organization = Organization::account();
        $plans = ApplicationFacade::availablePlans($app, $organization, display_order: true);
        $plan_count = $plans->count();

        if ($plan_count == 0) {
            return redirect('/discover');
        } elseif ($plan_count == 1) {
            return redirect("/discover/{$app->slug}/plans/{$plans->first()->id}/review");
        }

        return inertia('Organization/Discover/DiscoverPlans', [
            'app' => [
                'slug' => $app->slug,
            ],
            'plans' => $plans->map(function ($plan) use ($app) {
                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'features' => $plan->displayFeatures(),
                    'url' => "/discover/{$app->slug}/plans/{$plan->id}/review",
                    'can' => [
                        'select' => Gate::allows('select-app-plan', $plan),
                    ],
                ];
            }),
        ]);
    }

    public function review(Application $app, AppPlan $plan)
    {
        $this->authorize('activate-app', $app);
        $this->authorize('select-app-plan', $plan);

        $organization = Organization::account();

        $available_parent_apps = ApplicationFacade::availableParents($app);
        $customizations = ApplicationFacade::plan($plan)->features()->optional();

        $other_apps = $app->instances()->where('organization_id', $organization->id)->count();

        // Create domain list
        $domains = Organization::availableDomains($app)->map(function ($domain) {
            return [
                'text' => $domain->name,
                'value' => $domain->id,
            ];
        });

        $number_of_domains = count($domains);

        if (in_array($app->domain_option, ['all', 'base'])) {
            $domains->push([
                'text' => __('labels.system_provided_domain'),
                'value' => 'base',
            ]);
        }

        if ($organization->domains()->active()->primary()->count() > 0 && in_array($app->domain_option, ['all', 'subdomains'])) {
            $domains->push([
                'text' => __('labels.add_subdomain'),
                'value' => 'new',
            ]);
        }

        if ($app->domain_option === 'parent') {
            $domains->push([
                'text' => __('labels.parent_app_domain'),
                'value' => 'parent',
            ]);
        }

        if ($plan->setting('server_type') === 'shared') {
            $domains->push([
                'text' => __('labels.parent_app_domain'),
                'value' => 'parent',
            ]);
        }

        $settings = ApplicationFacade::personalizedConfigurations($app, $plan)->values();

        return inertia('Organization/Discover/DiscoverOverview', [
            'organization' => [
                'id' => $organization->id,
            ],
            'customizations' => collect($customizations)->map(function ($customization) {
                return [
                    'name' => $customization['name'],
                    'label' => $customization['label'],
                    'description' => $customization['description'],
                    'price' => Arr::get($customization, 'price') ? '$'.$customization['price'] : null,
                ];
            }),
            'plan' => [
                'id' => $plan->id,
                'payment_enabled' => $plan->payment_enabled,
                'name' => $plan->name,
                'descriptions' => $plan->description,
                'features' => $plan->displayFeatures(),
                'url' => "/discover/{$app->slug}/plans/{$plan->id}/review",
            ],
            'app' => [
                'name' => $app->name,
                'slug' => $app->slug,
                'parent_app' => $app->parent_app ? [
                    'name' => $app->parent_app->name,
                ] : null,
            ],
            'parent_apps' => $available_parent_apps->map(function ($app) {
                return [
                    'text' => $app->label,
                    'value' => $app->id,
                ];
            }),
            'domains' => $domains,
            'organizations' => collect([['id' => $organization->id, 'name' => $organization->name]])->merge($organization->suborganizations()->notDeactivated()->get()->map(function ($sub) {
                return [
                    'id' => $sub->id,
                    'name' => $sub->name,
                ];
            })),
            'parent_domains' => $organization->main_domains()->map(function ($domain) {
                return [
                    'value' => $domain->id,
                    'text' => $domain->name,
                ];
            }),
            'default_label' => $other_apps > 0 ? $app->name.' '.$other_apps + 1 : $app->name,
            'has_payment_method' => Billing::hasDefaultPaymentMethod(),
            'settings' => $settings,
        ]);
    }

    public function activate(Request $request, Application $app, AppPlan $plan)
    {
        $this->authorize('activate-app', $app);
        $this->authorize('select-app-plan', $plan);

        $organization = Organization::account();

        if ($plan->payment_enabled && ! Billing::hasDefaultPaymentMethod()) {
            return back()->with('error', __('organization.app.denied.payment_method'));
        }

        $validatedData = $request->validate([
            'organization' => $organization->suborganizations->count() > 0 ? ['required', 'numeric', new OrgSuborganization] : 'nullable',
            'parent_app' => ['nullable', new OrgAppInstance($organization)],
            'customizations' => 'nullable',
            'label' => 'required|string|max:100',
            'domain' => [
                'required',
                function (string $attribute, mixed $value, \Closure $fail) use ($plan) {
                    if (! $value || in_array($value, ['new', 'default', 'parent', 'base'])) {
                        return true;
                    }

                    $subdomain = OrgSubdomain::find($value);

                    if (! $subdomain) {
                        $fail(__('organization.domain.denied.exists'));

                        return;
                    }

                    if (Gate::allows('domain-direct-to-web-server', [$subdomain, $plan->web_server])) {
                        return true;
                    }

                    $fail(__('organization.domain.denied.ip', ['domain' => $subdomain->name, 'ip' => $plan->web_server->ip]));
                },
            ],
            'subdomain' => 'required_if:domain,new|nullable|string|max:100',
            'parent_domain' => [
                'required_if:domain,new',
                'nullable',
                new OrgDomainName,
            ],
        ]);

        // Check if parent_domain was set

        $subdomain = null;
        if ($validatedData['domain'] === 'new' && Arr::get($validatedData, 'parent_domain')) {
            $parent_domain = OrgDomain::find($validatedData['parent_domain']);
            $domain_name = implode('.', [$validatedData['subdomain'], $parent_domain->name]);

            // Check that domain with subdomain doesn't already exist. Otherwise it will create a "Integrity constraint violation" in MySQL
            if (! $subdomain = OrgSubdomain::where('name', $domain_name)->where('organization_id', $organization->id)->first()) {
                $subdomain = Domain::addSubdomain($validatedData['subdomain'], $parent_domain);
            }
        } elseif (in_array($validatedData['domain'], ['base', 'parent'])) { // Else use selected domain
            $subdomain = OrgSubdomain::find($validatedData['domain']);
        }

        $organization = Arr::get($validatedData, 'organization', null) ? \App\Organization::find($validatedData['organization']) : $organization;

        $parent_app = null;
        if ($app->parent_app || $plan->setting('parent_server_id')) {
            if ($request->parent_app) {
                $parent_app = AppInstance::find($request->parent_app);
            } elseif ($parent_server_id = $plan->setting('parent_server_id')) {
                $parent_app = AppInstance::find($parent_server_id);
            } else {
                $available_parent_apps = ApplicationFacade::availableParents($app);
                if ($available_parent_apps->count() == 1) {
                    $parent_app = $available_parent_apps->first();
                } else {
                    return redirect("/discover/{$app->slug}/plans/{$plan->id}/review")->with('error', __('organization.app.denied.select_parent_app', ['parent_app' => $app->parent_app->name]));
                }
            }
        }

        $activate_app = Action::execute(new ApplicationActivate($organization, $app, $plan, $request->input('customizations'), $parent_app, label: $validatedData['label'], domain: $subdomain, configurations: $request->configurations));

        if ($organization->setting('step') === 1) {
            $organization->updateSetting('step', 2);
            $organization->save();

            return redirect('/users')->with('success', __('organization.app.activating', ['app' => $validatedData['label']]));
        }

        return redirect('/apps')->with('success', __('organization.app.activating', ['app' => $validatedData['label']]));
    }
}
