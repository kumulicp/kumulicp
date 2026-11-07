<?php

use App\Application;
use App\Integrations\ServerManagers\Kubernetes\Charts\HelmChart;
use Tests\Support\TestSupports;

beforeEach(function () {
    (new TestSupports)->seed();
    (new TestSupports)->activateDemoApp();

    $this->instance = Application::where('slug', 'demo_app')->first()->instances()->first();
});

function storageChart($instance, ?int $app_storage = null): HelmChart
{
    $chart = new class($instance->organization, $instance) extends HelmChart
    {
        public function values(): array
        {
            return [];
        }
    };
    $chart->app_storage = $app_storage;

    return $chart;
}

it('gives a release with no storage worked out yet the smallest size, not 0', function () {
    // What a release reads from its hub when nothing has been stored for it
    expect(storageChart($this->instance, 0)->appStorage())->toBe(1);
});

it('does not store 0 as the storage of an instance', function () {
    $this->instance->updateSetting('chart_storage', null);

    // Nothing in the plan gives it any storage
    storageChart($this->instance)->appStorage();

    expect($this->instance->fresh()->setting('chart_storage'))->not->toBe(0);
});

it('uses the storage it was given', function () {
    expect(storageChart($this->instance, 7)->appStorage())->toBe(7);
});
