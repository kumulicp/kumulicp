<?php

namespace App\Http\Controllers\Admin\Organizations;

use App\AppInstance;
use App\Exceptions\NoBackupDriverException;
use App\Http\Controllers\Controller;
use App\Organization;
use App\OrgBackup;
use App\Server;
use App\Support\Facades\Backup;
use App\Support\OrgAppBreadcrumbs;
use Illuminate\Http\Request;

/**
 * The backups of one app instance: what has been taken, backing it up right
 * now, and restoring a backup. Scheduling backups ahead of time stays with the
 * organization's backups tab and the backup scheduler.
 */
class AppBackups extends Controller
{
    // How long a backup taken from here is kept, unless asked otherwise
    private const DEFAULT_KEEP_DAYS = 30;

    public function index(Organization $organization, AppInstance $app)
    {
        $this->authorizeApp($organization, $app);

        $backups = $app->backups()->orderByDesc('scheduled_at')->orderByDesc('id')->paginate(30);

        return inertia()->render('Admin/Organizations/Apps/AppBackups', [
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
            'can_backup' => Backup::databaseBackupServerFor($app) !== null,
            'keep_days' => self::DEFAULT_KEEP_DAYS,
            'database_servers' => Server::where('type', 'database')
                ->orderBy('name')
                ->get()
                ->filter(fn (Server $server) => Backup::supportsServerTargets($server))
                ->map(fn (Server $server) => ['value' => $server->id, 'text' => $server->name])
                ->values(),
            'backups' => $backups->map(fn (OrgBackup $backup) => [
                'id' => $backup->id,
                'name' => $backup->backup_name,
                'action' => $backup->action,
                'type' => $backup->type,
                'status' => $backup->status,
                'scheduled_at' => $backup->scheduled_at,
                'completed_at' => $backup->completed_at,
            ])->values(),
            'meta' => [
                'total' => $backups->total(),
                'pages' => $backups->lastPage(),
                'page' => $backups->currentPage(),
            ],
            'breadcrumbs' => OrgAppBreadcrumbs::for($organization, $app),
        ]);
    }

    /**
     * Backs the app up as soon as the backup runner next looks.
     */
    public function store(Request $request, Organization $organization, AppInstance $app)
    {
        $this->authorizeApp($organization, $app);

        $validated = $request->validate([
            'keep_for' => 'nullable|integer|min:1|max:3650',
        ]);

        try {
            Backup::runNow($app, (int) ($validated['keep_for'] ?? self::DEFAULT_KEEP_DAYS));
        } catch (NoBackupDriverException) {
            return redirect($this->backTo($organization, $app))->with('error', __('admin.backups.denied.no_driver'));
        }

        return redirect($this->backTo($organization, $app))->with('success', __('admin.backups.running'));
    }

    /**
     * Restores one of the app's completed backups, by default to the server it
     * was taken from, or into another database server (see BackupRestore).
     */
    public function restore(Request $request, Organization $organization, AppInstance $app, OrgBackup $backup)
    {
        $this->authorizeApp($organization, $app);
        abort_unless($backup->app_instance_id === $app->id, 404);

        $validated = $request->validate([
            'database_server_id' => 'nullable|integer|exists:servers,id',
        ]);

        // Only a backup that finished has anything to restore
        if ($backup->action !== 'backup' || $backup->status !== 'completed') {
            return redirect($this->backTo($organization, $app))->with('error', __('admin.backups.denied.restore_incomplete'));
        }

        $server = null;
        if ($validated['database_server_id'] ?? null) {
            $server = Server::findOrFail($validated['database_server_id']);

            if (! Backup::canRestoreTo($backup, $server)) {
                return redirect($this->backTo($organization, $app))->with('error', __('admin.backups.denied.restore_server'));
            }
        }

        Backup::queueRestore($backup, $server);

        return redirect($this->backTo($organization, $app))->with('success', $server ? __('admin.backups.restoring_to', ['server' => $server->name]) : __('admin.backups.restoring'));
    }

    private function authorizeApp(Organization $organization, AppInstance $app): void
    {
        abort_unless($app->organization_id === $organization->id, 404);
    }

    private function backTo(Organization $organization, AppInstance $app): string
    {
        return "/admin/organizations/{$organization->id}/apps/{$app->id}/backups";
    }
}
