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
use App\Rules\HostLabel;
use App\Rules\OrgAppInstance;
use App\Rules\OrgDomainName;
use App\Rules\OrgSuborganization;
use App\Services\Application\ApplicationReadiness;
use App\Services\Organization\AppActivationContext;
use App\Support\Facades\Action;
use App\Support\Facades\Application as ApplicationFacade;
use App\Support\Facades\Billing;
use App\Support\Facades\Domain;
use App\Support\Facades\Organization;
use App\Support\Facades\Subscription;
use App\Support\Facades\Tenancy;
use Illuminate\Auth\Access\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class Discover extends Controller
{
    public function index()
    {
        Gate::authorize('active');
        $organization = Organization::account();
        $single = Tenancy::isSingle();
        $is_admin = Gate::allows('admin');

        // A single-organization install lists every app so an admin can see what still needs
        // setting up; everyone else only sees the ones that can be installed
        $apps = $single ? Application::orderBy('slug')->get() : Subscription::base()->enabledApps();

        $readiness = $single ? $apps->mapWithKeys(fn ($app) => [$app->id => new ApplicationReadiness($app)]) : collect();

        return inertia('Organization/Discover/DiscoverApps', [
            'apps' => $apps
                ->filter(fn ($app) => ! $single || $is_admin || $readiness[$app->id]->isReady())
                ->map(fn ($app) => [
                    'name' => $app->name,
                    'slug' => $app->slug,
                    'description' => $app->short_description,
                    'category' => $app->category,
                    'installed' => $app->is_installed($organization),
                    'count' => $organization->app_instances()->where('application_id', $app->id)->count(),
                    'readiness' => $single && $is_admin ? [
                        'status' => $readiness[$app->id]->status(),
                        'issues' => count($readiness[$app->id]->issues()),
                    ] : null,
                ])
                ->values(),
            'can' => [
                'manage' => $is_admin,
            ],
        ]);
    }

    public function show(Request $request, Application $app)
    {
        Gate::authorize('view-app', $app);

        $organization = Organization::account();
        $is_admin = Gate::allows('admin');
        $readiness = Tenancy::isSingle() ? new ApplicationReadiness($app) : null;

        // An app that isn't ready only exists for the admin who has to set it up
        abort_if($readiness && ! $is_admin && ! $readiness->isReady(), 404);

        $version = AppVersion::where('application_id', $app->id)->orderBy('name', 'desc')->first();
        $is_installed = $app->is_installed($organization);
        $app_instance_count = $organization->app_instances()->where('application_id', $app->id)->count();

        // The plans on offer are the (sub)organization's own, so which organization the app is for is chosen first.
        // When more than one can take the app the user picks in a modal; until then, the first that can.
        $target = $this->chosenTarget($request) ?? $this->defaultTarget($app);
        $organizations = $this->organizationOptions($app);

        $plans = ApplicationFacade::availablePlans($app, $target);
        $plan = null;
        if (count($plans) == 1) {

            $plan = $plans->map(function ($plan) use ($app, $target) {
                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'descriptions' => $plan->description,
                    'features' => $plan->displayFeatures(),
                    'url' => $this->withTarget("/discover/{$app->slug}/plans/{$plan->id}/review", $target),
                ];
            })->first();
        }

        $can_activate = Gate::inspect('activate-app', $app);

        // The app the page asks for first: the one the plans the organization can pick need, when it is missing
        $parent_app = $plans->map->parentApplication()->filter()->first();

        return inertia('Organization/Discover/DiscoverApp', [
            'organization' => [
                'id' => $organization->id,
            ],
            'app' => [
                'name' => $app->name,
                'slug' => $app->slug,
                'description' => $app->description,
                'screenshots' => $app->screenshots->map(function ($screenshot) {
                    return [
                        'id' => $screenshot->id,
                        'url' => $screenshot->url,
                    ];
                }),
                'plan_count' => count($plans),
                'plan_id' => $plan ? $plan['id'] : null,
                'plans_url' => $this->withTarget("/discover/{$app->slug}/plans", $target),
                'activated' => $is_installed,
                'count' => $app_instance_count,
                'parent_app' => $parent_app ? [
                    'name' => $parent_app->name,
                    'slug' => $parent_app->slug,
                ] : null,
            ],
            'plan' => $plan,
            // Where there is a choice of organization to activate for, the user is asked which when they go on
            'organizations' => $organizations,
            // Which organization the app goes to when there is no choice, so it is clear when there are suborganizations
            'target' => $this->organizationSummary($target),
            'readiness' => $readiness && $is_admin ? [
                'status' => $readiness->status(),
                'items' => $readiness->issues(),
            ] : null,
            'can' => [
                'activate' => $can_activate->allowed(),
                'choose_organization' => $organizations->where('disabled', false)->count() > 1,
                'has_suborganizations' => $organizations->isNotEmpty(),
                'manage' => $is_admin,
            ],
            'authorization' => [
                'deny' => [
                    'message' => $can_activate->message(),
                    'code' => $can_activate->code(),
                ],
            ],
        ]);
    }

    public function plans(Request $request, Application $app)
    {
        $target = $this->chosenTarget($request) ?? $this->defaultTarget($app);

        Gate::authorize('activate-app', [$app, null, $target]);

        $plans = ApplicationFacade::availablePlans($app, $target, display_order: true);
        $plan_count = $plans->count();

        if ($plan_count == 0) {
            return redirect('/discover');
        } elseif ($plan_count == 1) {
            return redirect($this->withTarget("/discover/{$app->slug}/plans/{$plans->first()->id}/review", $target));
        }

        return inertia('Organization/Discover/DiscoverPlans', [
            'app' => [
                'name' => $app->name,
                'slug' => $app->slug,
            ],
            // Plans are the organization's own, which only needs saying when there is more than one it could be
            'organization' => $this->organizationSummary($target),
            'has_suborganizations' => AppActivationContext::organizationsFor(auth()->user())->count() > 1,
            'plans' => $plans->map(function ($plan) use ($app, $target) {
                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'features' => $plan->displayFeatures(),
                    'url' => $this->withTarget("/discover/{$app->slug}/plans/{$plan->id}/review", $target),
                    'can' => [
                        'select' => Gate::allows('select-app-plan', [$plan, $target]),
                    ],
                ];
            }),
        ]);
    }

    public function review(Request $request, Application $app, AppPlan $plan)
    {
        $target = $this->chosenTarget($request) ?? $this->defaultTarget($app, $plan);

        Gate::authorize('activate-app', [$app, $plan, $target]);
        Gate::authorize('select-app-plan', [$plan, $target]);

        $organization = Organization::account();

        $available_parent_apps = ApplicationFacade::availableParents($plan);
        $parent_application = $plan->parentApplication();
        $customizations = ApplicationFacade::plan($plan)->features()->optional();

        $other_apps = $app->instances()->where('organization_id', $target->id)->count();

        // Create domain list
        $domains = Organization::availableDomains($plan)->map(function ($domain) {
            return [
                'text' => $domain->name,
                'value' => $domain->id,
            ];
        });

        $number_of_domains = count($domains);

        // A shared-plan child whose app opts into parent-domain behavior
        // (AppPlan::hasDomainOption('parent') -- e.g. Nextcloud/
        // B1Church) always resolves to the hub's domain regardless of
        // anything picked here (see AppInstance::domain()), so 'parent' is
        // the only sensible choice -- offering base/subdomains alongside it
        // would just be a domain pick that silently gets ignored. An app
        // that doesn't opt in (e.g. ERPNext, which supports any domain even
        // when shared) keeps its normal single-mode options instead.
        if ($this->sharedParentOnlyDomain($app, $plan)) {
            $domains->push([
                'text' => __('labels.parent_app_domain'),
                'value' => 'parent',
            ]);
        } else {
            if ($plan->hasDomainOption('base')) {
                $domains->push([
                    'text' => __('labels.system_provided_domain'),
                    'value' => 'base',
                ]);
            }

            if ($organization->domains()->active()->primary()->count() > 0 && $plan->hasDomainOption('subdomains')) {
                $domains->push([
                    'text' => __('labels.add_subdomain'),
                    'value' => 'new',
                ]);
            }

            // Same-org "plan-level parent app" dependency (AppPlan::parentApplication())
            // -- unrelated to cross-org shared plans above, still offered
            // independently of server_type.
            if ($parent_application && $plan->hasDomainOption('parent')) {
                $domains->push([
                    'text' => __('labels.parent_app_domain'),
                    'value' => 'parent',
                ]);
            }
        }

        $settings = ApplicationFacade::personalizedConfigurations($app, $plan)->values();

        return inertia('Organization/Discover/DiscoverOverview', [
            // The organization the app is being activated for, which was chosen before the plans were listed
            'organization' => [
                'id' => $target->id,
                'name' => $target->name,
                'is_suborganization' => (bool) $target->parent_organization_id,
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
                'url' => $this->withTarget("/discover/{$app->slug}/plans/{$plan->id}/review", $target),
            ],
            'app' => [
                'name' => $app->name,
                'slug' => $app->slug,
                'parent_app' => $parent_application ? [
                    'name' => $parent_application->name,
                ] : null,
            ],
            'parent_apps' => $available_parent_apps->map(function ($app) {
                return [
                    'text' => $app->label,
                    'value' => $app->id,
                ];
            }),
            'domains' => $domains,
            'parent_domains' => $organization->main_domains()->map(function ($domain) {
                return [
                    'value' => $domain->id,
                    'text' => $domain->name,
                ];
            }),
            'driver' => Billing::component(),
            'default_label' => $other_apps > 0 ? $app->name.' '.$other_apps + 1 : $app->name,
            // A suborganization's user whose parent pays isn't asked for a payment method: it's the parent's
            // that is used, and activating checks it
            'has_payment_method' => ! (new AppActivationContext(auth()->user(), $target))->payerIsUsersOrganization() || Billing::hasDefaultPaymentMethod(),
            'settings' => $settings,
        ]);
    }

    public function activate(Request $request, Application $app, AppPlan $plan)
    {
        $organization = Organization::account();

        // What is activated, who may do it, what limits apply and who pays all depend on who it is for
        $target = $this->activationTarget($request, $organization);

        $context = new AppActivationContext($request->user(), $target);

        // An organization the screen offers but that can't take the app (it has reached its limit) is an
        // error on that field. Anything else is a request the screen never makes.
        $denial = $this->activationDenial($app, $plan, $target);
        if ($denial && $request->filled('organization') && $context->authorize()->allowed()) {
            throw ValidationException::withMessages(['organization' => $this->denialMessage($denial, $app)]);
        }
        $denial?->authorize();

        if ($plan->payment_enabled && ! $context->payerCanPay()) {
            return back()->with('error', $context->payerIsUsersOrganization()
                ? __('organization.app.denied.payment_method')
                : __('organization.app.denied.parent_payment_method'));
        }

        $validatedData = $request->validate([
            'organization' => $organization->suborganizations->count() > 0 ? ['required', 'numeric', new OrgSuborganization] : 'nullable',
            'parent_app' => ['nullable', new OrgAppInstance($organization)],
            'customizations' => 'nullable',
            'label' => 'required|string|max:100',
            'domain' => [
                'required',
                function (string $attribute, mixed $value, \Closure $fail) use ($plan, $app) {
                    // Mirrors review()'s domain-option list via the same
                    // helper, so what's accepted here can't drift from what
                    // the review screen actually offered.
                    $shared_parent_only = $this->sharedParentOnlyDomain($app, $plan);

                    if ($value === 'base') {
                        if ($shared_parent_only || ! $plan->hasDomainOption('base')) {
                            $fail(__('organization.domain.denied.type'));
                        }

                        return;
                    }

                    if ($value === 'new') {
                        if ($shared_parent_only || ! $plan->hasDomainOption('subdomains')) {
                            $fail(__('organization.domain.denied.type'));
                        }

                        return;
                    }

                    if ($value === 'parent') {
                        // Valid for a shared-plan child (server_type=shared)
                        // or a same-org plan-level parent-app dependency
                        // (AppPlan::parentApplication()) -- see review()'s
                        // equivalent branches.
                        if (! $plan->hasDomainOption('parent') || ! ($shared_parent_only || $plan->parentApplication())) {
                            $fail(__('organization.domain.denied.type'));
                        }

                        return;
                    }

                    if ($value === 'default') {
                        return;
                    }

                    if ($shared_parent_only || ! $plan->hasDomainOption(['primary', 'subdomains'])) {
                        $fail(__('organization.domain.denied.type'));

                        return;
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
            'subdomain' => ['required_if:domain,new', 'nullable', 'string', 'lowercase', new HostLabel],
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

        $organization = $target;

        $parent_app = null;
        $parent_application = $plan->parentApplication();
        if ($parent_application || $plan->setting('parent_server_id')) {
            if ($request->parent_app) {
                $parent_app = AppInstance::find($request->parent_app);
            } elseif ($parent_server_id = $plan->setting('parent_server_id')) {
                $parent_app = AppInstance::find($parent_server_id);
            } else {
                $available_parent_apps = ApplicationFacade::availableParents($plan);
                if ($available_parent_apps->count() == 1) {
                    $parent_app = $available_parent_apps->first();
                } else {
                    return redirect($this->withTarget("/discover/{$app->slug}/plans/{$plan->id}/review", $target))->with('error', __('organization.app.denied.select_parent_app', ['parent_app' => $parent_application?->name]));
                }
            }
        }

        $activate_app = Action::execute(new ApplicationActivate($organization, $app, $plan, $request->input('customizations'), $parent_app, label: $validatedData['label'], domain: $subdomain, configurations: $this->personalizedConfigurations($app, $request->configurations)));

        if ($organization->setting('step') === 1) {
            $organization->updateSetting('step', 2);
            $organization->save();

            return redirect('/users')->with('success', __('organization.app.activating', ['app' => $validatedData['label']]));
        }

        return redirect('/apps')->with('success', __('organization.app.activating', ['app' => $validatedData['label']]));
    }

    /**
     * Why the app can't be activated with this plan for the organization, or null when it can.
     */
    private function activationDenial(Application $app, ?AppPlan $plan, \App\Organization $target): ?Response
    {
        $checks = [['activate-app', [$app, $plan, $target]]];
        if ($plan) {
            $checks[] = ['select-app-plan', [$plan, $target]];
        }

        foreach ($checks as [$ability, $arguments]) {
            $response = Gate::inspect($ability, $arguments);

            if ($response->denied()) {
                return $response;
            }
        }

        return null;
    }

    /**
     * The organization the user chose to activate for, or null when they haven't: it travels with them
     * from the app, through its plans, to activating it.
     */
    private function chosenTarget(Request $request): ?\App\Organization
    {
        return $request->filled('organization') ? $this->activationTarget($request, Organization::account()) : null;
    }

    /**
     * Where the user hasn't chosen: their own organization if it can take the app, otherwise the first of
     * its suborganizations that can.
     */
    private function defaultTarget(Application $app, ?AppPlan $plan = null): \App\Organization
    {
        $candidates = AppActivationContext::organizationsFor(auth()->user());

        return $candidates->first(fn ($candidate) => $this->activationDenial($app, $plan, $candidate) === null) ?? $candidates->first();
    }

    /**
     * The organizations the user could activate the app for, with why one can't take it when that is so. Empty
     * when the user's organization has no suborganizations, as there is no choice to make.
     */
    private function organizationOptions(Application $app): Collection
    {
        $candidates = AppActivationContext::organizationsFor(auth()->user());

        if ($candidates->count() < 2) {
            return collect();
        }

        return $candidates->map(function ($candidate) use ($app) {
            $denial = $this->activationDenial($app, null, $candidate);

            return [
                'id' => $candidate->id,
                'name' => $candidate->name,
                'disabled' => $denial !== null,
                'note' => $denial ? $this->denialMessage($denial, $app) : null,
            ];
        })->values();
    }

    private function organizationSummary(\App\Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'is_suborganization' => (bool) $organization->parent_organization_id,
        ];
    }

    /**
     * The url for the organization the app is for. The user's own is what a url means without one.
     */
    private function withTarget(string $url, \App\Organization $target): string
    {
        return $target->is(auth()->user()->organization) ? $url : $url.'?organization='.$target->id;
    }

    /**
     * What to tell the user about a denial. Reaching the number of instances of the app is said as such.
     */
    private function denialMessage(Response $denial, Application $app): string
    {
        return in_array($denial->code(), ['limit_reached', 'plan_limit_reached'], true)
            ? __('organization.app.denied.org_limit', ['app' => $app->name])
            : (string) $denial->message();
    }

    /**
     * The organization the app is activated for: the user's own, or one of its suborganizations.
     */
    private function activationTarget(Request $request, \App\Organization $organization): \App\Organization
    {
        if (! $request->filled('organization')) {
            return $organization;
        }

        $request->validate(['organization' => ['numeric', new OrgSuborganization]]);

        return \App\Organization::findOrFail((int) $request->input('organization'));
    }

    // Whether this app+plan combo only allows the 'parent' domain choice --
    // see AppInstance::domain(): a shared-plan child whose app opts into
    // parent-domain behavior (AppPlan::hasDomainOption('parent')) always
    // resolves to the hub's domain regardless of what's picked here, so any
    // other choice would be silently ignored at deploy time. Single source
    // of truth for review() (which options to display) and activate()
    // (which to actually accept), so they can't drift apart.
    private function sharedParentOnlyDomain(Application $app, AppPlan $plan): bool
    {
        return $plan->setting('server_type') === 'shared' && $plan->hasDomainOption('parent');
    }

    private function personalizedConfigurations(Application $app, ?array $configurations): ?array
    {
        if (! $configurations) {
            return null;
        }

        $allowedKeys = array_map(
            fn ($k) => str_replace('configurations.', '', $k),
            array_keys(ApplicationFacade::validateConfigurations($app, true))
        );

        if (empty($allowedKeys)) {
            return null;
        }

        return array_intersect_key($configurations, array_flip($allowedKeys)) ?: null;
    }
}
