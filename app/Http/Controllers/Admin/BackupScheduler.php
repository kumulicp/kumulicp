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
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

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
        $organizations = Organization::all();
        $applications = Application::all();
        $servers = Server::all();

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

        $date = (new Carbon($validated['date']))->setTimezone('America/Vancouver')->format('Y-m-d');
        $time = (new Carbon($validated['time']))->setTimezone('America/Vancouver')->format('H:i:s');

        $scheduled_at = "$date $time";

        $backup_schedule = new BackupSchedule;
        $backup_schedule->scheduled_at = $scheduled_at;
        $backup_schedule->save();

        $server = Server::find($validated['server']);
        $application_type = 'application_'.$server->type.'s';

        foreach ($server->org_servers as $org_server) {
            foreach ($org_server->$application_type as $app_instance) {
                if (! array_key_exists('application', $validated) || $app_instance->application_id != $validated['application']) {
                    $backup_server = AppFacade::instance($app_instance)->backupServer($server->type);
                    $backup = Backup::schedule(
                        $backup_schedule,
                        organization: $org_server->organization,
                        org_server: $backup_server->get(),
                        app_instance: $app_instance,
                        type: $server->type,
                        delete_after: $validated['keep_for'],
                        scheduled_at: $scheduled_at,
                    );
                }
            }
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
