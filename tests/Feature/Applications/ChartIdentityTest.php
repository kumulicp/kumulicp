<?php

use App\AppChart;
use App\Application;
use App\AppVersion;
use App\RepoSecret;
use App\Support\VersionTesting\VersionFingerprint;
use App\User;
use Tests\Support\TestSupports;

function versionOfChart(AppChart $chart, array $attributes = []): AppVersion
{
    $group = $chart->defaultVersionGroup();

    return AppVersion::factory()->create([
        'application_id' => $chart->application_id,
        'version_group_id' => $group->id,
        'name' => fake()->unique()->numerify('#.#.#'),
        ...$attributes,
    ]);
}

it('reads where a version is deployed from from its chart', function () {
    $chart = AppChart::factory()->default()->create(['chart_name' => 'nextcloud', 'helm_repo' => 'https://nextcloud.github.io/helm/']);
    $version = versionOfChart($chart, ['settings' => ['chart_name' => 'old', 'helm_repo_name' => 'https://old.test', 'chart_version' => '1.0.0']]);

    expect($version->chartName())->toBe('nextcloud')
        ->and($version->helmRepo())->toBe('https://nextcloud.github.io/helm/')
        ->and($version->setting('chart_version'))->toBe('1.0.0');
});

it('falls back to the version settings while its chart does not say', function () {
    $chart = AppChart::factory()->default()->create(['chart_name' => null, 'helm_repo' => null]);
    $version = versionOfChart($chart, ['settings' => ['chart_name' => 'gitea', 'helm_repo_name' => 'https://repo.test']]);

    expect($version->chartName())->toBe('gitea')
        ->and($version->helmRepo())->toBe('https://repo.test');
});

it('uses the repo login of the chart, not of the version', function () {
    $secret = RepoSecret::factory()->helm()->create();
    $other = RepoSecret::factory()->helm()->create();
    $chart = AppChart::factory()->default()->create(['helm_repo_secret_id' => $secret->id]);
    $version = versionOfChart($chart, ['helm_repo_secret_id' => $other->id]);

    expect($version->chartRepoSecret()->is($secret))->toBeTrue()
        ->and($version->chartRepoSecretId())->toBe($secret->id);

    $chart->update(['helm_repo_secret_id' => null]);

    expect($version->fresh()->chartRepoSecret())->toBeNull();
});

it('counts a login held by a chart as used by its versions', function () {
    $secret = RepoSecret::factory()->helm()->create();
    $chart = AppChart::factory()->default()->create(['helm_repo_secret_id' => $secret->id]);
    $version = versionOfChart($chart);

    expect($secret->versions()->pluck('id')->all())->toBe([$version->id]);
});

it('changes the fingerprint of every version of a chart when the chart changes', function () {
    $chart = AppChart::factory()->default()->create(['chart_name' => 'nextcloud', 'helm_repo' => 'https://a.test']);
    $version = versionOfChart($chart, ['settings' => ['chart_name' => 'nextcloud', 'helm_repo_name' => 'https://a.test', 'chart_version' => '1.0.0']]);
    $before = (new VersionFingerprint)->for($version);

    $chart->update(['helm_repo' => 'https://b.test']);

    expect((new VersionFingerprint)->for($version->fresh()))->not->toBe($before);
});

it('leaves the deployment settings of a version whose chart says nothing as they were', function () {
    $chart = AppChart::factory()->default()->create(['chart_name' => null, 'helm_repo' => null]);
    $settings = ['chart_name' => 'gitea', 'helm_repo_name' => 'https://repo.test', 'chart_version' => '1.0.0'];

    expect(versionOfChart($chart, ['settings' => $settings])->deploymentSettings())->toBe($settings);
});

describe('editing a version', function () {
    beforeEach(function () {
        (new TestSupports)->seed();

        $this->actingAs(User::where('username', 'demo')->firstOrFail());
        $this->application = Application::factory()->create();
        $this->chart = AppChart::factory()->default()->for($this->application)->create(['chart_name' => 'demo', 'helm_repo' => 'https://old.test']);
        $this->registry = RepoSecret::factory()->create();
    });

    it('changes the chart for all of its versions', function () {
        $edited = versionOfChart($this->chart, ['name' => '1.0', 'roles' => []]);
        $sibling = versionOfChart($this->chart, ['name' => '2.0']);

        $this->post("/admin/apps/{$this->application->slug}/versions/{$edited->id}", [
            'version' => '1.0',
            'chart_version' => '3.0.0',
            'chart_name' => 'renamed',
            'helm_repo_name' => 'https://new.test',
            'image_registry_id' => $this->registry->id,
            'announcement_location' => 'none',
        ])->assertSessionDoesntHaveErrors();

        expect($this->chart->fresh())->chart_name->toBe('renamed')->helm_repo->toBe('https://new.test')
            ->and($sibling->fresh()->helmRepo())->toBe('https://new.test')
            ->and($sibling->fresh()->chartName())->toBe('renamed')
            // The chart version is the version's own
            ->and($edited->fresh()->setting('chart_version'))->toBe('3.0.0')
            ->and($sibling->fresh()->setting('chart_version'))->not->toBe('3.0.0');
    });

    it('shows the chart as it is on the chart', function () {
        $version = versionOfChart($this->chart, ['name' => '1.0', 'settings' => ['chart_name' => 'stale', 'helm_repo_name' => 'https://stale.test']]);

        $this->get("/admin/apps/{$this->application->slug}/versions/{$version->id}")
            ->assertInertia(fn ($page) => $page
                ->where('version.chart_name', 'demo')
                ->where('version.helm_repo_name', 'https://old.test'));
    });
});
