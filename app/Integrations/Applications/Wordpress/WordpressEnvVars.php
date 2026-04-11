<?php

namespace App\Integrations\Applications\Wordpress;

use App\AppInstance;
use App\Integrations\Applications\EnvVar;

class WordpressEnvVars extends EnvVar
{
    public function get(AppInstance $app_instance)
    {
        $default_settings = [
            'APP_URL' => $app_instance->address(),
        ];

        return $default_settings;
    }
}
