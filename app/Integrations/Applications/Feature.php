<?php

namespace App\Integrations\Applications;

use App\AppInstance;

interface Feature
{
    public function __construct(?AppInstance $app_instance = null);

    public function pricing_options();

    public function status(AppInstance $app_instance);
}
