<?php

use App\Integrations\Applications\AppProfile;

it('has the chart a profile recommends: its name and the repository it is published in', function () {
    $profile = new class extends AppProfile
    {
        protected $recommendations = [
            'helm_chart_name' => 'demo-chart',
            'helm_chart_version' => '2.5.0',
            'helm_chart_repo' => 'https://charts.example.com',
            'image_repo' => 'demo/app',
        ];
    };

    // The version of the chart is the version's own
    expect($profile->recommendedChart())->toBe(['chart_name' => 'demo-chart', 'helm_repo' => 'https://charts.example.com'])
        ->and((new AppProfile)->recommendedChart())->toBe([]);
});

it('makes the settings of a version from the chart version and image a profile recommends', function () {
    $profile = new class extends AppProfile
    {
        protected $recommendations = [
            'helm_chart_name' => 'demo-chart',
            'helm_chart_version' => '2.5.0',
            'helm_chart_repo' => 'https://charts.example.com',
            'image_repo' => 'demo/app',
            'image_registry' => 'registry.example.com',
            'image_version' => '1.0.0',
            'admin_path' => '/admin',
        ];
    };

    // Where the chart is published is the chart's; the version's name and path are not settings
    expect($profile->recommendedVersionSettings())->toBe([
        'chart_version' => '2.5.0',
        'image_repo_name' => 'demo/app',
        'image_registry' => 'registry.example.com',
    ]);
});

it('leaves out what a profile does not recommend', function () {
    $profile = new class extends AppProfile
    {
        protected $recommendations = ['image_repo' => 'demo/app'];
    };

    expect($profile->recommendedVersionSettings())->toBe(['image_repo_name' => 'demo/app']);
});

it('has no settings for a profile that recommends nothing', function () {
    expect((new AppProfile)->recommendedVersionSettings())->toBe([]);
});
