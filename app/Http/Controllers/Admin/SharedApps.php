<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Apps\ApplicationActivate;
use App\Actions\Domains\UpdateDnsRecords;
use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Http\Controllers\Controller;
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
            'available_apps' => Application::all()->filter(fn ($app) => $this->isShareable($app))->values()->map(function ($app) {
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

    /**
     * Whether the app's profile says it can be run as a shared app.
     */
    private function isShareable(Application $app): bool
    {
        return ApplicationFacade::profile($app)->isCompatible('shareable');
    }

    /**
     * Registers a shared app. Nothing is deployed here: that happens from the
     * shared app's page (see deploy()), once its version has passed its tests
     * or had them bypassed.
     */
    public function store(Request $request)
    {
        /* Validate */
        $validated = $request->validate([
            'app' => [
                'required',
                'exists:applications,id',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $app = Application::find($value);

                    if (! $app) {
                        return;
                    }

                    if (! $this->isShareable($app)) {
                        $fail(__('admin.shared_apps.errors.not_shareable', ['app' => $app->name]));
                    } elseif (! $app->versions()->where('status', 'active')->exists()) {
                        $fail(__('admin.shared_apps.errors.no_version'));
                    }
                },
            ],
            'label' => 'required|string',
        ]);

        $organization = Organization::where('type', 'shared')->first();
        $application = Application::find($validated['app']);
        $version = $application->versions()->where('status', 'active')->first();

        // Every shared app gets its own dedicated, hidden plan -- it's not
        // meant to be picked by organizations subscribing to the app, just
        // to hold the settings/features/configurations for this one shared
        // instance. It has no servers until the app is activated.
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
        $plan->save();

        $app_instance = ApplicationFacade::activate(
            organization: $organization,
            application: $application,
            version: $version,
            plan: $plan,
            label: $validated['label']
        )->get();

        // Added, not activated: it stays that way until deploy() is run
        $app_instance->status = 'deactivated';
        $app_instance->save();

        return redirect("/admin/service/shared-apps/{$app_instance->id}");
    }

    /**
     * Deploys a shared app that was added but not yet activated. Only allowed
     * once its version has passed its tests, or had them bypassed, since this
     * puts it in front of real users.
     */
    public function deploy(Request $request, AppInstance $shared_app)
    {
        abort_unless($shared_app->organization->type === 'shared', 404);

        $redirect = redirect("/admin/service/shared-apps/{$shared_app->id}");

        if (! $this->canBeActivated($shared_app)) {
            return $redirect->with('error', __('admin.shared_apps.errors.already_activated'));
        }

        $version = $shared_app->version;

        if (! $version->canBeEnabled()) {
            return $redirect->with('error', __('admin.shared_apps.errors.not_tested', ['version' => $version->name]));
        }

        $validated = $request->validate([
            'web_server' => ['required', 'numeric', Rule::exists('servers', 'id')->where('type', 'web')],
            'database_server' => ['nullable', 'numeric', Rule::exists('servers', 'id')->where('type', 'database')],
            'sso_server' => ['nullable', 'numeric', Rule::exists('servers', 'id')->where('type', 'sso')],
        ]);

        // A web server is what tells a hidden plan apart from one that's just a
        // pointer to an independently installed app.
        $plan = $shared_app->plan;
        $plan->web_server_id = $validated['web_server'];
        $plan->database_server_id = $validated['database_server'] ?? null;
        $plan->sso_server_id = $validated['sso_server'] ?? null;
        $plan->save();

        // The same deploy-and-track-to-completion flow as a normal app
        // activation, run against the instance that was added earlier
        Action::execute(new ApplicationActivate($shared_app->organization, $shared_app->application, $plan, version: $version, label: $shared_app->label, existing_instance: $shared_app));

        return $redirect->with('success', __('admin.shared_apps.activation_started', ['app' => $shared_app->label]));
    }

    /**
     * Where the shared app stands for activation: whether it can be activated
     * yet and, if not, why (its version's tests).
     *
     * @return array<string, mixed>
     */
    private function activationSummary(AppInstance $shared_app): array
    {
        $version = $shared_app->version;

        return [
            'pending' => $this->canBeActivated($shared_app),
            'can_activate' => $version->canBeEnabled(),
            'version' => $version->name,
            'gate' => $version->testGate(),
            'tests_url' => "/admin/apps/{$shared_app->application->slug}/versions/{$version->name}/tests",
        ];
    }

    /**
     * @return list<array{value: mixed, text: string}>
     */
    private function serverOptions(string $type): array
    {
        return Server::where('type', $type)->get()->map(fn ($server) => [
            'value' => $server->id,
            'text' => $server->name.' ('.$server->status.')',
        ])->all();
    }

    /**
     * Whether the shared app was added but never deployed.
     */
    private function canBeActivated(AppInstance $shared_app): bool
    {
        return $shared_app->status === 'deactivated' && ! $shared_app->plan?->isSharedAppActive();
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
                'status' => $shared_app->status,
            ],
            'activation' => $this->activationSummary($shared_app),
            'web_servers' => $this->serverOptions('web'),
            'database_servers' => $this->serverOptions('database'),
            'sso_servers' => $this->serverOptions('sso'),
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
            // These columns are NOT NULL with no default. The shared organization has no
            // contact or address, but leaving them out only works when the database isn't
            // strict, and MariaDB rejects a missing JSON settings value either way.
            $shared->settings = [];
            foreach (['email', 'phone_number', 'contact_first_name', 'contact_last_name', 'contact_email', 'contact_phone_number', 'street', 'zipcode', 'city', 'state', 'country'] as $column) {
                $shared->{$column} = '';
            }
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
