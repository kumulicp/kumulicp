<?php

namespace App\Integrations\Applications\Nextcloud\Features;

use App\AppInstance;

interface Features
{
    public function __construct(?AppInstance $app_instance = null);

    public function pricing_options();

    public function status(AppInstance $app_instance);
}
