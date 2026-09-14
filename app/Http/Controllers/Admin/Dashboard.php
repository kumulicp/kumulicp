<?php

namespace App\Http\Controllers\Admin;

use App\AppInstance;
use App\Application;
use App\FailedJob;
use App\Http\Controllers\Controller;
use App\Log;
use App\Organization;
use App\Plan;
use App\SecurityFinding;
use App\SecurityScan;
use App\Server;
use App\Task;
use Illuminate\Support\Carbon;

class Dashboard extends Controller
{
    public function index()
    {
        $now = Carbon::now();

        $organizations = [
            'total' => Organization::count(),
            'active' => Organization::where('status', 'active')->count(),
            'new' => Organization::where('status', 'new')->count(),
            'deactivated' => Organization::where('status', 'deactivated')->count(),
            'trialing' => Organization::whereNotNull('trial_ends_at')->where('trial_ends_at', '>=', $now)->count(),
            'new_last_30_days' => Organization::where('created_at', '>=', $now->copy()->subDays(30))->count(),
            'suborganizations' => Organization::whereNotNull('parent_organization_id')->count(),
        ];

        $plans = Plan::withCount('subscribers')
            ->orderByDesc('subscribers_count')
            ->get()
            ->map(fn ($plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'status' => $plan->status,
                'is_default' => (bool) $plan->is_default,
                'archive' => (bool) $plan->archive,
                'subscribers' => $plan->subscribers_count,
            ]);

        $app_usage = $this->appUsage();

        $task_statuses = Task::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $recent_failed_tasks = Task::where('status', 'failed')
            ->with(['organization', 'application'])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get()
            ->map(fn ($task) => [
                'id' => $task->id,
                'organization' => $task->organization->name ?? '',
                'application' => $task->application->name ?? '',
                'description' => $task->description,
                'error_message' => $task->error_message,
                'time' => optional($task->updated_at)->diffForHumans(),
            ]);

        $failed_jobs_count = FailedJob::count();

        $security_findings = SecurityFinding::unresolved()
            ->selectRaw('severity, count(*) as total')
            ->groupBy('severity')
            ->pluck('total', 'severity');

        $recent_failed_scans = SecurityScan::where('status', 'failed')
            ->with('org_server')
            ->orderByDesc('finished_at')
            ->limit(5)
            ->get()
            ->map(fn ($scan) => [
                'id' => $scan->id,
                'server' => $scan->org_server->name ?? '',
                'tool' => $scan->tool,
                'error_message' => $scan->error_message,
                'time' => optional($scan->finished_at)->diffForHumans(),
            ]);

        $error_logs_last_day = Log::whereIn('level', ['error', 'critical', 'alert', 'emergency'])
            ->where('created_at', '>=', $now->copy()->subDay())
            ->count();

        $servers_pending = Server::where('status', '!=', 'active')->count();

        $applications_total = Application::count();
        $applications_enabled = Application::where('enabled', true)->count();

        return inertia()->render('Admin/Dashboard/Overview', [
            'organizations' => $organizations,
            'applications' => [
                'total' => $applications_total,
                'enabled' => $applications_enabled,
            ],
            'plans' => $plans,
            'app_usage' => $app_usage,
            'task_statuses' => $task_statuses,
            'recent_failed_tasks' => $recent_failed_tasks,
            'security_findings' => $security_findings,
            'recent_failed_scans' => $recent_failed_scans,
            'warnings' => $this->buildWarnings(
                $failed_jobs_count,
                (int) ($task_statuses['failed'] ?? 0),
                $security_findings,
                $servers_pending,
                $error_logs_last_day
            ),
            'stats' => [
                'failed_jobs' => $failed_jobs_count,
                'servers_pending' => $servers_pending,
                'error_logs_last_day' => $error_logs_last_day,
            ],
            'breadcrumbs' => [
                [
                    'label' => __('admin.dashboard.dashboard'),
                ],
            ],
        ]);
    }

    private function appUsage()
    {
        $rows = AppInstance::selectRaw('application_id, status, count(*) as total')
            ->groupBy('application_id', 'status')
            ->get()
            ->groupBy('application_id');

        $applications = Application::whereIn('id', $rows->keys())->get()->keyBy('id');

        return $rows->map(function ($status_rows, $application_id) use ($applications) {
            $counts = $status_rows->pluck('total', 'status');

            return [
                'id' => (int) $application_id,
                'name' => $applications[$application_id]->name ?? __('labels.unknown'),
                'active' => (int) ($counts['active'] ?? 0),
                'total' => (int) $status_rows->sum('total'),
            ];
        })->sortByDesc('total')->values();
    }

    private function buildWarnings(
        int $failed_jobs_count,
        int $failed_tasks_count,
        $security_findings,
        int $servers_pending,
        int $error_logs_last_day
    ) {
        $warnings = [];

        if ($failed_jobs_count > 0) {
            $warnings[] = [
                'level' => 'danger',
                'message' => __('admin.dashboard.warning_messages.failed_jobs', ['count' => $failed_jobs_count]),
                'url' => '/admin/server/tasks',
            ];
        }

        if ($failed_tasks_count > 0) {
            $warnings[] = [
                'level' => 'warning',
                'message' => __('admin.dashboard.warning_messages.failed_tasks', ['count' => $failed_tasks_count]),
                'url' => '/admin/server/tasks',
            ];
        }

        $critical_findings = (int) ($security_findings['critical'] ?? 0);
        if ($critical_findings > 0) {
            $warnings[] = [
                'level' => 'danger',
                'message' => __('admin.dashboard.warning_messages.critical_findings', ['count' => $critical_findings]),
                'url' => '/admin/server/security/scans',
            ];
        }

        $high_findings = (int) ($security_findings['high'] ?? 0);
        if ($high_findings > 0) {
            $warnings[] = [
                'level' => 'warning',
                'message' => __('admin.dashboard.warning_messages.high_findings', ['count' => $high_findings]),
                'url' => '/admin/server/security/scans',
            ];
        }

        if ($servers_pending > 0) {
            $warnings[] = [
                'level' => 'warning',
                'message' => __('admin.dashboard.warning_messages.servers_pending', ['count' => $servers_pending]),
                'url' => '/admin/server/servers',
            ];
        }

        if ($error_logs_last_day > 0) {
            $warnings[] = [
                'level' => 'warning',
                'message' => __('admin.dashboard.warning_messages.error_logs', ['count' => $error_logs_last_day]),
                'url' => '/admin/server/logs',
            ];
        }

        return $warnings;
    }
}
