<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Apps\ApplicationActivate;
use App\Actions\Domains\UpdateDnsRecords;
use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Enums\ApplicationSource;
use App\Exceptions\VersionTestException;
use App\Http\Controllers\Controller;
use App\Jobs\Applications\AddLdapGroups;
use App\Organization;
use App\OrgDomain;
use App\OrgSubdomain;
use App\Rules\OrgSubdomainAvailable;
use App\Server;
use App\Services\SubscriptionService;
use App\Services\VersionTesting\VersionTestRunner;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Support\Facades\Application as ApplicationFacade;
use App\Support\Facades\Domain;
use App\Task;
use App\VersionTestRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SharedApps extends Controller
{
    public function __construct(private VersionTestRunner $runner) {}

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
     * Registers a shared app. Nothing is deployed here.
     *
     * An app with a registered profile (source `profile`) waits to be activated
     * from the shared app's page (see deploy()), once its version has passed its
     * tests on its plan or had them bypassed. Any other app, like GitLab, is
     * installed separately and can't be activated through kumulicp: it is only
     * registered, so its users can be managed centrally through LDAP and SSO.
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

        if ($this->isDeployable($application)) {
            // Added, not activated: it stays that way until deploy() is run
            $app_instance->status = 'deactivated';
            $app_instance->save();
        } else {
            // Nothing will ever activate it, and the apps shared from it need it active.
            // Activating a deployable app creates its LDAP groups; here nothing else would.
            $app_instance->status = 'active';
            $app_instance->save();

            AddLdapGroups::dispatch($app_instance);
        }

        return redirect("/admin/service/shared-apps/{$app_instance->id}");
    }

    /**
     * The steps to activate a shared app: its servers and configuration on its
     * plan, a look at the chart they produce, the tests on that plan, and then
     * the activation itself.
     */
    public function activation(AppInstance $shared_app)
    {
        $redirect = $this->pendingRedirect($shared_app);
        if ($redirect) {
            return $redirect;
        }

        $plan = $shared_app->plan;
        $summary = $this->activationSummary($shared_app);
        $plan_url = "/admin/apps/{$shared_app->application->slug}/plans/{$plan->id}";

        return inertia()->render('Admin/SharedApps/SharedAppActivation', [
            'app' => [
                'id' => $shared_app->id,
                'label' => $shared_app->label,
            ],
            'activation' => $summary + [
                // The first step still to do
                'current_step' => match (true) {
                    ! $summary['servers_saved'] => 0,
                    ! $summary['can_activate'] => 3,
                    default => 4,
                },
            ],
            'servers' => [
                'web' => $plan->web_server?->name,
                'database' => $plan->database_server?->name,
                'sso' => $plan->sso_server?->name,
            ],
            'links' => [
                'plan' => "{$plan_url}/edit",
                'configurations' => "{$plan_url}/configurations",
                'helm_values' => "/admin/organizations/{$shared_app->organization_id}/apps/{$shared_app->id}/helm-values",
            ],
            'breadcrumbs' => [
                ['url' => '/admin/service/shared-apps', 'label' => __('admin.shared_apps.shared_apps')],
                ['url' => "/admin/service/shared-apps/{$shared_app->id}", 'label' => $shared_app->label],
                ['label' => __('admin.shared_apps.activation')],
            ],
        ]);
    }

    /**
     * Starts testing the shared app's version on the shared app's own plan.
     * How the version did on the plans customers can pick says nothing about it.
     */
    public function startTests(AppInstance $shared_app)
    {
        $redirect = $this->pendingRedirect($shared_app) ?? $this->serversRedirect($shared_app);
        if ($redirect) {
            return $redirect;
        }

        try {
            $run = $this->runner->start($shared_app->version, auth()->user(), $shared_app->plan);
        } catch (VersionTestException $e) {
            return redirect($this->activationUrl($shared_app))->with('error', $e->getMessage());
        }

        return redirect($this->runUrl($shared_app, $run))->with('success', __('admin.version_tests.started'));
    }

    /**
     * Skips testing the shared app's version on its plan.
     */
    public function bypassTests(Request $request, AppInstance $shared_app)
    {
        $redirect = $this->pendingRedirect($shared_app) ?? $this->serversRedirect($shared_app);
        if ($redirect) {
            return $redirect;
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:1000',
            'acknowledge' => 'accepted',
        ]);

        try {
            $this->runner->bypass($shared_app->version, auth()->user(), $validated['reason'], $shared_app->plan);
        } catch (VersionTestException $e) {
            return redirect($this->activationUrl($shared_app))->with('error', $e->getMessage());
        }

        return redirect($this->activationUrl($shared_app))->with('success', __('admin.shared_apps.tests_bypassed'));
    }

    /**
     * Deploys a shared app that was added but not yet activated. Only allowed
     * once its version has passed its tests on the shared app's own plan, or
     * had them bypassed there, since this puts it in front of real users.
     */
    public function deploy(AppInstance $shared_app)
    {
        $redirect = $this->pendingRedirect($shared_app) ?? $this->serversRedirect($shared_app);
        if ($redirect) {
            return $redirect;
        }

        $version = $shared_app->version;
        $plan = $shared_app->plan;

        if (! $version->canBeActivatedOn($plan)) {
            return redirect($this->activationUrl($shared_app))->with('error', __('admin.shared_apps.errors.not_tested', ['version' => $version->name]));
        }

        // The same deploy-and-track-to-completion flow as a normal app
        // activation, run against the instance that was added earlier
        Action::execute(new ApplicationActivate($shared_app->organization, $shared_app->application, $plan, version: $version, label: $shared_app->label, existing_instance: $shared_app));

        return redirect("/admin/service/shared-apps/{$shared_app->id}")->with('success', __('admin.shared_apps.activation_started', ['app' => $shared_app->label]));
    }

    /**
     * Where the shared app stands for activation: its servers, and how its
     * version did on its own plan.
     *
     * @return array<string, mixed>
     */
    private function activationSummary(AppInstance $shared_app): array
    {
        $version = $shared_app->version;
        $plan = $shared_app->plan;
        $run = $version->planTestRuns($plan)->first();

        return [
            'pending' => $this->canBeActivated($shared_app),
            'servers_saved' => $plan->web_server_id !== null,
            'version' => $version->name,
            'gate' => $version->testGate($plan),
            'can_activate' => $version->canBeActivatedOn($plan),
            'run_url' => $run ? $this->runUrl($shared_app, $run) : null,
            'bypassed_by' => $run?->bypassed_by?->name,
            'bypass_reason' => $run?->bypass_reason,
        ];
    }

    private function activationUrl(AppInstance $shared_app): string
    {
        return "/admin/service/shared-apps/{$shared_app->id}/activation";
    }

    private function runUrl(AppInstance $shared_app, VersionTestRun $run): string
    {
        return "/admin/apps/{$shared_app->application->slug}/versions/{$shared_app->version->name}/tests/{$run->id}";
    }

    /**
     * A refusal for a shared app that is not waiting to be activated, or null.
     */
    private function pendingRedirect(AppInstance $shared_app): ?RedirectResponse
    {
        abort_unless($shared_app->organization->type === 'shared', 404);

        if ($this->canBeActivated($shared_app)) {
            return null;
        }

        $error = $this->isDeployable($shared_app->application) ? 'already_activated' : 'not_deployable';

        return redirect("/admin/service/shared-apps/{$shared_app->id}")->with('error', __("admin.shared_apps.errors.{$error}"));
    }

    /**
     * A refusal for a shared app without a web server yet, or null. The tests
     * and the activation both run on the servers, so they come first.
     */
    private function serversRedirect(AppInstance $shared_app): ?RedirectResponse
    {
        if ($shared_app->plan->web_server_id !== null) {
            return null;
        }

        return redirect($this->activationUrl($shared_app))->with('error', __('admin.shared_apps.errors.no_servers'));
    }

    /**
     * Whether kumulicp can deploy the app: only apps with a registered profile.
     */
    private function isDeployable(Application $app): bool
    {
        return $app->source === ApplicationSource::Profile;
    }

    /**
     * Whether the shared app was added but never deployed.
     */
    private function canBeActivated(AppInstance $shared_app): bool
    {
        return $this->isDeployable($shared_app->application)
            && $shared_app->status === 'deactivated'
            && ! Task::where('app_instance_id', $shared_app->id)->where('action_slug', 'application_activate')->exists();
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
            // Where to finish setting it up, while that is still to do
            'activation_url' => $this->canBeActivated($shared_app) ? $this->activationUrl($shared_app) : null,
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
