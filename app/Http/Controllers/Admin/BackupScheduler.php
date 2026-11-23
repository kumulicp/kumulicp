<?php

namespace App\Http\Controllers\Admin;

use App\Application;
use App\BackupSchedule;
use App\Http\Controllers\Controller;
use App\Organization;
use App\OrgBackup;
use App\Server;
use App\Support\Facades\Application as AppFacade;
use App\Support\Facades\Backup;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Response;

class BackupScheduler extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $backups = BackupSchedule::orderBy('scheduled_at', 'desc')->paginate(30);
        $organizations = Organization::notTest()->get();
        $applications = Application::all();
        $servers = Server::all()->filter(fn (Server $server) => Backup::supports($server->type))->values();

        return inertia()->render('Admin/Backups/SchedulesList', [
            'backups' => $backups->map(function ($backup) {
                return [
                    'id' => $backup->id,
                    'scheduled_at' => $backup->scheduled_at,
                    'apps' => $backup->backups()->count(),
                ];
            }),
            'organizations' => $organizations->map(function ($organization) {
                return [
                    'id' => $organization->id,
                    'name' => $organization->name,
                ];
            }),
            'applications' => $applications->map(function ($app) {
                return [
                    'id' => $app->id,
                    'name' => $app->name,
                ];
            }),
            'servers' => $servers->map(function ($server) {
                return [
                    'id' => $server->id,
                    'name' => $server->name,
                ];
            }),
            'meta' => [
                'total' => $backups->total(),
                'pages' => $backups->lastPage(),
                'page' => $backups->currentPage(),
            ],
            'breadcrumbs' => [
                [
                    'label' => __('admin.backups.backups'),
                ],
            ],
        ]);
    }

    public function show(BackupSchedule $backup_scheduler)
    {
        $backups = OrgBackup::where('scheduled_backup_id', $backup_scheduler->id)->with('organization')->get();

        return inertia()->render('Admin/Backups/BackupsList', [
            'scheduler' => [
                'id' => $backup_scheduler->id,
            ],
            'backups' => $backups->map(function ($backup) {
                return [
                    'id' => $backup->id,
                    'type' => $backup->type,
                    'completed_at' => $backup->completed_at,
                    'scheduled_at' => $backup->scheduled_at,
                    'status' => $backup->status,
                    'organization' => [
                        'id' => $backup->organization->id,
                        'name' => $backup->organization->name,
                    ],
                    'app' => [
                        'id' => $backup->app_instance->id,
                        'label' => $backup->app_instance->label,
                    ],
                ];
            }),
            'breadcrumbs' => [
                [
                    'label' => __('admin.backups.backups'),
                    'url' => '/admin/server/backup_scheduler',
                ],
                [
                    'label' => $backup_scheduler->scheduled_at,
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'time' => 'required|date',
            'date_time' => 'required|date|after:now',
            'keep_for' => 'required|integer',
            'server' => 'required|exists:servers,id',
            'application' => 'nullable|integer|exists:applications,id',
            'organization' => 'nullable|integer|exists:organizations,id',
        ]);

        $server = Server::find($validated['server']);

        // Only what a driver exists for. Anything else would never run.
        if (! Backup::supports($server->type)) {
            return redirect('/admin/server/backup_scheduler')->with('error', __('admin.backups.denied.unsupported_type', ['type' => $server->type]));
        }

        $date = (new Carbon($validated['date']))->setTimezone('America/Vancouver')->format('Y-m-d');
        $time = (new Carbon($validated['time']))->setTimezone('America/Vancouver')->format('H:i:s');

        $scheduled_at = "$date $time";

        $backup_schedule = new BackupSchedule;
        $backup_schedule->scheduled_at = $scheduled_at;
        $backup_schedule->save();

        $application_type = 'application_'.$server->type.'s';
        $scheduled = 0;

        foreach ($server->org_servers as $org_server) {
            foreach ($org_server->$application_type as $app_instance) {
                if (! array_key_exists('application', $validated) || $app_instance->application_id != $validated['application']) {
                    $backup_server = AppFacade::instance($app_instance)->backupServer($server->type);

                    // An app whose backup server has no driver is left out, and counted below
                    if (! Backup::driverExists($backup_server->get())) {
                        continue;
                    }

                    Backup::schedule(
                        $backup_schedule,
                        organization: $org_server->organization,
                        org_server: $backup_server->get(),
                        app_instance: $app_instance,
                        type: $server->type,
                        delete_after: $validated['keep_for'],
                        scheduled_at: $scheduled_at,
                    );
                    $scheduled++;
                }
            }
        }

        if ($scheduled === 0) {
            $backup_schedule->delete();

            return redirect('/admin/server/backup_scheduler')->with('error', __('admin.backups.denied.nothing_scheduled'));
        }

        return redirect('/admin/server/backup_scheduler')->with('success', __('admin.backups.added', ['time' => $scheduled_at]));
    }

    public function destroy($scheduled_backup)
    {
        $scheduled_backup = BackupSchedule::find($scheduled_backup);
        if ($scheduled_backup->scheduled_at > now()) {
            $scheduled_backup->backups()->delete();
            $scheduled_backup->delete();

            return redirect('/admin/server/backup_scheduler')->with('success', __('admin.backups.cancelled', ['time' => $scheduled_backup->scheduled_at]));
        }

        return redirect('/admin/server/backup_scheduler')->with('error', __('admin.backups.denied.delete', ['time' => $scheduled_backup->scheduled_at]));
    }
}
