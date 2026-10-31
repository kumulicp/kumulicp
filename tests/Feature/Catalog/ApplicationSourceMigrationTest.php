<?php

use App\Application;
use App\Enums\ApplicationSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills the source of existing applications', function () {
    $migration = require database_path('migrations/2026_10_30_000000_add_source_to_applications_table.php');
    $migration->down();

    foreach (['nextcloud', 'added-in-ui', 'gitea'] as $slug) {
        DB::table('applications')->insert(['name' => $slug, 'slug' => $slug, 'short_description' => '', 'description' => '', 'enabled' => 0, 'domain_option' => '[]', 'catalog' => $slug === 'gitea' ? '{"chart_name":"gitea"}' : null]);
    }

    $migration->up();

    expect(Schema::hasColumn('applications', 'source'))->toBeTrue();
    expect(Application::where('slug', 'nextcloud')->first()->source)->toBe(ApplicationSource::Profile);
    expect(Application::where('slug', 'added-in-ui')->first()->source)->toBe(ApplicationSource::Generic);
    expect(Application::where('slug', 'gitea')->first()->source)->toBe(ApplicationSource::HelmForge);
});
