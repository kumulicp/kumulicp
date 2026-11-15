<?php

namespace App\Http\Controllers\Admin;

use App\AppVersion;
use App\Enums\VersionTestRunStatus;
use App\Enums\VersionTestStepStatus;
use App\Http\Controllers\Controller;
use App\VersionTestRun;
use App\VersionTestStep;
use Illuminate\Support\Collection;

/**
 * A dashboard of the version test system: how many tests are going, which need
 * the admin, how recent runs ended, the runs that are still in progress and the
 * versions that are waiting to be tested.
 */
class TestsOverview extends Controller
{
    // How far back the passed, failed and bypassed counts look
    private const RECENT_DAYS = 30;

    public function index()
    {
        $runs = VersionTestRun::where('status', VersionTestRunStatus::RUNNING->value)
            ->with(['application', 'version', 'started_by', 'steps.plan', 'steps.from_version'])
            ->orderBy('id')
            ->get();

        $finished = VersionTestRun::where('finished_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $untested = $this->untestedVersions();

        return inertia()->render('Admin/Tests/TestsOverview', [
            'stats' => [
                'running' => $runs->count(),
                'untested' => $untested->count(),
                'awaiting' => VersionTestStep::where('status', VersionTestStepStatus::AWAITING_CONFIRMATION->value)->count(),
                'passed' => (int) ($finished[VersionTestRunStatus::PASSED->value] ?? 0),
                'failed' => (int) ($finished[VersionTestRunStatus::FAILED->value] ?? 0),
                'bypassed' => (int) ($finished[VersionTestRunStatus::BYPASSED->value] ?? 0),
                'days' => self::RECENT_DAYS,
            ],
            'runs' => $runs->map(fn (VersionTestRun $run) => $this->runProps($run))->values(),
            'untested_versions' => $untested->map(fn (AppVersion $version) => [
                'id' => $version->id,
                'app' => $version->application->name,
                'version' => $version->name,
                'gate' => $version->testGate(),
                'created_at' => $version->created_at?->format('Y-m-d H:i:s'),
                'url' => '/admin/apps/'.$version->application->slug.'/versions/'.$version->name.'/tests',
            ])->values(),
            'breadcrumbs' => [
                ['label' => __('admin.tests.tests')],
            ],
        ]);
    }

    /**
     * Versions that cannot be enabled yet because they were never tested, or
     * changed since they were. Only versions newer than the enabled one count:
     * older ones were replaced long ago and are not waiting for anything. Apps
     * that are not enabled are left out: nobody is about to enable their versions.
     *
     * @return Collection<int, AppVersion>
     */
    private function untestedVersions(): Collection
    {
        $enabled = AppVersion::where('status', 'active')->get()->groupBy('application_id')->map(fn ($versions) => $versions->max('id'));

        return AppVersion::where('status', '!=', 'active')
            ->whereHas('application', fn ($query) => $query->where('enabled', true))
            ->with('application')
            ->orderBy('id')
            ->get()
            ->filter(fn (AppVersion $version) => $version->id > ($enabled[$version->application_id] ?? 0))
            ->filter(fn (AppVersion $version) => in_array($version->testGate(), ['untested', 'stale'], true));
    }

    /**
     * @return array<string, mixed>
     */
    private function runProps(VersionTestRun $run): array
    {
        $steps = $run->steps;
        $current = $steps->first(fn (VersionTestStep $step) => $step->status->isActive());

        return [
            'id' => $run->id,
            'app' => $run->application->name,
            'version' => $run->version->name,
            'started_by' => $run->started_by?->name,
            'created_at' => $run->created_at->format('Y-m-d H:i:s'),
            'url' => '/admin/apps/'.$run->application->slug.'/versions/'.$run->version->name.'/tests/'.$run->id,
            'progress' => [
                'total' => $steps->count(),
                'passed' => $steps->where('status', VersionTestStepStatus::PASSED)->count(),
                'pending' => $steps->where('status', VersionTestStepStatus::PENDING)->count(),
            ],
            // The test holding the single test slot right now, if there is one
            'current_step' => $current ? [
                'plan' => $current->plan->name,
                'type' => $current->type->value,
                'from_version' => $current->from_version?->name,
                'status' => $current->status->value,
            ] : null,
        ];
    }
}
