<?php

use App\Application;
use App\ChartSource;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Tests\Support\VersionUpdates\ChartArchive;

beforeEach(function () {
    ChartSource::where('slug', 'helmforge')->first()->update(['url' => 'https://repo.test']);

    Http::fake([
        'repo.test/index.yaml' => Http::response(ChartArchive::index('gitea', ['1.0.0'])),
        'repo.test/gitea-1.0.0.tgz' => Http::response(ChartArchive::make('gitea', '1.0.0', ['replicaCount' => 1])),
    ]);
});

it('imports nothing while the app catalog is disabled', function () {
    config(['toggle.flags.app-catalog' => false]);

    $this->artisan('catalog:sync')->assertFailed();

    expect(Application::where('slug', 'gitea')->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('imports the catalog when the app catalog is enabled', function () {
    config(['toggle.flags.app-catalog' => true]);

    $this->artisan('catalog:sync')->assertSuccessful();

    expect(Application::where('slug', 'gitea')->exists())->toBeTrue();
});

function catalogSyncEvent()
{
    return collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'catalog:sync'));
}

it('skips the daily sync while the app catalog is disabled', function () {
    config(['toggle.flags.app-catalog' => false]);

    expect(catalogSyncEvent()->filtersPass(app()))->toBeFalse();
});

it('runs the daily sync when the app catalog is enabled', function () {
    config(['toggle.flags.app-catalog' => true]);

    expect(catalogSyncEvent()->filtersPass(app()))->toBeTrue();
});
