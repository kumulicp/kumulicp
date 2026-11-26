<?php

namespace App\Http\Controllers\Admin\Organizations;

use App\BackupSchedule;
use App\Http\Controllers\Controller;
use App\Organization;
use App\OrgBackup;
use App\Server;
use App\Services\OrgServerService;
use App\Support\Facades\Backup;
use App\Support\Facades\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BackupRestore extends Controller
{
    public function index(Organization $organization)
    {
        $backups = $organization->backups()->with(['app_instance.application', 'app_instance.database_server'])->orderBy('scheduled_at', 'desc')->paginate(30);

        return inertia()->render('Admin/Organizations/Backups/BackupsList', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'apps' => $organization->app_instances()->with('application')->get()->map(function ($app) {
                return [
                    'value' => $app->id,
                    'text' => $app->label." ({$app->application->name})",
                ];
            }),
            'supported_types' => Backup::supportedTypes(),
            'database_servers' => Server::where('type', 'database')
                ->orderBy('name')
                ->get()
                ->filter(fn (Server $server) => Backup::supportsServerTargets($server))
                ->map(fn (Server $server) => ['value' => $server->id, 'text' => $server->name])
                ->values(),
            'backups' => $backups->map(function ($backup) {
                return [
                    'id' => $backup->id,
                    'app' => [
                        'id' => $backup->app_instance?->id,
                        'name' => $backup->app_instance?->application->name,
                    ],
                    'name' => $backup->backup_name,
                    'action' => $backup->action,
                    'source' => $backup->source,
                    'type' => $backup->type,
                    'database_server_id' => $backup->app_instance?->database_server?->server_id,
                    'status' => $backup->status,
                    'completed_at' => $backup->completed_at,
                    'scheduled_at' => $backup->scheduled_at,
                ];
            }),
            'meta' => [
                'total' => $backups->total(),
                'pages' => $backups->lastPage(),
                'page' => $backups->currentPage(),
            ],
            'breadcrumbs' => Tenancy::isSingle()
                ? [['label' => __('labels.backups')]]
                : [
                    [
                        'label' => __('admin.organizations.organizations'),
                        'url' => '/admin/organizations',
                    ],
                    [
                        'label' => $organization->name,
                        'url' => '/admin/organizations/'.$organization->id,
                    ],
                    [
                        'label' => __('admin.organizations.details'),
                    ],
                ],
        ]);
    }

    public function store(Request $request, Organization $organization)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'time' => 'required|date',
            'date_time' => 'required|date|after:now',
            'backup' => 'required|integer',
            'backup_type' => 'required|string',
            'keep_for' => 'required|integer',
        ]);

        // Only what a driver exists for. Anything else would never run.
        if (! Backup::supports($validated['backup_type'])) {
            return redirect('/admin/organizations/'.$organization->id.'/backups')->with('error', __('admin.backups.denied.unsupported_type', ['type' => $validated['backup_type']]));
        }

        $app_instance = null;
        if ($validated['backup_type'] == 'email') {
            $org_server = $organization->domains()->where('id', $validated['backup'])->first()->email_server;
        } elseif ($validated['backup_type'] == 'web') {
            $app_instance = $organization->app_instances()->where('id', $validated['backup'])->first();
            $org_server = $app_instance->web_server;
        } elseif ($validated['backup_type'] == 'database') {
            $app_instance = $organization->app_instances()->where('id', $validated['backup'])->first();
            $org_server = $app_instance->database_server;
        } else {
            return redirect('/admin/organizations/'.$organization->slug.'/backups')->with('error', __('admin.backups.denied.exists'));
        }

        $backup_server = (new OrgServerService($org_server))->backupServer();

        if (! Backup::driverExists($backup_server->get())) {
            return redirect('/admin/organizations/'.$organization->id.'/backups')->with('error', __('admin.backups.denied.no_driver'));
        }

        $date_time = (new Carbon($validated['date_time']))->setTimezone(config('app.timezone'));

        $scheduled_backup = new BackupSchedule;
        $scheduled_backup->scheduled_at = $date_time;
        $scheduled_backup->save();

        $backup = Backup::schedule(
            $scheduled_backup,
            scheduled_at: $date_time,
            delete_after: $validated['keep_for'],
            type: $validated['backup_type'],
            organization: $organization,
            org_server: $backup_server->get(),
            app_instance: $validated['backup_type'] != 'email' ? $app_instance : null,
        );

        return redirect('/admin/organizations/'.$organization->id.'/backups')->with('success', __('admin.backups.scheduled'));
    }

    /**
     * Restores a backup, by default to the server it was taken from. For a
     * database backup, `database_server_id` restores it into another database
     * server instead (adding the app's database and user there); the app
     * itself keeps using its current server -- moving the app is what
     * ApplicationDatabaseMigrate is for.
     */
    public function restore(Request $request, Organization $organization, OrgBackup $backup)
    {
        abort_unless($backup->organization_id === $organization->id, 404);

        $validated = $request->validate([
            'database_server_id' => 'nullable|integer|exists:servers,id',
        ]);

        $redirect = "/admin/organizations/{$organization->id}/backups";
        $server = null;

        if ($validated['database_server_id'] ?? null) {
            $server = Server::findOrFail($validated['database_server_id']);

            if (! Backup::canRestoreTo($backup, $server)) {
                return redirect($redirect)->with('error', __('admin.backups.denied.restore_server'));
            }
        }

        Backup::queueRestore($backup, $server);

        return redirect($redirect)->with('success', $server ? __('admin.backups.restoring_to', ['server' => $server->name]) : __('admin.backups.restoring'));
    }

    public function destroy(Request $request, Organization $organization, OrgBackup $backup)
    {
        $redirect = $request->backup_scheduler_id ? '/admin/server/backup_scheduler/'.$request->backup_scheduler_id : "/admin/organizations/{$organization->id}/backups";

        $scheduled = $backup->scheduled_at;
        if ($backup->status === 'scheduled') {
            $backup->delete();

            return redirect($redirect)->with('success', __('admin.backups.cancelled', ['time' => $scheduled]));
        }

        return redirect($redirect)->with('failed', __('admin.backups.denied.delete', ['time' => $scheduled]));
    }
}
