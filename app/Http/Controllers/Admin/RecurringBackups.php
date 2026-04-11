<?php

namespace App\Http\Controllers\Admin;

use App\Application;
use App\Http\Controllers\Controller;
use App\Organization;
use App\RecurringBackup;
use App\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RecurringBackups extends Controller
{
    public function index()
    {
        $backups = RecurringBackup::all();
        $servers = Server::all();
        $applications = Application::all();
        $organizations = Organization::all();

        return inertia()->render('Admin/Backups/RecurrentsList', [
            'backups' => $backups->map(function ($backup) {
                return [
                    'id' => $backup->id,
                    'recurrence' => $backup->recurrence,
                    'delete_after' => $backup->delete_after,
                    'delete_interval' => $backup->delete_interval,
                    'time' => $backup->time,
                    'organization' => $backup->organization ? [
                        'id' => $backup->organization->id,
                        'name' => $backup->organization->name,
                    ] : [],
                    'application' => $backup->application ? [
                        'id' => $backup->application->slug,
                        'name' => $backup->application->name,
                    ] : [],
                    'server' => [
                        'id' => $backup->server->id,
                        'name' => $backup->server->name,
                    ],
                    'type' => $backup->type,
                    'last_scheduled_at' => $backup->last_scheduled_at,
                    'status' => $backup->status,
                    'scheduled_at' => $backup->scheduled_at,
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
            'breadcrumbs' => [
                [
                    'label' => 'Backups',
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'time' => 'required|date',
            'recurrence' => 'required|in:daily,monthly',
            'keep_for' => 'required|integer',
            'keep_interval' => 'required|in:days,months,backups',
            'server' => 'required|integer|exists:servers,id',
            'organization' => 'nullable|integer|exists:organizations,id',
            'application' => 'nullable|integer|exists:applications,id',
        ]);

        $server = Server::find($validated['server']);

        $time = (new Carbon($validated['time']))->setTimezone('America/Vancouver')->format('H:i');

        $recurring_backups = new RecurringBackup;
        $recurring_backups->recurrence = $validated['recurrence'];
        $recurring_backups->time = $time;
        $recurring_backups->server_id = $validated['server'];
        $recurring_backups->delete_after = $validated['keep_for'];
        $recurring_backups->delete_interval = $validated['keep_interval'];
        $recurring_backups->type = $server->type;
        $recurring_backups->organization_id = array_key_exists('organization', $validated) ? $validated['organization'] : null;
        $recurring_backups->application_id = array_key_exists('application', $validated) ? $validated['application'] : null;
        $recurring_backups->save();

        return redirect('/admin/server/backup_scheduler/recurring')->with('success', 'Recurring backup added!');
    }

    public function update(Request $request, $backup_id) {}

    public function destroy(Request $request, $backup_id)
    {
        $backup = RecurringBackup::where('id', $backup_id)->delete();

        return redirect('/admin/server/backup_scheduler/recurring')->with('success', 'Recurring backup was deleted');
    }

    public function activate($backup_id)
    {
        $backup = RecurringBackup::where('id', $backup_id)->first();
        $backup->status = 'active';
        $backup->save();

        return redirect('/admin/server/backup_scheduler/recurring')->with('success', '');
    }

    public function deactivate($backup_id)
    {
        $backup = RecurringBackup::where('id', $backup_id)->first();
        $backup->status = 'inactive';
        $backup->save();

        return redirect('/admin/server/backup_scheduler/recurring')->with('success', '');
    }
}
