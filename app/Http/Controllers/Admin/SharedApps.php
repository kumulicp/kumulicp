<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Apps\ApplicationActivate;
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
use App\Server;
use App\Services\SubscriptionService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Support\Facades\Application as ApplicationFacade;
use App\Support\Facades\Domain;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SharedApps extends Controller
{
    public function index()
    {
        $shared = Organization::where('type', 'shared')->first();
        $apps = $shared?->applications()->paginate(20);

        return inertia()->render('Admin/SharedApps/SharedAppsList', [
            'enabled' => $shared ? true : false,
            'apps' => $shared?->app_instances->map(function ($app) {
                return [
                    'id' => $app->id,
                    'name' => $app->name,
                    'label' => $app->label,
                    'status' => $app->status === 'active' ? __('labels.enabled') : __('labels.disabled'),
                ];
            }),
            'available_apps' => Application::all()->map(function ($app) {
                return [
                    'id' => $app->id,
                    'name' => $app->name,
                ];
            }),
            'web_servers' => Server::where('type', 'web')->get()->map(function ($server) {
                return [
                    'value' => $server->id,
                    'text' => $server->name.' ('.$server->status.')',
                ];
            }),
            'database_servers' => Server::where('type', 'database')->get()->map(function ($server) {
                return [
                    'value' => $server->id,
                    'text' => $server->name.' ('.$server->status.')',
                ];
            }),
            'sso_servers' => Server::where('type', 'sso')->get()->map(function ($server) {
                return [
                    'value' => $server->id,
                    'text' => $server->name.' ('.$server->status.')',
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
            'label' => 'required|string',
            'activate' => 'boolean',
            'web_server' => [Rule::requiredIf($request->boolean('activate')), 'nullable', 'numeric', 'exists:servers,id'],
            'database_server' => 'nullable|numeric|exists:servers,id',
            'sso_server' => 'nullable|numeric|exists:servers,id',
        ]);

        $organization = Organization::where('type', 'shared')->first();
        $application = Application::find($validated['app']);
        $version = $application->versions()->where('status', 'active')->first();

        // Every shared app gets its own dedicated, hidden plan -- it's not
        // meant to be picked by organizations subscribing to the app, just
        // to hold the settings/features/configurations for this one shared
        // instance.
        $plan = new AppPlan;
        $plan->application_id = $application->id;
        $plan->name = $validated['label'];
        $plan->description = __('admin.shared_apps.plan_description', ['app' => $application->name]);
        $plan->hidden = true;
        $plan->features = [];
        $plan->settings = [
            'base' => [],
            'standard' => [],
            'basic' => [],
            'storage' => [],
            'application' => [],
        ];

        // A web server is only ever set when we're actually deploying this
        // shared app through kumulicp -- it's what tells a hidden plan apart
        // from one that's just a pointer to an independently installed app.
        if ($validated['activate'] ?? false) {
            $plan->web_server_id = $validated['web_server'] ?? null;
            $plan->database_server_id = $validated['database_server'] ?? null;
            $plan->sso_server_id = $validated['sso_server'] ?? null;
        }

        $plan->save();

        if ($validated['activate'] ?? false) {
            // Dispatches the same deploy-and-track-to-completion flow as a
            // normal app activation, instead of just creating an inert
            // AppInstance row.
            $task = Action::execute(new ApplicationActivate($organization, $application, $plan, version: $version, label: $validated['label']));
            $app_instance = $task->app_instance;
        } else {
            $app_instance = ApplicationFacade::activate(
                organization: $organization,
                application: $application,
                version: $version,
                plan: $plan,
                label: $validated['label']
            )->get();
        }

        AddLdapGroups::dispatch($app_instance);

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
                'app_slug' => $shared_app->application->slug,
                'name' => $shared_app->name,
                'version' => $shared_app->version_id,
                'domain' => $shared_app->primary_domain_id ?? 0,
                'active' => $shared_app->status === 'active',
                'organization_id' => $shared_app->organization_id,
            ],
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
