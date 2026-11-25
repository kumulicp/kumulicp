<?php

use App\AppChart;
use App\Application;
use App\AppVersion;
use App\RepoSecret;
use App\Support\VersionTesting\VersionFingerprint;
use App\User;
use Illuminate\Support\Facades\DB;
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

it('has nothing of its own to fall back to when its chart does not say', function () {
    $chart = AppChart::factory()->default()->create(['chart_name' => null, 'helm_repo' => null]);
    $version = versionOfChart($chart, ['settings' => ['chart_name' => 'gitea', 'helm_repo_name' => 'https://repo.test']]);

    expect($version->chartName())->toBeNull()
        ->and($version->helmRepo())->toBeNull();
});

it('has no chart, so nothing to deploy from, when it is in no group', function () {
    $version = AppVersion::factory()->create(['application_id' => Application::factory()->create()->id]);
    DB::table('app_versions')->where('id', $version->id)->update(['version_group_id' => null]);

    expect($version->fresh()->chart())->toBeNull()
        ->and($version->fresh()->chartName())->toBeNull()
        ->and($version->fresh()->chartRepoSecretId())->toBeNull();
});

it('uses the repo login of its chart', function () {
    $secret = RepoSecret::factory()->helm()->create();
    $chart = AppChart::factory()->default()->create(['helm_repo_secret_id' => $secret->id]);
    $version = versionOfChart($chart);

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

    it('changes the chart for all of its versions, from the chart', function () {
        $edited = versionOfChart($this->chart, ['name' => '1.0', 'roles' => []]);
        $sibling = versionOfChart($this->chart, ['name' => '2.0']);
        $login = RepoSecret::factory()->helm()->create();

        $this->put("/admin/apps/{$this->application->slug}/charts/{$this->chart->id}", [
            'chart_name' => 'renamed',
            'helm_repo' => 'https://new.test',
            'helm_repo_secret_id' => $login->id,
        ])->assertSessionDoesntHaveErrors()->assertSessionHas('success');

        expect($this->chart->fresh())->chart_name->toBe('renamed')->helm_repo->toBe('https://new.test')->helm_repo_secret_id->toBe($login->id)
            ->and($sibling->fresh()->helmRepo())->toBe('https://new.test')
            ->and($sibling->fresh()->chartName())->toBe('renamed')
            ->and($edited->fresh()->chartRepoSecretId())->toBe($login->id);
    });

    it('clears what the chart form leaves empty', function () {
        $this->put("/admin/apps/{$this->application->slug}/charts/{$this->chart->id}", ['chart_name' => '', 'helm_repo' => ''])->assertSessionDoesntHaveErrors();

        expect($this->chart->fresh())->chart_name->toBeNull()->helm_repo->toBeNull();
    });

    it('does not edit the chart of another app, or a login that does not exist', function () {
        $foreign = AppChart::factory()->create();

        $this->put("/admin/apps/{$this->application->slug}/charts/{$foreign->id}", ['chart_name' => 'x'])->assertNotFound();
        $this->put("/admin/apps/{$this->application->slug}/charts/{$this->chart->id}", ['helm_repo_secret_id' => 99999])->assertSessionHasErrors('helm_repo_secret_id');
    });

    it('leaves the chart alone when the version form is saved, and keeps the version its own chart version', function () {
        $edited = versionOfChart($this->chart, ['name' => '1.0', 'roles' => []]);

        $this->post("/admin/apps/{$this->application->slug}/versions/{$edited->id}", [
            'version' => '1.0',
            'chart_version' => '3.0.0',
            'chart_name' => 'ignored',
            'helm_repo_name' => 'https://ignored.test',
            'image_registry_id' => $this->registry->id,
            'announcement_location' => 'none',
        ])->assertSessionDoesntHaveErrors();

        expect($this->chart->fresh())->chart_name->toBe('demo')->helm_repo->toBe('https://old.test')
            ->and($edited->fresh()->setting('chart_version'))->toBe('3.0.0');
    });

    it('shows the chart as it is on the chart', function () {
        $login = RepoSecret::factory()->helm()->create(['name' => 'Charts login']);
        $this->chart->update(['helm_repo_secret_id' => $login->id]);
        $version = versionOfChart($this->chart, ['name' => '1.0', 'settings' => ['chart_name' => 'stale', 'helm_repo_name' => 'https://stale.test']]);

        $this->get("/admin/apps/{$this->application->slug}/versions/{$version->id}")
            ->assertInertia(fn ($page) => $page
                ->where('chart.id', $this->chart->id)
                ->where('chart.chart_name', 'demo')
                ->where('chart.helm_repo', 'https://old.test')
                ->where('chart.helm_repo_secret', 'Charts login')
                ->missing('version.chart_name')
                ->missing('version.helm_repo_name'));
    });
});
