<?php

use App\Application;
use App\Enums\ApplicationSource;
use App\Services\ApplicationService;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestSupports;

it('backfills the source of existing applications', function () {
    $migration = require database_path('migrations/2026_10_30_000000_add_source_to_applications_table.php');
    $migration->down();

    foreach (['nextcloud', 'added-in-ui'] as $slug) {
        DB::table('applications')->insert(['name' => $slug, 'slug' => $slug, 'short_description' => '', 'description' => '', 'enabled' => 0]);
    }

    $migration->up();

    expect(Schema::hasColumn('applications', 'source'))->toBeTrue();
    expect(Application::where('slug', 'nextcloud')->first()->source)->toBe(ApplicationSource::Profile);
    expect(Application::where('slug', 'added-in-ui')->first()->source)->toBe(ApplicationSource::Generic);
});

it('defaults an application to the generic source', function () {
    expect((new Application)->source)->toBe(ApplicationSource::Generic);
});

it('marks an application created from a registered profile as a profile app', function () {
    expect(app(ApplicationService::class)->initialize('nextcloud')->source)->toBe(ApplicationSource::Profile);
});

it('marks an application added in the admin UI as generic', function () {
    (new TestSupports)->seed();

    $this->actingAs(User::find(1))->post('/admin/apps', [
        'name' => 'Wiki',
        'slug' => 'wiki',
        'category' => 'Productivity',
        'description' => 'A wiki',
    ]);

    expect(Application::where('slug', 'wiki')->first()->source)->toBe(ApplicationSource::Generic);
});

it('shows the source of each application in the apps list', function () {
    (new TestSupports)->seed();
    Application::factory()->create(['slug' => 'from_profile', 'name' => 'From Profile', 'source' => ApplicationSource::Profile, 'enabled' => true]);

    $this->actingAs(User::find(1))->get('/admin/apps')->assertInertia(fn ($page) => $page
        ->where('apps', fn ($apps) => collect($apps)->firstWhere('slug', 'from_profile')['source'] === 'Registered'));
});

it('labels every source', function () {
    foreach (ApplicationSource::cases() as $source) {
        expect($source->label())->not->toBe("admin.applications.sources.{$source->value}");
    }
});

it('sets up the recommended version as the default and a default plan when an app is first loaded', function () {
    $app = app(ApplicationService::class)->initialize('nextcloud');

    $version = $app->versions()->sole();
    expect($version->name)->toBe('33.0.1');
    expect($version->status)->toBe('active');
    // Where the chart comes from is the app's chart; the version has its version
    $chart = $app->charts()->sole();
    expect($chart->is_default)->toBeTrue();
    expect($chart->chart_name)->toBe('nextcloud');
    expect($chart->helm_repo)->toBe('https://nextcloud.github.io/helm/');
    expect($version->version_group_id)->toBe($chart->defaultVersionGroup()->id);
    expect($version->setting('chart_name'))->toBeNull();
    expect($version->setting('chart_version'))->toBe('9.0.5');
    expect($version->setting('image_repo_name'))->toBe('library/nextcloud');
    expect($app->enabled)->toBeFalse();

    $plan = $app->plans()->sole();
    expect($plan->is_default)->toBeTrue();
    expect($app->default_plan()->id)->toBe($plan->id);
    expect($plan->setting('configurations.redis.enabled'))->toBeTrue();
    expect($plan->setting('configurations'))->toBeArray()->not->toBeEmpty();
});

it('leaves an app without a recommended version without a version or plan', function () {
    $app = app(ApplicationService::class)->initialize('generic');

    expect($app->versions()->count())->toBe(0);
    expect($app->plans()->count())->toBe(0);
});
