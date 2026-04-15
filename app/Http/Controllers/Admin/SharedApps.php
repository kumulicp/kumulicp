<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Domains\UpdateDnsRecords;
use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Http\Controllers\Controller;
use App\Jobs\Applications\AddLdapGroups;
use App\Organization;
use App\OrgDomain;
use App\OrgSubdomain;
use App\Rules\OrgSubdomainAvailable;
use App\Services\SubscriptionService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Support\Facades\Application as ApplicationFacade;
use App\Support\Facades\Domain;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SharedApps extends Controller
{
    public function index()
    {
        $shared = Organization::where('type', 'shared')->first();
        $apps = $shared?->applications()->paginate(20);

        $plans = [];
        foreach (AppPlan::with('application')->get() as $plan) {
            $plans[$plan->application->id][] = [
                'id' => $plan->id,
                'name' => $plan->name,
            ];
        }

        return inertia()->render('Admin/SharedApps/SharedAppsList', [
            'enabled' => $shared ? true : false,
            'apps' => $shared?->app_instances->map(function ($app) {
                return [
                    'id' => $app->id,
                    'name' => $app->name,
                    'label' => $app->label,
                    'status' => $app->enabled ? __('labels.enabled') : __('labels.disabled'),
                ];
            }),
            'plans' => $plans,
            'available_apps' => Application::all()->map(function ($app) {
                return [
                    'id' => $app->id,
                    'name' => $app->name,
                ];
            }),
            'meta' => $apps ? [
                'total' => $apps->total(),
                'pages' => $apps->lastPage(),
                'page' => $apps->currentPage(),
            ] : [],
            'breadcrumbs' => [
                [
                    'label' => __('admin.applications.apps'),
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        /* Validate */
        $validated = $request->validate([
            'app' => 'required|exists:applications,id',
            'plan' => 'required|exists:app_plans,id',
            'label' => 'required|string',
            'activate' => 'boolean',
        ]);

        $organization = Organization::where('type', 'shared')->first();
        $application = Application::find($validated['app']);
        $version = $application->versions()->where('status', 'active')->first();
        $app_instance = ApplicationFacade::activate(
            organization: $organization,
            application: $application,
            version: $version,
            plan: AppPlan::find($validated['plan']),
            label: $validated['label']
        );
        AddLdapGroups::dispatch($app_instance->get());

        return redirect("/admin/service/shared-apps/{$app_instance->id}");
    }

    public function show(AppInstance $shared_app)
    {
        $organization = Organization::where('type', 'superaccount')->first();

        return inertia()->render('Admin/SharedApps/SharedAppEdit', [
            'app' => [
                'id' => $shared_app->id,
                'label' => $shared_app->label,
                'plan' => $shared_app->plan_id,
                'name' => $shared_app->name,
                'version' => $shared_app->version_id,
                'domain' => $shared_app->primary_domain_id ?? 0,
            ],
            'plans' => AppPlan::where('application_id', $shared_app->application_id)->get()->map(function ($plan) {
                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                ];
            }),
            'versions' => AppVersion::where('application_id', $shared_app->application_id)->get()->map(function ($version) {
                return [
                    'id' => $version->id,
                    'name' => $version->name,
                ];
            }),
            'parent_domains' => $organization->domains->map(function ($domain) {
                return [
                    'id' => $domain->id,
                    'name' => $domain->name,
                ];
            }),
            'domains' => $organization->subdomains->map(function ($domain) {
                return [
                    'id' => $domain->id,
                    'name' => $domain->name,
                ];
            })->push([
                'name' => $shared_app->base_domain(),
                'id' => 0,
            ])->push([
                'name' => 'Add New Subdomain',
                'id' => 'new',
            ])->all(),
            'breadcrumbs' => [
                [
                    'url' => '/admin/service/shared-apps',
                    'label' => __('admin.shared_apps.shared_apps'),
                ],
                [
                    'label' => $shared_app->label,
                ],
            ],
        ]);
    }

    public function edit(AppInstance $shared_app)
    {
        //
    }

    public function update(Request $request, AppInstance $shared_app)
    {
        /* Validate */
        $validated = $request->validate([
            'label' => 'required|string|max:100',
            'domain' => [
                'required',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === 0 || $value === 'new') {
                        return true;
                    }
                    $domain = OrgSubdomain::find($value);

                    if (! $domain) {
                        $fail(__('organization.domain.denied.exists'));

                        return;
                    }
                },
            ],
            'parent_domain' => 'nullable|required_if:domain,new|numeric|exists:org_domains,id',
            'subdomain' => ['nullable', 'required_if:domain,new', 'string', new OrgSubdomainAvailable($shared_app)],
        ]);

        $current_subdomain = $shared_app->primary_domain;
        $new_subdomain = null;

        // Added to custom domains
        if ($validated['domain'] === 'new') {
            $parent_domain = OrgDomain::find($validated['parent_domain']);
            $new_subdomain = Domain::addSubdomain($validated['subdomain'], $parent_domain, $shared_app);
        } elseif ($validated['domain'] !== 0) {
            // Can find directly since it should already be validated
            $new_subdomain = OrgSubdomain::find($validated['domain']);
        }

        $shared_app->label = $validated['label'];
        $shared_app->primary_domain_id = $new_subdomain?->id;
        $shared_app->save();

        if (isset($new_subdomain) && $current_subdomain !== $new_subdomain && $new_subdomain->domain->type === 'managed') {
            Action::execute(new UpdateDnsRecords($shared_app->organization, $new_subdomain->domain));
        }

        return redirect("/admin/service/shared-apps/{$shared_app->id}")->with('success', __('admin.shared_apps.updated', ['app' => $shared_app->name]));
    }

    public function activate()
    {
        $shared = Organization::where('type', 'shared')->first();

        if (! $shared) {
            $shared = new Organization;
            $shared->slug = 'shared';
            $shared->name = __('admin.shared_apps.shared_apps');
            $shared->type = 'shared';
            $shared->secretpw = Str::password(20, true, true, false, false);
            $shared->description = __('admin.shared_apps.shared_apps_description');
            $shared->status = 'active';
            $shared->save();
        }

        $account = AccountManager::accounts();
        $account->create($shared);
        $superaccount = Organization::where('type', 'superaccount')->first();
        $plan = (new SubscriptionService($shared))->all()->updateBase($superaccount->plan);

        return redirect('/admin/service/shared-apps')->with('success', __('admin.shared_apps.enabled'));
    }
}
