<?php

use App\AppChart;
use App\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function versionRow(int $application_id, ?int $group_id, string $name, string $status, array $settings): int
{
    return DB::table('app_versions')->insertGetId([
        'application_id' => $application_id,
        'version_group_id' => $group_id,
        'name' => $name,
        'status' => $status,
        'settings' => json_encode($settings),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_11_25_000000_move_chart_identity_off_versions.php');
    $this->application = Application::factory()->create();
});

it('gives a chart what it lacks from its enabled version, then takes it off the versions', function () {
    $chart = AppChart::factory()->default()->for($this->application)->create(['chart_name' => null, 'helm_repo' => null]);
    $group = $chart->defaultVersionGroup();

    $newest = versionRow($this->application->id, $group->id, '3', 'deactivated', ['chart_name' => 'newest', 'helm_repo_name' => 'https://newest.test', 'chart_version' => '3.0.0']);
    $enabled = versionRow($this->application->id, $group->id, '2', 'active', ['chart_name' => 'enabled', 'helm_repo_name' => 'https://enabled.test', 'chart_version' => '2.0.0', 'image_repo_name' => 'demo/app']);

    $this->migration->up();

    expect($chart->fresh())->chart_name->toBe('enabled')->helm_repo->toBe('https://enabled.test');
    expect(json_decode(DB::table('app_versions')->where('id', $enabled)->value('settings'), true))->toBe(['chart_version' => '2.0.0', 'image_repo_name' => 'demo/app']);
    expect(json_decode(DB::table('app_versions')->where('id', $newest)->value('settings'), true))->toBe(['chart_version' => '3.0.0']);
});

it('does not change what a chart already says', function () {
    $chart = AppChart::factory()->default()->for($this->application)->create(['chart_name' => 'kept', 'helm_repo' => 'https://kept.test']);
    versionRow($this->application->id, $chart->defaultVersionGroup()->id, '1', 'active', ['chart_name' => 'other', 'helm_repo_name' => 'https://other.test']);

    $this->migration->up();

    expect($chart->fresh())->chart_name->toBe('kept')->helm_repo->toBe('https://kept.test');
});

it('leaves a version in no group as it is, since it has no chart to take over', function () {
    $version = versionRow($this->application->id, null, '1', 'active', ['chart_name' => 'gitea', 'helm_repo_name' => 'https://repo.test', 'chart_version' => '1.0.0']);

    $this->migration->up();

    expect(json_decode(DB::table('app_versions')->where('id', $version)->value('settings'), true))
        ->toBe(['chart_name' => 'gitea', 'helm_repo_name' => 'https://repo.test', 'chart_version' => '1.0.0']);
});

it('is safe to run again', function () {
    $chart = AppChart::factory()->default()->for($this->application)->create(['chart_name' => null, 'helm_repo' => null]);
    $version = versionRow($this->application->id, $chart->defaultVersionGroup()->id, '1', 'active', ['chart_name' => 'gitea', 'helm_repo_name' => 'https://repo.test']);

    $this->migration->up();
    $this->migration->up();

    expect($chart->fresh())->chart_name->toBe('gitea')
        ->and(json_decode(DB::table('app_versions')->where('id', $version)->value('settings'), true))->toBe([]);
});

it('no longer has a login on the version, only on its chart', function () {
    expect(Schema::hasColumn('app_versions', 'helm_repo_secret_id'))->toBeFalse()
        ->and(Schema::hasColumn('app_charts', 'helm_repo_secret_id'))->toBeTrue();
});
