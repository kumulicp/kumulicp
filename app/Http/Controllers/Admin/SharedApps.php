<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Apps\ApplicationActivate;
use App\Actions\Domains\UpdateDnsRecords;
use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\Exceptions\VersionTestException;
use App\Http\Controllers\Controller;
use App\Jobs\Applications\AddLdapGroups;
use App\Organization;
use App\OrgDomain;
use App\OrgSubdomain;
use App\Rules\OrgSubdomainAvailable;
use App\Services\SubscriptionService;
use App\Services\VersionTesting\VersionTestRunner;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Support\Facades\Application as ApplicationFacade;
use App\Support\Facades\Domain;
use App\Support\SharedAppContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
     * Registers a shared app. Nothing is deployed here, and nothing is set on
     * the instance: it is set up from its own pages afterwards.
     *
     * An app with a registered profile (source `profile`) waits there to be
     * activated (see deploy()) once its version has passed its tests on its
     * plan or had them bypassed, or to be marked as managed externally (see
     * manageExternally()). Any other app, like GitLab, is installed separately
     * and can't be activated through kumulicp: it is only registered, so its
     * users can be managed centrally through LDAP and SSO.
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

        // Without settings: it takes them from its plan once it is activated
        $app_instance = ApplicationFacade::activate(
            organization: $organization,
            application: $application,
            version: $version,
            plan: $plan,
            label: $validated['label'],
            with_configurations: false,
        )->get();

        if (SharedAppContext::isDeployable($application)) {
            // Added, not activated: it stays that way until it is activated or marked as managed externally
            $app_instance->status = 'deactivated';
            $app_instance->save();
        } else {
            // Nothing will ever activate it, so it is only registered
            $this->registerExternally($app_instance);
        }

        return redirect("/admin/service/shared-apps/{$app_instance->id}");
    }

    /**
     * Registers the shared app as one that is run outside Kumuli CP. The apps
     * shared from it need it active, and its users come through the LDAP groups
     * created here; activating a deployable app creates them, but nothing else would.
     */
    private function registerExternally(AppInstance $app_instance): void
    {
        $app_instance->updateSetting('managed_externally', true);
        $app_instance->status = 'active';
        $app_instance->save();

        AddLdapGroups::dispatch($app_instance);
    }

    /**
     * Chooses the version the shared app is activated with. It is tested and
     * activated as chosen, so it can only change while the app waits to be set up.
     */
    public function setVersion(Request $request, AppInstance $shared_app)
    {
        $redirect = $this->pendingRedirect($shared_app);
        if ($redirect) {
            return $redirect;
        }

        $validated = $request->validate([
            'version' => ['required', Rule::exists('app_versions', 'id')->where('application_id', $shared_app->application_id)],
        ]);

        $shared_app->version_id = $validated['version'];
        $shared_app->save();

        return redirect($this->viewUrl($shared_app))->with('success', __('admin.shared_apps.version_updated', ['app' => $shared_app->label]));
    }

    /**
     * Chooses not to activate the shared app: it is run outside Kumuli CP, and its
     * users come through the central LDAP groups that are already in place. All
     * it needs is the domain organizations click through to.
     */
    public function manageExternally(Request $request, AppInstance $shared_app)
    {
        $redirect = $this->pendingRedirect($shared_app);
        if ($redirect) {
            return $redirect;
        }

        $this->applyDomain($shared_app, $request->validate($this->domainRules($shared_app)));
        $this->registerExternally($shared_app);

        return redirect($this->viewUrl($shared_app))->with('success', __('admin.shared_apps.managed_externally', ['app' => $shared_app->label]));
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
            return redirect($this->viewUrl($shared_app))->with('error', $e->getMessage());
        }

        return redirect(SharedAppContext::runUrl($shared_app, $run))->with('success', __('admin.version_tests.started'));
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
            return redirect($this->viewUrl($shared_app))->with('error', $e->getMessage());
        }

        return redirect($this->viewUrl($shared_app))->with('success', __('admin.shared_apps.tests_bypassed'));
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
            return redirect($this->viewUrl($shared_app))->with('error', __('admin.shared_apps.errors.not_tested', ['version' => $version->name]));
        }

        // The same deploy-and-track-to-completion flow as a normal app
        // activation, run against the instance that was added earlier
        Action::execute(new ApplicationActivate($shared_app->organization, $shared_app->application, $plan, version: $version, label: $shared_app->label, existing_instance: $shared_app));

        return redirect($this->viewUrl($shared_app))->with('success', __('admin.shared_apps.activation_started', ['app' => $shared_app->label]));
    }

    private function viewUrl(AppInstance $shared_app): string
    {
        return "/admin/service/shared-apps/{$shared_app->id}";
    }

    /**
     * A refusal for a shared app that is not waiting to be set up, or null.
     */
    private function pendingRedirect(AppInstance $shared_app): ?RedirectResponse
    {
        abort_unless($shared_app->isSharedApp(), 404);

        if (SharedAppContext::canBeActivated($shared_app)) {
            return null;
        }

        $error = match (true) {
            ! SharedAppContext::isDeployable($shared_app->application) => 'not_deployable',
            $shared_app->isManagedExternally() => 'managed_externally',
            default => 'already_activated',
        };

        return redirect($this->viewUrl($shared_app))->with('error', __("admin.shared_apps.errors.{$error}"));
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

        return redirect($this->viewUrl($shared_app))->with('error', __('admin.shared_apps.errors.no_servers'));
    }

    /**
     * Just what the shared app is: where it stands, and what that means for it.
     * It is set up, and its settings changed, from the other pages.
     */
    public function show(AppInstance $shared_app)
    {
        $state = SharedAppContext::state($shared_app);
        $activated = $state === SharedAppContext::ACTIVE;

        return inertia()->render('Admin/SharedApps/SharedAppView', [
            'app' => [
                'id' => $shared_app->id,
                'label' => $shared_app->label,
                'name' => $shared_app->application->name,
                'state' => $state,
                'status' => $shared_app->status,
                // Only an app that is deployed has a version of its own running
                'version' => $activated ? $shared_app->version->name : null,
                'domain' => $shared_app->domain(),
                // Without one it is at an address nobody is sent to
                'domain_chosen' => $shared_app->primary_domain_id !== null,
            ],
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
        return inertia()->render('Admin/SharedApps/SharedAppEdit', [
            'app' => [
                'id' => $shared_app->id,
                'label' => $shared_app->label,
                'domain' => $shared_app->primary_domain_id ?? 0,
            ],
            ...SharedAppContext::domainOptions($shared_app),
            'breadcrumbs' => [
                [
                    'url' => '/admin/service/shared-apps',
                    'label' => __('admin.shared_apps.shared_apps'),
                ],
                [
                    'url' => $this->viewUrl($shared_app),
                    'label' => $shared_app->label,
                ],
                [
                    'label' => __('admin.shared_apps.settings'),
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function domainRules(AppInstance $shared_app): array
    {
        return [
            'domain' => [
                'required',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === 0 || $value === 'new') {
                        return;
                    }

                    if (! OrgSubdomain::find($value)) {
                        $fail(__('organization.domain.denied.exists'));
                    }
                },
            ],
            'parent_domain' => 'nullable|required_if:domain,new|numeric|exists:org_domains,id',
            'subdomain' => ['nullable', 'required_if:domain,new', 'string', new OrgSubdomainAvailable($shared_app)],
        ];
    }

    /**
     * Points the shared app at the domain that was validated with domainRules().
     *
     * @param  array<string, mixed>  $validated
     */
    private function applyDomain(AppInstance $shared_app, array $validated): void
    {
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

        $shared_app->primary_domain_id = $new_subdomain?->id;
        $shared_app->save();

        // Nothing is deployed to point the domain at until the app is activated, which does it then
        if ($new_subdomain && $new_subdomain->id !== $current_subdomain?->id && $new_subdomain->domain->type === 'managed' && SharedAppContext::state($shared_app) === SharedAppContext::ACTIVE) {
            Action::execute(new UpdateDnsRecords($shared_app->organization, $new_subdomain->domain));
        }
    }

    public function update(Request $request, AppInstance $shared_app)
    {
        /* Validate */
        $validated = $request->validate([
            'label' => 'required|string|max:100',
            ...$this->domainRules($shared_app),
        ]);

        $shared_app->label = $validated['label'];
        $this->applyDomain($shared_app, $validated);

        return redirect($this->viewUrl($shared_app))->with('success', __('admin.shared_apps.updated', ['app' => $shared_app->name]));
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
