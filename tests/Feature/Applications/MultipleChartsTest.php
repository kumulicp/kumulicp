<?php

use App\AppChart;
use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\ChartSource;
use App\Enums\ApplicationSource;
use App\Integrations\Applications\HelmForge\HelmForgeChart;
use App\Integrations\Applications\HelmForge\HelmForgeProfile;
use App\Organization;
use App\Support\Catalog\ChartCatalogSync;
use App\Support\Facades\Application as ApplicationFacade;
use App\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\TestSupports;
use Tests\Support\VersionUpdates\ChartArchive;

function fakeGiteaRepo(array $versions = ['1.0.0'], array $values = ['replicaCount' => 1, 'fullnameOverride' => '']): void
{
    $fakes = ['repo.test/index.yaml' => Http::response(ChartArchive::index('gitea', $versions))];

    foreach ($versions as $version) {
        $fakes["repo.test/gitea-$version.tgz"] = Http::response(ChartArchive::make('gitea', $version, $values));
    }

    Http::swap(new HttpFactory);
    Http::fake($fakes);
}

beforeEach(function () {
    (new TestSupports)->seed();
    $this->actingAs(User::find(1));

    ApplicationFacade::register(new DemoAppProfile);
    $this->application = ApplicationFacade::initialize('demo_app');
    $this->default_group = $this->application->defaultVersionGroup();

    $this->source = ChartSource::where('slug', 'helmforge')->first();
    $this->source->update(['url' => 'https://repo.test']);
});

function attachedChart(): AppChart
{
    fakeGiteaRepo();

    return app(ChartCatalogSync::class)->attach(test()->source, test()->application, 'gitea')['chart'];
}

describe('adding a chart', function () {
    it('adds the chart of a source to an app deployed another way, off until enabled', function () {
        fakeGiteaRepo(['1.0.0', '0.9.0']);

        $result = app(ChartCatalogSync::class)->attach($this->source, $this->application, 'gitea');
        $chart = $result['chart'];

        expect($result['created'])->toBeTrue()
            ->and($chart)->source->toBe(ApplicationSource::HelmForge)
            ->is_default->toBeFalse()
            ->enabled->toBeFalse()
            ->chart_name->toBe('gitea')
            ->helm_repo->toBe('https://repo.test')
            ->chart_source_id->toBe($this->source->id)
            ->and($chart->catalog['chart_version'])->toBe('1.0.0')
            ->and($chart->defaultVersionGroup()->versions()->orderBy('id')->pluck('name')->all())->toBe(['1.0.0', '0.9.0']);

        // The app itself is left as it was
        $application = $this->application->fresh();
        expect($application->source)->toBe(ApplicationSource::Profile)
            ->and($application->catalog)->toBeNull()
            ->and($application->chart_source_id)->toBeNull()
            ->and($application->charts()->where('is_default', true)->count())->toBe(1);
    });

    it('brings an added chart up to date instead of adding it twice', function () {
        $chart = attachedChart();

        fakeGiteaRepo(['1.1.0', '1.0.0']);
        $result = app(ChartCatalogSync::class)->attach($this->source, $this->application, 'gitea');

        expect($result['created'])->toBeFalse()
            ->and($result['chart']->is($chart))->toBeTrue()
            ->and($this->application->charts()->count())->toBe(2)
            ->and($chart->defaultVersionGroup()->versions()->pluck('name')->sort()->values()->all())->toBe(['1.0.0', '1.1.0']);
    });

    it('does not take a version name the app already has under another chart', function () {
        AppVersion::factory()->create(['application_id' => $this->application->id, 'version_group_id' => $this->default_group->id, 'name' => '1.0.0']);
        fakeGiteaRepo(['1.0.0', '0.9.0']);

        $result = app(ChartCatalogSync::class)->attach($this->source, $this->application, 'gitea');

        expect($result['skipped'])->toHaveKey('1.0.0')
            ->and($result['chart']->defaultVersionGroup()->versions()->pluck('name')->all())->toBe(['0.9.0']);
    });

    it('refuses a chart the source does not have, and an app already imported from the source', function () {
        fakeGiteaRepo();

        expect(fn () => app(ChartCatalogSync::class)->attach($this->source, $this->application, 'nonexistent'))
            ->toThrow(RuntimeException::class, 'no application chart named nonexistent');

        $imported = Application::factory()->create(['source' => ApplicationSource::HelmForge, 'chart_source_id' => $this->source->id]);

        expect(fn () => app(ChartCatalogSync::class)->attach($this->source, $imported, 'gitea'))
            ->toThrow(RuntimeException::class, 'already deployed from');
    });

    it('keeps an added chart up to date with the daily sync', function () {
        $chart = attachedChart();

        fakeGiteaRepo(['1.1.0', '1.0.0']);
        $report = app(ChartCatalogSync::class)->sync($this->source);

        expect($report['updated'])->toContain('demo_app (helmforge)')
            ->and($chart->defaultVersionGroup()->versions()->pluck('name')->sort()->values()->all())->toBe(['1.0.0', '1.1.0']);
    });
});

describe('which profile deploys what', function () {
    it('uses the registered profile for everything while an app has one chart', function () {
        $plan = AppPlan::factory()->create(['application_id' => $this->application->id]);

        expect(ApplicationFacade::profileFor($plan))->toBeInstanceOf(DemoAppProfile::class)
            ->and(ApplicationFacade::profileFor($this->default_group->chart))->toBeInstanceOf(DemoAppProfile::class);
    });

    it('uses the profile of the chart a plan picked, and the registered one for a plan that picked none', function () {
        $chart = attachedChart();
        $on_registered = AppPlan::factory()->create(['application_id' => $this->application->id]);
        $on_catalog = AppPlan::factory()->create(['application_id' => $this->application->id, 'version_group_id' => $chart->defaultVersionGroup()->id]);

        expect(ApplicationFacade::profileFor($on_registered))->toBeInstanceOf(DemoAppProfile::class)
            ->and(ApplicationFacade::profileFor($on_catalog))->toBeInstanceOf(HelmForgeProfile::class)
            ->and(array_keys(ApplicationFacade::profileFor($on_catalog)->configurations()))->not->toBe(array_keys(ApplicationFacade::profileFor($on_registered)->configurations()));
    });

    it('uses the profile of the chart an install runs', function () {
        $chart = attachedChart();
        $registered_version = AppVersion::factory()->create(['application_id' => $this->application->id, 'version_group_id' => $this->default_group->id, 'name' => '5.0']);
        $catalog_version = $chart->defaultVersionGroup()->versions()->first();
        $organization = Organization::factory()->create();

        $on_registered = AppInstance::factory()->create(['application_id' => $this->application->id, 'organization_id' => $organization->id, 'version_id' => $registered_version->id]);
        $on_catalog = AppInstance::factory()->create(['application_id' => $this->application->id, 'organization_id' => $organization->id, 'version_id' => $catalog_version->id]);

        expect(ApplicationFacade::profileFor($on_registered))->toBeInstanceOf(DemoAppProfile::class)
            ->and(ApplicationFacade::profileFor($on_catalog))->toBeInstanceOf(HelmForgeProfile::class)
            // What is registered for the app as a whole is unchanged
            ->and(ApplicationFacade::profile($this->application))->toBeInstanceOf(DemoAppProfile::class);
    });

    it('leaves features the registered profile has out of a plan on the catalog chart', function () {
        $chart = attachedChart();
        $on_registered = AppPlan::factory()->create(['application_id' => $this->application->id]);
        $on_catalog = AppPlan::factory()->create(['application_id' => $this->application->id, 'version_group_id' => $chart->defaultVersionGroup()->id]);

        expect(ApplicationFacade::profileFor($on_registered)->features())->not->toBeEmpty()
            ->and(ApplicationFacade::profileFor($on_catalog)->features())->toBeEmpty();
    });

    it('deploys an install on the catalog chart with the values the chart is detected to take', function () {
        $chart = attachedChart();
        $organization = Organization::factory()->create();
        $plan = AppPlan::factory()->create(['application_id' => $this->application->id, 'version_group_id' => $chart->defaultVersionGroup()->id]);
        $version = $chart->defaultVersionGroup()->versions()->first();

        $app_instance = ApplicationFacade::activate($organization, $this->application, $version, $plan)->get();
        $values = (new HelmForgeChart($organization, $app_instance))->valuesWithAdditionalConfigs();

        // The app has no catalog data of its own: these keys come from the chart's
        expect($values['replicaCount'])->toBe(1)
            ->and($values['fullnameOverride'])->toContain($organization->slug);
    });
});

describe('the charts page', function () {
    it('lists the charts of the app with their use, and the sources one could be added from', function () {
        $chart = attachedChart();

        $this->get("/admin/apps/{$this->application->slug}/charts")
            ->assertInertia(fn ($page) => $page
                ->has('charts', 2)
                ->where('charts.0.is_default', true)
                ->where('charts.1.id', $chart->id)
                ->where('charts.1.enabled', false)
                ->where('charts.1.versions', 1)
                ->where('sources', []));
    });

    it('offers a source until its chart was added', function () {
        $this->get("/admin/apps/{$this->application->slug}/charts")
            ->assertInertia(fn ($page) => $page->where('sources.0.id', $this->source->id));
    });

    it('adds a chart from a source', function () {
        fakeGiteaRepo();

        $this->post("/admin/apps/{$this->application->slug}/charts", ['chart_source_id' => $this->source->id, 'chart_name' => 'gitea'])
            ->assertSessionHas('success');

        expect($this->application->charts()->where('is_default', false)->count())->toBe(1);
    });

    it('says why a chart could not be added', function () {
        fakeGiteaRepo();

        $this->post("/admin/apps/{$this->application->slug}/charts", ['chart_source_id' => $this->source->id, 'chart_name' => 'missing'])
            ->assertSessionHas('error');

        expect($this->application->charts()->count())->toBe(1);
    });

    it('enables and disables a chart other than the default', function () {
        $chart = attachedChart();

        $this->post("/admin/apps/{$this->application->slug}/charts/{$chart->id}/enable")->assertSessionHas('success');
        expect($chart->fresh()->enabled)->toBeTrue();

        $this->post("/admin/apps/{$this->application->slug}/charts/{$chart->id}/disable")->assertSessionHas('success');
        expect($chart->fresh()->enabled)->toBeFalse();
    });

    it('keeps the default chart on, and a chart that plans use', function () {
        $chart = attachedChart();
        $chart->update(['enabled' => true]);
        AppPlan::factory()->create(['application_id' => $this->application->id, 'version_group_id' => $chart->defaultVersionGroup()->id]);

        $this->post("/admin/apps/{$this->application->slug}/charts/{$this->default_group->chart->id}/disable")->assertSessionHas('error');
        $this->post("/admin/apps/{$this->application->slug}/charts/{$chart->id}/disable")->assertSessionHas('error');

        expect($this->default_group->chart->fresh()->enabled)->toBeTrue()
            ->and($chart->fresh()->enabled)->toBeTrue();
    });

    it('does not touch the chart of another app', function () {
        $foreign = AppChart::factory()->create();

        $this->post("/admin/apps/{$this->application->slug}/charts/{$foreign->id}/enable")->assertNotFound();
    });
});

describe('plans', function () {
    it('are only offered the groups of enabled charts, besides the one they have', function () {
        $chart = attachedChart();
        $group = $chart->defaultVersionGroup();
        $plan = AppPlan::factory()->create(['application_id' => $this->application->id]);
        $url = "/admin/apps/{$this->application->slug}/plans/{$plan->id}/edit";

        // Off: not offered, and a plan that has none of its groups sees one group, so no picker at all
        $this->get($url)->assertInertia(fn ($page) => $page->has('version_groups', 1));

        $chart->update(['enabled' => true]);
        $this->get($url)->assertInertia(fn ($page) => $page->has('version_groups', 2)->where('version_groups.1.value', $group->id));

        // A plan already on a chart that is switched off keeps seeing it
        $chart->update(['enabled' => false]);
        $plan->update(['version_group_id' => $group->id]);
        $this->get($url)->assertInertia(fn ($page) => $page->has('version_groups', 2));
    });

    it('show the configurations of the chart they are on', function () {
        $chart = attachedChart();
        $on_registered = AppPlan::factory()->create(['application_id' => $this->application->id]);
        $on_catalog = AppPlan::factory()->create(['application_id' => $this->application->id, 'version_group_id' => $chart->defaultVersionGroup()->id]);

        expect(array_keys(ApplicationFacade::configurations($this->application, $on_registered)))->toBe(array_keys((new DemoAppProfile)->configurations()))
            ->and(array_keys(ApplicationFacade::configurations($this->application, $on_catalog)))->toBe(array_keys($chart->catalog['configurations']));
    });
});
