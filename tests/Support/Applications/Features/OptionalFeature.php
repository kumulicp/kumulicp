<?php

namespace Tests\Support\Applications\Features;

use App\AppInstance;
use App\Integrations\Applications\AppFeature;

class OptionalFeature extends AppFeature
{
    public $name = 'optional-feature';

    public $category = 'apps';

    public $type = 'checkbox';

    public $input = 'enable_disable';

    public $display_activation = true;

    public $display_options = true;

    public function __construct()
    {
        $this->label = 'Optional Feature';
        $this->description = 'A test feature that is optional.';
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
        return $app_instance->setting('features.optional-feature.status') == 'enabled';
    }
}
