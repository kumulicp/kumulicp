<?php

namespace Tests\Support\Applications;

use App\Integrations\Applications\AppProfile;
use Tests\Support\Applications\Features\DisabledFeature;
use Tests\Support\Applications\Features\EnabledFeature;
use Tests\Support\Applications\Features\OptionalFeature;

class DemoAppProfile extends AppProfile
{
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
