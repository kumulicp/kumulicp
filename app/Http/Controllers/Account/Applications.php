<?php

namespace App\Http\Controllers\Account;

use App\Actions\Domains\UpdateDnsRecords;
use App\Actions\Organizations\SubscriptionUpdate;
use App\AppInstance;
use App\Http\Controllers\Controller;
use App\Jobs\Applications\UpdateLDAPGroups;
use App\OrgDomain;
use App\OrgSubdomain;
use App\Rules\OrgSubdomainAvailable;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Support\Facades\Application;
use App\Support\Facades\Billing;
use App\Support\Facades\Domain;
use App\Support\Facades\Organization;
use App\Support\Facades\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class Applications extends Controller
{
    public function index()
    {
        $organization = Organization::account();
        $apps = Organization::apps(['organization', 'version']);

        if (! $apps->isEmpty()) {
            return inertia('Organization/Apps/AppsList', [
                'apps' => $apps->map(function ($app) {
                    $tasks = $app->tasks()->where('background', 0)->where('status', '!=', 'complete')->get();

                    return [
                        'id' => $app->id,
                        'name' => $app->label,
                        'has_admin_address' => ! is_null($app->version->admin_path),
                        'status' => $app->status,
                        'address' => $app->address(),
                        'admin_address' => $app->admin_address(),
                        'tasks' => $tasks->map(function ($task) {
                            return [
                                'id' => $task->id,
                                'name' => $task->action_slug,
                                'description' => $task->description,
                            ];
                        })->push($app->deactivate_at ? [
                            'id' => 0,
                            'name' => '',
                            'description' => __('labels.deactivates', ['date' => $app->deactivate_at?->format('M d Y')]),
                        ] : []),
                        'organization' => [
                            'name' => $app->organization->name,
                        ],
                    ];
                }),
                'multiple_orgs' => $organization->suborganizations()->count() > 0,
            ]);
        }

        return redirect('/discover');
    }

    public function show(AppInstance $app)
    {
        return redirect('/apps/'.$app->id.'/edit');
    }

    public function edit(AppInstance $app)
    {
        $this->authorize('edit-app', $app);

        $organization = Organization::account();
        $version = $app->version()->where('status', 'active')->first();
        $domain_option = $app->application->domain_option;

        $parent_app_instance = $app->parent()->with(['application', 'primary_domain'])->first();

        $domains = [];
        $parent_domains = [];
        $can_add_custom_subdomains = in_array($domain_option, ['all', 'subdomains']);
        if ($app->application->can_update_domain && $domain_option !== 'parent') {
            $domains = Application::instance($app)->availableDomains()->map(function ($domain) {
                return [
                    'text' => $domain->name,
                    'value' => $domain->id,
                ];
            });

            if ($can_add_custom_subdomains) {
                $domains->push([
                    'text' => $app->base_domain(),
                    'value' => 0,
                ])->push([
                    'text' => 'Add New Subdomain',
                    'value' => 'connection',
                ]);

                $parent_domains = $organization->domains()->active()->primary()->get()->map(function ($domain) {
                    return [
                        'text' => $domain->name,
                        'value' => $domain->id,
                    ];
                });
            }

            $domains = $domains->all();
        }

        $tasks = $app->tasks()->where('background', 0)->where('status', '!=', 'complete')->get();

        $features = collect(Application::instance($app)->features()->optional($app))->map(function ($customization) {
            return [
                'label' => $customization['label'],
                'description' => $customization['description'],
                'name' => $customization['name'],
                'status' => $customization['status'] == 'enabled',
                'price' => Arr::get($customization, 'price') ? '$'.$customization['price'] : null,
            ];
        });

        $settings = Application::instance($app)->personalized_settings()->values();

        return inertia('Organization/Apps/AppEdit', [
            'app' => [
                'name' => $app->application->name,
                'id' => $app->id,
                'domain' => $app->primary_domain ? $app->primary_domain->id : 0,
                'parent_app' => $parent_app_instance ? [
                    'id' => $parent_app_instance->id,
                    'name' => $parent_app_instance->application->name,
                    'domain' => $parent_app_instance->domain(),
                    'address' => $parent_app_instance->address(),
                ] : null,
                'label' => $app->label,
                'organization' => $organization->suborganizations()->count() > 0 ? [
                    'name' => $app->organization->name,
                ] : null,
                'admin_access' => $app->plan->setting('admin_access', false),
                'admin_password' => $app->api_password(),
            ],
            'customizations' => $features,
            'settings' => $settings,
            'domains' => $domains,
            'tasks' => $tasks->map(function ($task) {
                return [
                    'id' => $task->id,
                    'name' => $task->action_slug,
                    'description' => $task->description,
                ];
            }),
            'parent_domains' => $parent_domains,
            'can' => [
                'update_app' => $app->status === 'active',
                'add_custom_subdomain' => $can_add_custom_subdomains,
            ],
            'breadcrumbs' => [
                [
                    'url' => '/apps',
                    'label' => 'Apps',
                ],
                [
                    'label' => $app->label,
                ],
            ],
        ]);
    }

    public function update(Request $request, AppInstance $app)
    {
        $this->authorize('edit-app', $app);

        if ($app->status === 'updating') {
            return back()->with('error', 'Unable to update app while updates in progress');
        }

        $validateConfigurations = Application::validateConfigurations($app->application, true);
        $validated = $request->validate(array_merge([
            'label' => 'required|string|max:100',
            'domain' => [
                'required',
                function (string $attribute, mixed $value, \Closure $fail) use ($app) {
                    if ($value === 0 || $value === 'connection') {
                        return true;
                    }
                    $domain = OrgSubdomain::find($value);

                    if (! $domain) {
                        $fail(__('organization.domain.denied.exists'));

                        return;
                    }

                    $gate = Gate::inspect('domain-direct-to-app-instance', [$domain, $app]);

                    if (! $gate->allowed()) {
                        $fail($gate->message());
                    }
                },
            ],
            'parent_domain' => 'nullable|required_if:domain,connection|numeric|exists:org_domains,id',
            'subdomain' => ['nullable', 'required_if:domain,connection', 'string', new OrgSubdomainAvailable($app)],
        ], $validateConfigurations));

        $customizations = $request->input('customizations') ?? [];
        $app->label = $validated['label'];
        $app->save();

        $current_subdomain = $app->primary_domain;

        // Added to custom domains
        if ($validated['domain'] === 'connection') {
            $parent_domain = OrgDomain::where('id', $validated['parent_domain'])->where('organization_id', $app->organization->id)->first();

            if ($parent_domain) {
                $subdomain = Domain::addSubdomain($validated['subdomain'], $parent_domain, $app);

                if (! Gate::allows('domain-direct-to-app-instance', [$subdomain, $app])) {
                    return redirect('/apps/'.$app->id.'/edit')->with('error', __('organization.domain.denied.ip', ['domain' => $subdomain->name, 'ip' => $app->web_server->server->ip]));
                }
            } else {
                return redirect('/apps/'.$app->id.'/edit')->with('error', __('organization.domain.denied.exists'));
            }
        } else {
            // Can find directly since it should already be validated
            $subdomain = OrgSubdomain::find($validated['domain']);
        }

        $application = Application::instance($app);

        if (is_array($customizations) && count($customizations) > 0) {
            $application->features()->update($customizations);
            $application->updateCustomizations();
            Action::execute(new SubscriptionUpdate($application->organization, Subscription::all()), background: true); // TODO Check that customization change affects invoice

            UpdateLDAPGroups::dispatch($application->get());
            // Update all users access types if feature affects permissions
            if ($application->features()->hasRolesDependentOnUpdatedFeatures()) {
                AccountManager::users()->updateAllUsersAccessType();
            }

        }

        $application->updatePrimaryDomain($subdomain);

        if (Arr::has($validated, 'configurations')) {
            foreach ($validated['configurations'] as $key => $value) {
                $application->updateSetting('configurations.'.$key, $value);
            }
        }

        $application->save();

        if ($current_subdomain && $current_subdomain->domain->type === 'managed') {
            Action::execute(new UpdateDnsRecords($app->organization, $current_subdomain->domain));
        } elseif ($subdomain && $subdomain->domain->type === 'managed') {
            Action::execute(new UpdateDnsRecords($app->organization, $subdomain->domain));
        }

        return redirect('/apps/'.$app->id.'/edit')->with('success', __('organization.app.updated', ['app' => $app->label]));
    }

    public function reactivate(Request $request, AppInstance $app)
    {
        $this->authorize('reactivate-app', $app);

        $app->deactivate_at = null;
        $app->status = 'active';
        $app->save();

        $subscription = Subscription::all();

        Action::execute(new SubscriptionUpdate($app->organization, $subscription));

        return back()->with('success', __('organization.app.reactivated', ['app' => $app->label]));
    }

    public function destroy(Request $request, AppInstance $app)
    {
        $this->authorize('deactivate-app', $app);

        if (Billing::isBillable()) {
            $period_end = Billing::periodEnds();
            $app->deactivate_at = $period_end;
        } else {
            $app->deactivate_at = now();
        }

        $app->status = 'deactivating';
        $app->save();

        $subscription = Subscription::all();

        Action::execute(new SubscriptionUpdate($app->organization, $subscription));

        return back()->with('success', __('organization.app.deactivated', ['app' => $app->label, 'date' => $app->deactivate_at->format('M d, Y')]));
    }
}
