<?php

namespace App\Integrations\Applications;

use App\AppInstance;

class AppFeature
{
    public $name = '';

    public $label = '';

    public $description = '';

    public ?AppInstance $app_instance = null;
}
