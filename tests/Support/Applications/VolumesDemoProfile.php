<?php

namespace Tests\Support\Applications;

use App\Integrations\Applications\AppProfile;

/**
 * An app that declares the volumes a chart with a Redis and a MariaDB next to it would.
 */
class VolumesDemoProfile extends AppProfile
{
    protected $name = 'volumes_demo';

    protected $activation_type = 'chart';

    protected $helm_chart = DemoHelmChart::class;

    protected $volumes = [
        'data' => ['label' => 'admin.volumes.data', 'size' => 'quota', 'claim' => true, 'enabled' => false, 'storage_class' => '', 'access_mode' => 'ReadWriteOnce'],
        'redis-master' => ['optional' => true, 'size' => 8, 'enabled' => false, 'storage_class' => ''],
        'redis-replica' => ['optional' => true, 'size' => 8, 'enabled' => false, 'storage_class' => ''],
        'mariadb' => ['optional' => true, 'size' => 4, 'access_mode' => 'ReadWriteOnce'],
    ];
}
