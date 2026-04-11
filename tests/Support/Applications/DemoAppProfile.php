<?php

namespace Tests\Support\Applications;

use App\Integrations\Applications\AppProfile;
use App\Integrations\Applications\Wordpress\WordpressRancherJobs;
use App\Integrations\ServerManagers\Rancher\Charts\WordpressChart;

class DemoAppProfile extends AppProfile
{
    protected $name = 'demo_app';

    protected $activation_type = 'chart';

    protected $helm_chart = WordpressChart::class;

    protected $jobs = WordpressRancherJobs::class;

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

    protected $configurations = [
        'fake-config' => [
            'name' => 'fake-config',
            'type' => 'bool',
            'persistent' => false,
            'default' => false,
            'validations' => 'boolean',
        ],
    ];
}
