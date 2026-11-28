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
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Organization;
use App\Server;
use App\Services\AppInstanceService;
use App\Services\OrgServerService;
use App\Support\AdminAppDomain;
use App\Support\AppMaintenance;
use App\Support\Facades\Action;
use App\Support\OrgAppBreadcrumbs;
use App\Support\SafeErrorMessage;
use App\Support\ServerChoices;
use App\Support\ServerReach;
use App\Support\SharedAppContext;
use App\Support\StorageHandover;
use App\Support\Volumes\Volumes;
use App\Support\Volumes\VolumesForm;
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
            'volumes' => VolumesForm::fields($app, Volumes::for($app)),
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

    public function updateVolumes(Request $request, Organization $organization, AppInstance $app)
    {
        abort_unless($app->organization_id === $organization->id, 404);

        if ($redirect = $this->notYetActivated($app)) {
            return $redirect;
        }

        $volumes = Volumes::for($app);
        $validated = $request->validate(VolumesForm::rules($volumes));

        VolumesForm::apply($volumes, $validated['volumes']);

        return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}/edit")->with('success', __('admin.volumes.updated', ['app' => $app->label]));
    }

    public function helmValues(Organization $organization, AppInstance $app)
    {
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
            // What a release is given
            'charts' => (new AppInstanceService($app))->renderedCharts(),
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
            return redirect($this->backTo($request, $organization, $app))->with('error', MoveBlockers::summary($blockers));
        }

        $task = Action::execute(new ApplicationDatabaseMigrate($app, $server));

        if (! $task) {
            return redirect($this->backTo($request, $organization, $app))->with('error', __('admin.organizations.apps.denied.migration_running'));
        }

        return redirect($this->backTo($request, $organization, $app))->with('success', __('admin.organizations.apps.migrating_database', ['app' => $app->label, 'server' => $server->name]));
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
            return redirect($this->backTo($request, $organization, $app))->with('error', MoveBlockers::summary($blockers));
        }

        $task = Action::execute(new ApplicationSsoMove($app, $server));

        if (! $task) {
            return redirect($this->backTo($request, $organization, $app))->with('error', __('admin.organizations.apps.denied.sso_move_running'));
        }

        return redirect($this->backTo($request, $organization, $app))->with('success', __('admin.organizations.apps.moving_sso', ['app' => $app->label, 'server' => $server->name]));
    }

    /**
     * Where an action goes back to: the Move tab when it was started from there (with the app server
     * being moved to), and the app's page otherwise.
     */
    private function backTo(Request $request, Organization $organization, AppInstance $app): string
    {
        $base = "/admin/organizations/{$organization->id}/apps/{$app->id}";

        return $request->filled('move_target') ? "{$base}/move?target=".(int) $request->input('move_target') : $base;
    }

    /**
     * The tab for moving an app to another app server, in steps: the database to a server the new app
     * server can reach, the sign-in server kept or changed to one it can, then the app server, with the
     * settings for its volume. What each step is in is worked out from the servers, so the page is the
     * same wherever it is left and came back to.
     */
    public function move(Request $request, Organization $organization, AppInstance $app)
    {
        abort_unless($app->organization_id === $organization->id, 404);

        if ($redirect = $this->notYetActivated($app)) {
            return $redirect;
        }

        // Nothing is deployed for it
        if ($app->isSharedApp() && SharedAppContext::state($app) === SharedAppContext::EXTERNAL) {
            return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}");
        }

        // Not only the ones it can be moved to now: the steps are what makes the rest possible. And not
        // while it is active: a step that is running is what it is not
        $targets = ServerChoices::for('web')
            ->filter(fn (Server $server) => ! ApplicationWebMove::blockers($app, $server, require_active: false, with_reach: false))
            ->values();
        $target = $targets->firstWhere('id', (int) $request->query('target'));
        $app_server = $app->web_server?->server;

        return inertia()->render('Admin/Organizations/Apps/AppMove', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'app' => [
                'id' => $app->id,
                'name' => $app->application->name,
                'status' => $app->status,
                'web_server' => $app_server?->name,
                'database_server' => $app->database_server?->server?->name,
                'sso_server' => $app->sso_server?->server?->name,
                'supports_sso' => $app->supportsSso(),
            ],
            'targets' => $targets->map(fn (Server $server) => ['value' => $server->id, 'text' => $server->name])->values(),
            'target' => $target ? ['value' => $target->id, 'text' => $target->name] : null,
            'steps' => $target && $app_server ? $this->moveSteps($app, $app_server, $target) : null,
            // What is being done to the app now, so that the page can say so and look again
            'running' => $this->runningMoves($app),
            'awaiting' => $this->awaiting($app),
            // The apps that run in its release go down with it, so they are listed for the move to be agreed to
            'children' => $this->releaseChildren($app),
            'breadcrumbs' => OrgAppBreadcrumbs::for($organization, $app),
        ]);
    }

    /**
     * The apps that run in the app's release (the apps shared from a shared app, an addon), which a web move
     * stops along with it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function releaseChildren(AppInstance $app): array
    {
        return $app->children()->notDeactivated()->with(['organization', 'primary_domain.domain'])->get()
            ->map(fn (AppInstance $child) => [
                'id' => $child->id,
                'label' => $child->label,
                'organization' => $child->organization?->name,
                'domain' => $child->domain(),
                // A domain that is not managed here has to be pointed at the new server by hand
                'managed' => $child->primary_domain?->domain?->type === 'managed',
            ])->values()->all();
    }

    /**
     * What each step of moving the app to $target needs: the server the app has for it, whether the new
     * app server can reach it, and the servers it can be moved to, with the app, that the new app server
     * can reach. The old app server is not updated by the move, so it does not have to reach them. Each can
     * be kept when it can already be reached, or changed.
     *
     * @return array<string, mixed>
     */
    private function moveSteps(AppInstance $app, Server $app_server, Server $target): array
    {
        $requirements = collect(ApplicationWebMove::requirements($app, $target))->keyBy('type');

        $step = function (string $type) use ($app, $target, $requirements) {
            $requirement = $requirements->get($type);

            return [
                'server' => $requirement['server'] ?? null,
                'reachable' => $requirement['reachable'] ?? true,
                'options' => ServerReach::reachableFromAll($type, [$target])
                    ->filter(fn (Server $server) => ! ($type === 'database'
                        ? ApplicationDatabaseMigrate::blockers($app, $server, with_reach: false)
                        : ApplicationSsoMove::blockers($app, $server, with_reach: false)))
                    ->map(fn (Server $server) => ['value' => $server->id, 'text' => $server->name])
                    ->values(),
            ];
        };

        return [
            'database' => $step('database'),
            'sso' => $step('sso'),
            'app' => [
                'ready' => $requirements->every(fn (array $requirement) => $requirement['reachable']),
                // What the chart can be given for its volume
                'storage' => [
                    'supports_existing' => StorageHandover::supports($app),
                    'fields' => StorageHandover::fields($app),
                    'access_modes' => StorageHandover::ACCESS_MODES,
                ],
            ],
        ];
    }

    /**
     * The moves of the app that are being done: by what they move.
     *
     * @return array<string, array{task_id: int, description: string}|null>
     */
    private function runningMoves(AppInstance $app): array
    {
        $slugs = ['database' => 'application_database_migrate', 'sso' => 'application_sso_move', 'web' => 'application_web_move'];

        $open = Task::where('app_instance_id', $app->id)
            ->whereIn('action_slug', array_values($slugs))
            ->whereIn('status', TaskStatus::openValues())
            ->get();

        return collect($slugs)->map(fn (string $slug) => ($task = $open->firstWhere('action_slug', $slug))
            ? ['task_id' => $task->id, 'description' => (string) $task->description]
            : null)->all();
    }

    /**
     * @return array{task_id: int, message: string}|null
     */
    private function awaiting(AppInstance $app): ?array
    {
        $task = AwaitConfirmation::waiting($app);

        if (! $task) {
            return null;
        }

        return [
            'task_id' => $task->id,
            'message' => (string) $task->getValue('awaiting.message'),
        ];
    }

    public function confirm_step(Request $request, Organization $organization, AppInstance $app)
    {
        abort_unless($app->organization_id === $organization->id, 404);

        $validated = $request->validate(['task_id' => 'required|integer']);
        $task = AwaitConfirmation::waiting($app);

        if (! $task || $task->id !== (int) $validated['task_id']) {
            return redirect($this->backTo($request, $organization, $app))->with('error', __('admin.organizations.apps.denied.nothing_to_confirm'));
        }

        // What the step asks for with the go-ahead is checked by the step
        $step = $task->getValue('awaiting.class');
        $step::confirm($task, $request->validate($step::rules($task)));

        return redirect($this->backTo($request, $organization, $app))->with('success', __('admin.organizations.apps.confirmed', ['app' => $app->label]));
    }

    public function move_web(Request $request, Organization $organization, AppInstance $app)
    {
        if ($redirect = $this->notYetActivated($app)) {
            return $redirect;
        }

        $validated = $request->validate([
            'web_server_id' => 'required|integer|exists:servers,id',
            // Servers to move the app's database and sign-in to along with it, ones the new app server can reach
            'database_server_id' => 'nullable|integer|exists:servers,id',
            'sso_server_id' => 'nullable|integer|exists:servers,id',
            // What the chart is to be given for the app's volume, chosen now so that the move does not stop to ask
            'storage' => 'required|array',
            ...StorageHandover::rulesFor($app, 'storage.'),
            // The apps that run in its release are stopped with it, which is agreed to
            'acknowledge' => $app->children()->notDeactivated()->exists() ? 'accepted' : 'nullable|boolean',
        ]);

        abort_unless($app->organization_id === $organization->id, 404);

        $server = Server::findOrFail($validated['web_server_id']);
        $database_server = isset($validated['database_server_id']) ? Server::findOrFail($validated['database_server_id']) : null;
        $sso_server = isset($validated['sso_server_id']) ? Server::findOrFail($validated['sso_server_id']) : null;

        if ($blockers = ApplicationWebMove::blockers($app, $server, database_server: $database_server, sso_server: $sso_server)) {
            return redirect($this->backTo($request, $organization, $app))->with('error', MoveBlockers::summary($blockers));
        }

        $task = Action::execute(new ApplicationWebMove($app, $server, $validated['storage'], $database_server, $sso_server));

        if (! $task) {
            return redirect($this->backTo($request, $organization, $app))->with('error', __('admin.organizations.apps.denied.web_move_running'));
        }

        $children = $app->children()->notDeactivated()->count();

        return redirect($this->backTo($request, $organization, $app))->with('success', $children
            ? __('admin.organizations.apps.moving_web_children', ['app' => $app->label, 'server' => $server->name, 'count' => $children])
            : __('admin.organizations.apps.moving_web', ['app' => $app->label, 'server' => $server->name]));
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

        // An install is only upgraded within the group its plan picked
        $group = $app->plan?->versionGroup() ?? $app->application->defaultVersionGroup();

        if ($version->version_group_id !== $group->id) {
            return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('error', __('admin.organizations.apps.denied.version_group'));
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
