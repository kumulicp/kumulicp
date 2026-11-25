<?php

use App\AppChart;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\AppVersionGroup;
use App\ChartSource;
use Illuminate\Support\Facades\DB;

function insertVersion(Application $application, string $name, string $status, ?array $settings): int
{
    return DB::table('app_versions')->insertGetId([
        'application_id' => $application->id,
        'name' => $name,
        'status' => $status,
        'settings' => $settings === null ? null : json_encode($settings),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('puts a new version in the default group of its app, made from the version', function () {
    $application = Application::factory()->create();

    $version = AppVersion::factory()->create([
        'application_id' => $application->id,
        'settings' => ['chart_name' => 'nextcloud', 'chart_version' => '9.0.5', 'helm_repo_name' => 'https://nextcloud.github.io/helm/'],
    ]);

    $chart = $application->charts()->sole();

    expect($chart->is_default)->toBeTrue()
        ->and($chart->chart_name)->toBe('nextcloud')
        ->and($chart->helm_repo)->toBe('https://nextcloud.github.io/helm/')
        ->and($version->versionGroup->chart->is($chart))->toBeTrue()
        ->and($version->versionGroup->is_default)->toBeTrue();
});

it('keeps further versions in the same group instead of making more charts', function () {
    $application = Application::factory()->create();

    $first = AppVersion::factory()->create(['application_id' => $application->id]);
    $second = AppVersion::factory()->create(['application_id' => $application->id, 'name' => '2.0']);

    expect($application->charts()->count())->toBe(1)
        ->and(AppVersionGroup::count())->toBe(1)
        ->and($second->version_group_id)->toBe($first->version_group_id);
});

it('leaves the group of a version that was given one', function () {
    $application = Application::factory()->create();
    $group = AppVersionGroup::factory()->for(AppChart::factory()->for($application)->default(), 'chart')->create();

    $version = AppVersion::factory()->create(['application_id' => $application->id, 'version_group_id' => $group->id]);

    expect($version->version_group_id)->toBe($group->id)
        ->and(AppVersionGroup::count())->toBe(1);
});

it('has the plan follow the default group unless it picked one', function () {
    $application = Application::factory()->create();
    $version = AppVersion::factory()->create(['application_id' => $application->id]);
    $plan = AppPlan::factory()->create(['application_id' => $application->id]);

    expect($plan->versionGroup()->is($version->versionGroup))->toBeTrue();

    $other = AppVersionGroup::factory()->for($application->charts()->sole(), 'chart')->create();
    $plan->update(['version_group_id' => $other->id]);

    expect($plan->fresh()->versionGroup()->is($other))->toBeTrue();
});

it('names the default chart of a catalog app after its chart source', function () {
    $source = ChartSource::create(['name' => 'HelmForge', 'slug' => 'forge-test', 'url' => 'https://repo.test']);
    $application = Application::factory()->create(['chart_source_id' => $source->id]);

    AppVersion::factory()->create(['application_id' => $application->id]);

    expect($application->charts()->sole())
        ->name->toBe('HelmForge')
        ->chart_source_id->toBe($source->id);
});

describe('backfill', function () {
    beforeEach(function () {
        $this->backfill = require database_path('migrations/2026_11_20_000001_backfill_app_charts_and_version_groups.php');
    });

    it('gives an app its chart and one group holding all its versions', function () {
        $application = Application::factory()->create();
        $settings = ['chart_name' => 'nextcloud', 'chart_version' => '9.0.5', 'helm_repo_name' => 'https://nextcloud.github.io/helm/'];
        $ids = [insertVersion($application, '33.0.0', 'deactivated', $settings), insertVersion($application, '33.0.1', 'active', $settings)];

        $this->backfill->up();

        $chart = $application->charts()->sole();
        expect($chart->is_default)->toBeTrue()
            ->and($chart->chart_name)->toBe('nextcloud')
            ->and($chart->versionGroups()->sole()->versions()->pluck('id')->sort()->values()->all())->toBe($ids);
        // Deployment still reads the version's own settings
        expect(AppVersion::find($ids[1])->setting('chart_name'))->toBe('nextcloud');
    });

    it('makes a chart for each distinct chart an app was deployed from, the enabled one being the default', function () {
        $application = Application::factory()->create();
        insertVersion($application, '1', 'deactivated', ['chart_name' => 'nextcloud', 'helm_repo_name' => 'https://repo.helmforge.dev']);
        insertVersion($application, '2', 'active', ['chart_name' => 'nextcloud', 'helm_repo_name' => 'https://nextcloud.github.io/helm/']);

        $this->backfill->up();

        $charts = $application->charts()->get();
        expect($charts)->toHaveCount(2)
            ->and($charts->firstWhere('is_default', true)->helm_repo)->toBe('https://nextcloud.github.io/helm/')
            ->and($charts->firstWhere('is_default', false)->helm_repo)->toBe('https://repo.helmforge.dev')
            ->and($charts->pluck('slug')->unique())->toHaveCount(2);
    });

    it('puts versions that name no chart with the default one', function () {
        $application = Application::factory()->create();
        $nameless = insertVersion($application, '1', 'deactivated', null);
        insertVersion($application, '2', 'active', ['chart_name' => 'app', 'helm_repo_name' => 'https://r.test']);

        $this->backfill->up();

        expect($application->charts()->sole()->versionGroups()->sole()->versions()->pluck('id'))->toContain($nameless);
    });

    it('leaves an app that already has charts, and one without versions, alone', function () {
        $with_chart = Application::factory()->create();
        AppChart::factory()->default()->for($with_chart)->create();
        insertVersion($with_chart, '1', 'active', ['chart_name' => 'a']);
        $without_versions = Application::factory()->create();

        $this->backfill->up();
        $this->backfill->up();

        expect($with_chart->charts()->count())->toBe(1)
            ->and(DB::table('app_versions')->whereNotNull('version_group_id')->count())->toBe(0)
            ->and($without_versions->charts()->count())->toBe(0);
    });
});
