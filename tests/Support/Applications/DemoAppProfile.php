<?php

namespace Tests\Support\Applications;

use App\AppInstance;
use App\Integrations\Applications\AppProfile;
use Tests\Support\Applications\Features\DisabledFeature;
use Tests\Support\Applications\Features\EnabledFeature;
use Tests\Support\Applications\Features\OptionalFeature;

class DemoAppProfile extends AppProfile
{
    // Set while a test needs the app to be one whose shared children keep a site, and groups, of their own
    public static bool $multisite = false;

    public function isCompatible(string|array $compatibility): bool
    {
        $flags = (array) $compatibility;

        return (static::$multisite && in_array('multisite', $flags, true)) || parent::isCompatible($compatibility);
    }

    // Set while a test needs the app's site to live in a parent's release, made by a job
    public static bool $job_activation = false;

    public function activationType(?AppInstance $app_instance = null)
    {
        return static::$job_activation && $app_instance ? 'job' : parent::activationType($app_instance);
    }

    // A plan can name the local admin, like an app that is not 'support' does
    public function adminUsername(AppInstance $app_instance): string
    {
        return $app_instance->plan?->setting('admin_username') ?: parent::adminUsername($app_instance);
    }

    // An instance on a plan with this setting is only a container, like the shared ERPNext bench
    public function hasOwnSite(?AppInstance $app_instance = null): bool
    {
        return ! $app_instance?->plan?->setting('container_only');
    }

    protected $name = 'demo_app';

    protected $activation_type = 'chart';

    protected $compatibility = ['shareable'];

    protected $helm_chart = DemoHelmChart::class;

    protected $jobs = DemoJobs::class;

    protected $role_groups = [
        'demo_group' => [
            'id' => 'demo_group',
            'label' => 'Demo Group',
            'roles' => [
                'demo_role',
                'basic_demo_role',
                'minimal_demo_role',
            ],
        ],
    ];

    protected $roles = [
        'demo_role' => [
            'id' => 'demo_role',
            'label' => 'Demo Role',
            'role_group' => 'demo_group',
            'access_type' => 'standard',
        ],
        'basic_demo_role' => [
            'id' => 'basic_demo_role',
            'label' => 'Basic Demo Role',
            'role_group' => 'demo_group',
            'access_type' => 'basic',
        ],
        'minimal_demo_role' => [
            'id' => 'minimal_demo_role',
            'label' => 'Minimal Demo Role',
            'role_group' => 'demo_group',
            'access_type' => 'minimal',
        ],
    ];

    protected $recommendations = [
        'helm_chart_version' => '2.5.0',
        'helm_chart_name' => 'demo-chart',
        'image_repo' => 'demo/app',
        'image_registry' => 'registry.example.com',
        'admin_path' => '/admin',
    ];

    protected $features = [
        'enabled-feature' => EnabledFeature::class,
        'optional-feature' => OptionalFeature::class,
        'disabled-feature' => DisabledFeature::class,
    ];

    protected $configurations = [
        'fake-config' => [
            'name' => 'fake-config',
            'type' => 'bool',
            'persistent' => false,
            'default' => false,
            'validations' => 'boolean',
        ],
        'persistent-value' => [
            'name' => 'persistent-value',
            'type' => 'string',
            'persistent' => true,
            'default' => 'default-persistent',
            'validations' => 'nullable|string',
        ],
        'non-persistent-value' => [
            'name' => 'non-persistent-value',
            'type' => 'string',
            'persistent' => false,
            'default' => 'default-non-persistent',
            'validations' => 'nullable|string',
        ],
        'override-value' => [
            'name' => 'override-value',
            'type' => 'string',
            'persistent' => false,
            'default' => 'default-override',
            'validations' => 'nullable|string',
        ],
    ];
}
