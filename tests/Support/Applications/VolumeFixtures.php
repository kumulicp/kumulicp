<?php

namespace Tests\Support\Applications;

use App\AppInstance;
use App\Application as ApplicationModel;
use App\AppPlan;
use App\AppVersion;
use App\ChartSource;
use App\Integrations\ServerManagers\Kubernetes\Charts\HelmChart;
use App\Organization;
use App\OrgServer;
use App\Server;
use App\Support\Catalog\ChartCatalogSync;
use App\Support\Facades\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Tests\Support\VersionUpdates\ChartArchive;

/**
 * Instances of each kind of chart, for the tests that pin what an app's volumes are given
 * (docs/volumes-and-settings-plan.md, Slice 0). They build the chart the way a release does,
 * through the profile the instance deploys with, so they keep working when the volume code
 * behind that moves.
 */
class VolumeFixtures
{
    /** What each registered app's version says about where its chart is from. */
    private const VERSIONS = [
        'nextcloud' => [
            'chart_name' => 'nextcloud', 'chart_version' => '1.0.0',
            'helm_repo_name' => 'https://nextcloud.github.io/helm',
            'image_repo_name' => 'library/nextcloud', 'image_registry' => 'docker.io',
        ],
        'wordpress' => [
            'chart_name' => 'wordpress', 'chart_version' => '1.0.0',
            'helm_repo_name' => 'https://charts.bitnami.com/bitnami',
            'image_repo_name' => 'bitnami/wordpress', 'image_registry' => 'docker.io',
        ],
        'civicrm-standalone' => [
            'chart_name' => 'civicrm-standalone', 'chart_version' => '1.0.0',
            'helm_repo_name' => 'https://repo.example.test',
            'image_repo_name' => 'library/civicrm', 'image_registry' => 'docker.io',
        ],
        'generic' => [
            'chart_name' => 'generic', 'chart_version' => '3.1.5',
            'helm_repo_name' => 'https://repo.helmforge.dev',
            'image_repo_name' => 'library/my-app', 'image_registry' => 'docker.io',
            'port' => 9000,
        ],
    ];

    /**
     * An activated instance of a registered app, on a plan with the given configurations, with the
     * given settings written over what activation left in its own.
     *
     * @param  array<string, mixed>  $configurations  the plan's
     * @param  array<string, mixed>  $settings  the instance's, merged over its own
     * @param  array<string, mixed>  $additional_configs  configurations the plan adds to the profile's
     */
    public static function instance(string $slug, array $configurations = [], array $settings = [], array $additional_configs = []): AppInstance
    {
        $organization = Organization::factory()->create();

        // A test may make several instances of the same app
        $app = ApplicationModel::where('slug', $slug)->first() ?? Application::initialize($slug);
        Application::roles($app);

        $version = AppVersion::factory()->create([
            'application_id' => $app->id,
            'settings' => self::VERSIONS[$slug],
        ]);

        $plan = AppPlan::factory()->create([
            'application_id' => $app->id,
            'settings' => self::planSettings($configurations, $additional_configs),
        ]);

        $app_instance = Application::activate($organization, $app, $version, $plan)->get();

        return self::finish($organization, $app_instance, $settings);
    }

    /**
     * An instance of a chart imported from the catalog, whose chart has the given default values.
     * The chart is the application's own (a catalog app), as in HelmForgeChartTest.
     *
     * @param  array<string, mixed>  $values  the chart's default values
     * @param  array<string, mixed>  $configurations  the plan's
     * @param  array<string, mixed>  $settings  the instance's, merged over its own
     */
    public static function catalogInstance(array $values, array $configurations = [], array $settings = []): AppInstance
    {
        $source = ChartSource::where('slug', 'helmforge')->first();
        $source->update(['url' => 'https://repo.test']);

        Http::fake([
            'repo.test/index.yaml' => Http::response(ChartArchive::index('gitea', ['1.0.0'])),
            'repo.test/gitea-1.0.0.tgz' => Http::response(ChartArchive::make('gitea', '1.0.0', $values)),
        ]);

        app(ChartCatalogSync::class)->sync($source);

        $organization = Organization::factory()->create();
        $application = ApplicationModel::where('slug', 'gitea')->first();
        $version = AppVersion::where('application_id', $application->id)->first();
        $plan = AppPlan::factory()->create([
            'application_id' => $application->id,
            'settings' => self::planSettings($configurations),
        ]);

        $app_instance = Application::activate($organization, $application, $version, $plan)->get();

        return self::finish($organization, $app_instance, $settings);
    }

    /**
     * One app with two charts, an instance on each: the app's own (the demo app's), and a catalog chart
     * added to it, as docs/chart-catalog.md describes ("Several charts for one app"). The catalog
     * chart's data is on the chart; the app has none of its own.
     *
     * @param  array<string, mixed>  $values  the added chart's default values
     * @param  array<string, mixed>  $settings  the settings of both instances, merged over their own
     * @return array{0: AppInstance, 1: AppInstance} the one on the app's own chart, the one on the added chart
     */
    public static function onTwoCharts(array $values, array $settings = []): array
    {
        Application::register(new DemoAppProfile);
        $app = Application::initialize('demo_app');
        Application::roles($app);

        $source = ChartSource::where('slug', 'helmforge')->first();
        $source->update(['url' => 'https://repo.test']);

        Http::swap(new HttpFactory);
        Http::fake([
            'repo.test/index.yaml' => Http::response(ChartArchive::index('gitea', ['1.0.0'])),
            'repo.test/gitea-1.0.0.tgz' => Http::response(ChartArchive::make('gitea', '1.0.0', $values)),
        ]);

        $chart = app(ChartCatalogSync::class)->attach($source, $app, 'gitea')['chart'];

        $own_version = AppVersion::factory()->create(['application_id' => $app->id, 'version_group_id' => $app->defaultVersionGroup()->id, 'name' => '5.0']);
        $own_plan = AppPlan::factory()->create(['application_id' => $app->id, 'settings' => self::planSettings([])]);
        $added_plan = AppPlan::factory()->create([
            'application_id' => $app->id,
            'version_group_id' => $chart->defaultVersionGroup()->id,
            'settings' => self::planSettings([]),
        ]);

        $own = Application::activate($organization = Organization::factory()->create(), $app, $own_version, $own_plan)->get();
        $own = self::finish($organization, $own, $settings);

        $added = Application::activate($organization = Organization::factory()->create(), $app, $chart->defaultVersionGroup()->versions()->first(), $added_plan)->get();

        return [$own, self::finish($organization, $added, $settings)];
    }

    /**
     * The chart the instance is deployed with, found the way a release finds it.
     */
    public static function chart(AppInstance $app_instance): HelmChart
    {
        $class = Application::profileFor($app_instance)->chart();

        return new $class($app_instance->organization, $app_instance);
    }

    /**
     * The values the chart gives the release, as it deploys them: its own plus what the plan adds.
     *
     * @return array<string, mixed>
     */
    public static function values(AppInstance $app_instance): array
    {
        return self::chart($app_instance->fresh())->valuesWithAdditionalConfigs();
    }

    /**
     * Only the parts of the values that say how a volume is made or found, at the keys given
     * (dot notation): what a refactor of the volume code must leave as it is.
     *
     * @param  array<int, string>  $paths
     * @return array<string, mixed>
     */
    public static function only(array $values, array $paths): array
    {
        $found = [];

        foreach ($paths as $path) {
            $found[$path] = Arr::get($values, $path, '(not set)');
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private static function finish(Organization $organization, AppInstance $app_instance, array $settings): AppInstance
    {
        // The chart's environment reads the web server's address
        $web_server = OrgServer::create(['organization_id' => $organization->id, 'server_id' => Server::factory()->create()->id]);
        $app_instance->web_server_id = $web_server->id;
        $app_instance->settings = array_replace_recursive($app_instance->settings ?? [], $settings);
        $app_instance->save();

        return $app_instance->fresh();
    }

    /**
     * @param  array<string, mixed>  $configurations
     * @param  array<string, mixed>  $additional_configs
     * @return array<string, mixed>
     */
    private static function planSettings(array $configurations, array $additional_configs = []): array
    {
        return [
            'base' => ['price' => 0, 'storage' => 2, 'price_id' => null],
            'basic' => ['max' => 1, 'name' => 'Basic', 'price' => 0, 'amount' => 1, 'storage' => 1, 'price_id' => null],
            'storage' => ['max' => 1, 'price' => 0, 'amount' => 1, 'price_id' => null],
            'standard' => ['max' => 1, 'price' => 0, 'storage' => 1, 'price_id' => null],
            'configurations' => $configurations,
            'additionalConfigs' => $additional_configs,
        ];
    }
}
