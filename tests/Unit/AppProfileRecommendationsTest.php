<?php

use App\Integrations\Applications\AppProfile;

it('makes the settings of a version from the chart and image a profile recommends', function () {
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

    // The name of the chart is not the repository it is in; the version's name and path are not settings
    expect($profile->recommendedVersionSettings())->toBe([
        'chart_name' => 'demo-chart',
        'chart_version' => '2.5.0',
        'helm_repo_name' => 'https://charts.example.com',
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
