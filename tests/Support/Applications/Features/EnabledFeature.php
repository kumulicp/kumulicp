<?php

namespace Tests\Support\Applications\Features;

use App\AppInstance;
use App\Integrations\Applications\AppFeature;

class EnabledFeature extends AppFeature
{
    public $name = 'enabled-feature';

    public $category = 'apps';

    public $type = 'checkbox';

    public $input = 'enable_disable';

    public $display_activation = true;

    public $display_options = true;

    public function __construct()
    {
        $this->label = 'Enabled Feature';
        $this->description = 'A test feature that is enabled.';
    }

    public function pricing_options()
    {
        return [];
    }

    public function admin_settings()
    {
        return [];
    }

    public function status(AppInstance $app_instance)
    {
        return $app_instance->setting('features.enabled-feature.status') == 'enabled';
    }
}
