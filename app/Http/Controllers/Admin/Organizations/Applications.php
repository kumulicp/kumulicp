<?php

namespace App\Http\Controllers\Admin\Organizations;

use App\Actions\Apps\ApplicationDatabaseMigrate;
use App\Actions\Apps\ApplicationDelete;
use App\Actions\Apps\ApplicationSsoCleanup;
use App\Actions\Apps\ApplicationSsoMove;
use App\Actions\Apps\ApplicationUpdate;
use App\Actions\Apps\ApplicationUpgrade;
use App\Actions\Apps\ApplicationWebCleanup;
use App\Actions\Apps\ApplicationWebMove;
use App\Actions\Apps\ServerMove\MoveBlockers;
use App\Actions\Steps\AwaitConfirmation;
use App\AppInstance;
use App\AppVersion;
use App\Http\Controllers\Controller;
use App\Organization;
use App\Server;
use App\Services\AppInstanceService;
use App\Services\OrgServerService;
use App\Support\Facades\Action;
use App\Support\AdminAppDomain;
use App\Support\AppMaintenance;
use App\Support\OrgAppBreadcrumbs;
use App\Support\Redactor;
use App\Support\SafeErrorMessage;
use App\Support\ServerChoices;
use App\Support\SharedAppContext;
use App\Task;
use App\Updates\PendingUpdates;
use App\Updates\ShadowedImages;
use App\Updates\UpdateSteps;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class Applications extends Controller
{
    // The servers an app can be pointed at, by the name the app calls them
    private const SERVER_TYPES = ['database', 'web', 'sso'];

    public function index(Organization $organization)
    {
        return inertia()->render('Admin/Organizations/Apps/AppsList', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'contact_name' => $organization->contact_first_name.' '.$organization->contact_last_name,
                'contact_email' => $organization->contact_email,
                'status' => $organization->status,
            ],
            'apps' => $organization->app_instances()->with(['organization', 'application', 'version', 'primary_domain'])->get()->map(function ($app) {
                return [
                    'id' => $app->id,
                    'name' => $app->label,
                    'version' => $app->version->name,
                    'domain' => [
                        'name' => $app->domain(),
                    ],
                    'status' => $app->status,
                ];
            }),
            'breadcrumbs' => [
                [
                    'label' => __('admin.organizations.organizations'),
                    'url' => '/admin/organizations',
                ],
                [
                    'label' => $organization->name,
                    'url' => '/admin/organizations/'.$organization->id,
                ],
                [
                    'label' => __('admin.applications.apps'),
                ],
            ],
        ]);
    }

    public function show(Organization $organization, AppInstance $app)
    {
        $app->load(['web_server.server', 'database_server.server', 'sso_server.server']);

        return inertia()->render('Admin/Organizations/Apps/AppView', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'app' => [
                'id' => $app->id,
                'name' => $app->application->name,
                'version' => [
                    'id' => $app->version->id,
                    'name' => $app->version->name,
                ],
                'settings' => $app->settings,
                'status' => $app->status,
                'password' => $app->api_password(),
                'plan' => [
                    'id' => $app->plan->id,
                    'name' => $app->plan->name,
                ],
            ],
            'updates' => app(PendingUpdates::class)->forInstance($app),
            'servers' => collect([
                'web' => $app->web_server,
                'database' => $app->database_server,
                'sso' => $app->sso_server,
            ])->filter()->map(fn ($org_server, $type) => [
                'type' => $type,
                'id' => $org_server->server->id,
                'name' => $org_server->server->name,
            ])->values(),
            'breadcrumbs' => OrgAppBreadcrumbs::for($organization, $app),
        ]);
    }

    /**
     * A shared app that is still to be set up has nothing to edit: it isn't deployed,
     * and takes its settings from its plan when it is. Leads back to its own page, or null.
     */
    private function notYetActivated(AppInstance $app): ?RedirectResponse
    {
        if (! $app->isSharedApp() || ! SharedAppContext::canBeActivated($app)) {
            return null;
        }

        return redirect("/admin/service/shared-apps/{$app->id}")->with('error', __('admin.shared_apps.errors.not_activated'));
    }

    public function edit(Organization $organization, AppInstance $app)
    {
        if ($redirect = $this->notYetActivated($app)) {
            return $redirect;
        }

        // Nothing is deployed for it: the domain is all there is to change
        $external = $app->isSharedApp() && SharedAppContext::state($app) === SharedAppContext::EXTERNAL;

        $versions = $external ? collect() : AppVersion::where('application_id', $app->application_id)
            ->get()
            ->filter(fn ($version) => version_compare($version->name, $app->version->name, '>'))
            ->sort(fn ($a, $b) => version_compare($a->name, $b->name))
            ->values();

        // Servers the app can be pointed at, per type: any active server, not only the ones
        // the organization is already on. Its own is always listed.
        $current = $this->currentServers($app);
        $serverOptions = collect(self::SERVER_TYPES)->mapWithKeys(fn ($type) => [
            $type => ServerChoices::options(ServerChoices::for($type, keep: $current[$type])),
        ]);

        return inertia()->render('Admin/Organizations/Apps/AppEdit', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'app' => [
                'id' => $app->id,
                'name' => $app->application->name,
                'version' => [
                    'id' => $app->version->id,
                    'name' => $app->version->name,
                ],
                'database_server' => $external ? null : $app->database_server?->server?->name,
                'web_server' => $external ? null : $app->web_server?->server?->name,
                'sso_server' => $external ? null : $app->sso_server?->server?->name,
                'settings' => $app->settings,
                'status' => $app->status,
                'password' => $app->api_password(),
                'plan' => [
                    'id' => $app->plan->id,
                    'name' => $app->plan->name,
                ],
                'managed_externally' => $external,
                // Without it there is no sign-in server to move
                'supports_sso' => $app->supportsSso(),
                'database_server_id' => $current['database'],
                'web_server_id' => $current['web'],
                'sso_server_id' => $current['sso'],
            ],
            'domain' => [
                'id' => $app->primary_domain_id ?? 0,
                'name' => $app->domain(),
                // Whether the app is built to have its domain changed
                'editable' => $app->plan->canUpdateDomain(),
                // Whether changing it changes a running app, which restarts it
                'deployed' => AdminAppDomain::isDeployed($app),
                ...AdminAppDomain::options($app),
            ],
            'maintenance' => [
                'enabled' => $app->inMaintenance(),
                // Whether the app is deployed in a way that can have a maintenance page
                'available' => ! $external && AppMaintenance::supported($app),
            ],
            'server_options' => $serverOptions,
            'versions' => $versions->map(function ($version) {
                return [
                    'id' => $version->id,
                    'version' => $version->name,
                ];
            }),
            'updates' => $external ? [] : app(PendingUpdates::class)->forInstance($app),
            'update_steps' => $external ? [] : app(UpdateSteps::class)->options($app),
            // Settings of the instance for an image its version sets itself, which are not used
            'ignored_image_settings' => $external ? [] : app(ShadowedImages::class)->for($app),
            // A task of the app that is waiting for an administrator to say something has been done
            'awaiting' => $external ? null : $this->awaiting($app),
            // Providers left on the servers the app signed in through before, to be removed
            'old_sso_entries' => $external ? [] : ApplicationSsoCleanup::pending($app)->map(fn (Task $move) => [
                'id' => $move->id,
                'server' => ApplicationSsoCleanup::previousServer($move)?->server?->name,
            ])->values(),
            // Releases left on the servers the app ran on before, to be removed
            'old_web_entries' => $external ? [] : ApplicationWebCleanup::pending($app)->map(fn (Task $move) => [
                'id' => $move->id,
                'server' => ApplicationWebCleanup::previousServer($move)?->server?->name,
            ])->values(),
            // The app servers it can be moved to, or could be once what it uses is moved to servers they can
            // reach (requirements), which is what the page takes the admin through
            'web_servers' => $external ? [] : ServerChoices::for('web')
                ->filter(fn (Server $server) => ! ApplicationWebMove::blockers($app, $server, with_reach: false))
                ->map(function (Server $server) use ($app) {
                    $requirements = ApplicationWebMove::requirements($app, $server);

                    return [
                        'value' => $server->id,
                        'text' => $server->name,
                        'ready' => collect($requirements)->every(fn (array $requirement) => $requirement['reachable']),
                        'requirements' => $requirements,
                    ];
                })->values(),
            'sso_servers' => $external ? [] : ServerChoices::for('sso')
                ->filter(fn (Server $server) => ! ApplicationSsoMove::blockers($app, $server))
                ->map(fn (Server $server) => [
                    'value' => $server->id,
                    'text' => $server->name,
                ])->values(),
            'database_servers' => $external ? [] : ApplicationDatabaseMigrate::targets($app)->map(fn (Server $server) => [
                'value' => $server->id,
                'text' => $server->name,
            ])->values(),
            'breadcrumbs' => OrgAppBreadcrumbs::for($organization, $app),
        ]);
    }

    public function updateDomain(Request $request, Organization $organization, AppInstance $app)
    {
        abort_unless($app->organization_id === $organization->id, 404);

        if ($redirect = $this->notYetActivated($app)) {
            return $redirect;
        }

        AdminAppDomain::apply($app, $request->validate(AdminAppDomain::rules($app)));

        return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}/edit")->with('success', __('admin.organizations.apps.domain_updated', ['app' => $app->label]));
    }

    public function maintenance(Request $request, Organization $organization, AppInstance $app)
    {
        abort_unless($app->organization_id === $organization->id, 404);

        if ($redirect = $this->notYetActivated($app)) {
            return $redirect;
        }

        $enable = (bool) $request->validate(['enabled' => 'required|boolean'])['enabled'];
        $edit = "/admin/organizations/{$organization->id}/apps/{$app->id}/edit";

        if ($blocker = AppMaintenance::blocker($app, $enable)) {
            return redirect($edit)->with('error', $blocker);
        }

        try {
            AppMaintenance::apply($app, $enable);
        } catch (\Throwable $e) {
            return redirect($edit)->with('error', SafeErrorMessage::from($e));
        }

        return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")
            ->with('success', __($enable ? 'admin.organizations.apps.maintenance_enabling' : 'admin.organizations.apps.maintenance_disabling', ['app' => $app->label]));
    }

    public function helmValues(Organization $organization, AppInstance $app)
    {
        $charts = (new AppInstanceService($app))->charts();

        return inertia()->render('Admin/Organizations/Apps/AppHelmValues', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'app' => [
                'id' => $app->id,
                'name' => $app->application->name,
                'version' => [
                    'id' => $app->version->id,
                    'name' => $app->version->name,
                ],
            ],
            'charts' => collect($charts)->map(fn ($chart) => [
                'name' => $chart->chartName(),
                'values' => Redactor::redact($chart->values()),
            ])->values(),
            'breadcrumbs' => OrgAppBreadcrumbs::for($organization, $app),
        ]);
    }

    public function update(Request $request, Organization $organization, AppInstance $app)
    {
        if ($redirect = $this->notYetActivated($app)) {
            return $redirect;
        }

        abort_unless($app->organization_id === $organization->id, 404);

        $current = $this->currentServers($app);

        $validated = $request->validate([
            'settings' => 'required|json',
            ...collect(self::SERVER_TYPES)->mapWithKeys(fn ($type) => [
                "{$type}_server_id" => ServerChoices::rules($type, keep: $current[$type]),
            ])->all(),
        ]);

        // Only a server that was sent, and is a different one, changes. Leaving one out,
        // or sending none, keeps the app where it is: an app with no database server
        // would not be able to start.
        $servers = collect(self::SERVER_TYPES)->filter(fn ($type) => ($validated["{$type}_server_id"] ?? null) !== null
            && (int) $validated["{$type}_server_id"] !== $current[$type]);

        // An app that is being updated, migrated or deleted is already being moved by a task
        // that expects the servers it started with
        if ($servers->isNotEmpty() && $app->status !== 'active') {
            return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}/edit")->with('error', __('admin.organizations.apps.denied.servers_not_active'));
        }

        $app->settings = json_decode($validated['settings'], true);
        // The organization may not be on the server yet
        $servers->each(fn ($type) => $app->{"{$type}_server_id"} = OrgServerService::forServer(
            $organization,
            Server::findOrFail($validated["{$type}_server_id"]),
        )->get()->id);
        $app->save();

        return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('success', __('admin.organizations.apps.updated', ['app' => $app->label]));
    }

    /**
     * The `Server` the app uses for each type, or null where it has none.
     *
     * @return array<string, int|null>
     */
    private function currentServers(AppInstance $app): array
    {
        return collect(self::SERVER_TYPES)->mapWithKeys(fn ($type) => [
            $type => $app->{"{$type}_server"}?->server_id,
        ])->all();
    }

    public function update_settings(Request $request, Organization $organization, AppInstance $app)
    {
        if ($redirect = $this->notYetActivated($app)) {
            return $redirect;
        }

        abort_unless($app->organization_id === $organization->id, 404);

        $available = app(UpdateSteps::class)->for($app)->keys()->all();

        $validated = $request->validate([
            'steps' => 'required|array|min:1',
            'steps.*' => ['string', Rule::in($available)],
        ]);

        $task = Action::execute(new ApplicationUpdate($app, $validated['steps']));

        return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('success', __('admin.organizations.apps.updating', ['app' => $app->label]));
    }

    public function migrate_database(Request $request, Organization $organization, AppInstance $app)
    {
        if ($redirect = $this->notYetActivated($app)) {
            return $redirect;
        }

        $validated = $request->validate([
            'database_server_id' => 'required|integer|exists:servers,id',
        ]);

        abort_unless($app->organization_id === $organization->id, 404);

        $server = Server::findOrFail($validated['database_server_id']);

        if ($blockers = ApplicationDatabaseMigrate::blockers($app, $server)) {
            return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('error', MoveBlockers::summary($blockers));
        }

        $task = Action::execute(new ApplicationDatabaseMigrate($app, $server));

        if (! $task) {
            return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('error', __('admin.organizations.apps.denied.migration_running'));
        }

        return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('success', __('admin.organizations.apps.migrating_database', ['app' => $app->label, 'server' => $server->name]));
    }

    public function move_sso(Request $request, Organization $organization, AppInstance $app)
    {
        if ($redirect = $this->notYetActivated($app)) {
            return $redirect;
        }

        $validated = $request->validate([
            'sso_server_id' => 'required|integer|exists:servers,id',
        ]);

        abort_unless($app->organization_id === $organization->id, 404);

        $server = Server::findOrFail($validated['sso_server_id']);

        if ($blockers = ApplicationSsoMove::blockers($app, $server)) {
            return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('error', MoveBlockers::summary($blockers));
        }

        $task = Action::execute(new ApplicationSsoMove($app, $server));

        if (! $task) {
            return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('error', __('admin.organizations.apps.denied.sso_move_running'));
        }

        return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('success', __('admin.organizations.apps.moving_sso', ['app' => $app->label, 'server' => $server->name]));
    }

    /**
     * @return array{task_id: int, message: string, form: array<string, mixed>|null}|null
     */
    private function awaiting(AppInstance $app): ?array
    {
        $task = AwaitConfirmation::waiting($app);

        if (! $task) {
            return null;
        }

        $step = $task->getValue('awaiting.class');

        return [
            'task_id' => $task->id,
            'message' => (string) $task->getValue('awaiting.message'),
            'form' => $step::form($task),
        ];
    }

    public function confirm_step(Request $request, Organization $organization, AppInstance $app)
    {
        abort_unless($app->organization_id === $organization->id, 404);

        $validated = $request->validate(['task_id' => 'required|integer']);
        $task = AwaitConfirmation::waiting($app);

        if (! $task || $task->id !== (int) $validated['task_id']) {
            return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('error', __('admin.organizations.apps.denied.nothing_to_confirm'));
        }

        // What the step asks for with the go-ahead is checked by the step
        $step = $task->getValue('awaiting.class');
        $step::confirm($task, $request->validate($step::rules($task)));

        return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('success', __('admin.organizations.apps.confirmed', ['app' => $app->label]));
    }

    public function move_web(Request $request, Organization $organization, AppInstance $app)
    {
        if ($redirect = $this->notYetActivated($app)) {
            return $redirect;
        }

        $validated = $request->validate([
            'web_server_id' => 'required|integer|exists:servers,id',
        ]);

        abort_unless($app->organization_id === $organization->id, 404);

        $server = Server::findOrFail($validated['web_server_id']);

        if ($blockers = ApplicationWebMove::blockers($app, $server)) {
            return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('error', MoveBlockers::summary($blockers));
        }

        $task = Action::execute(new ApplicationWebMove($app, $server));

        if (! $task) {
            return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('error', __('admin.organizations.apps.denied.web_move_running'));
        }

        return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('success', __('admin.organizations.apps.moving_web', ['app' => $app->label, 'server' => $server->name]));
    }

    public function remove_old_release(Request $request, Organization $organization, AppInstance $app)
    {
        if ($redirect = $this->notYetActivated($app)) {
            return $redirect;
        }

        $validated = $request->validate([
            'move_task_id' => 'required|integer',
        ]);

        abort_unless($app->organization_id === $organization->id, 404);

        $move = ApplicationWebCleanup::pending($app)->firstWhere('id', $validated['move_task_id']);

        if (! $move) {
            return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('error', __('messages.web_move.nothing_to_remove'));
        }

        $task = Action::execute(new ApplicationWebCleanup($app, $move));

        if (! $task) {
            return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('error', __('admin.organizations.apps.denied.web_cleanup_running'));
        }

        return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('success', __('admin.organizations.apps.removing_old_release', [
            'app' => $app->label,
            'server' => ApplicationWebCleanup::previousServer($move)?->server?->name,
        ]));
    }

    public function remove_old_sso(Request $request, Organization $organization, AppInstance $app)
    {
        if ($redirect = $this->notYetActivated($app)) {
            return $redirect;
        }

        $validated = $request->validate([
            'move_task_id' => 'required|integer',
        ]);

        abort_unless($app->organization_id === $organization->id, 404);

        $move = ApplicationSsoCleanup::pending($app)->firstWhere('id', $validated['move_task_id']);

        if (! $move) {
            return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('error', __('messages.sso_move.nothing_to_remove'));
        }

        $task = Action::execute(new ApplicationSsoCleanup($app, $move));

        if (! $task) {
            return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('error', __('admin.organizations.apps.denied.sso_cleanup_running'));
        }

        return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('success', __('admin.organizations.apps.removing_old_sso', [
            'app' => $app->label,
            'server' => ApplicationSsoCleanup::previousServer($move)?->server?->name,
        ]));
    }

    public function run(Organization $organization, AppInstance $app, $action)
    {
        $task = $action->createTask($app);

        return redirect(route('organizations.app', ['organization_id' => $organization->id, 'app' => $app->id]))->with('success', __('admin.organizations.apps.run_job', ['job' => $action->name, 'app' => $app->application->name]));
    }

    public function activate(Organization $organization, $app, $version) {}

    public function upgrade(Organization $organization, AppInstance $app, AppVersion $version)
    {
        // TODO: Add check that version is compatible, current or more recent
        if ($version->application_id != $app->application_id) {
            return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('error', __('admin.organizations.apps.denied.version_exists'));
        }

        $notify = true;

        if ($app->version_id === $version->id || $version->announcement_location === 'none') {
            $notify = false;
        }

        Action::execute(new ApplicationUpgrade($app, $version, null, $notify));

        return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('success', __('admin.organizations.apps.upgrading', ['app' => $app->label]));

    }

    public function delete(Request $request, Organization $organization, AppInstance $app)
    {
        $validated = $request->validate([
            'when' => 'required|in:now,later',
            'start_time' => 'required_if:when,later',
            'end_time' => 'required_if:when,later',
        ]);

        $start = $validated['when'] === 'later' ? $validated['start_time'] : null;
        $end = $validated['when'] === 'later' ? $validated['end_time'] : null;

        try {
            $task = Action::execute(new ApplicationDelete($app, start_time: $start, end_time: $end));
        } catch (\Throwable $e) {
            return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('error', SafeErrorMessage::from($e));
        }

        return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('success', __('admin.organizations.apps.deleting', ['app' => $app->label]));
    }
}
