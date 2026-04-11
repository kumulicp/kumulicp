<?php

namespace App\Integrations\Applications\Nextcloud\Features;

use App\AppInstance;
use App\Integrations\Applications\AppFeature;
use App\Integrations\Applications\Nextcloud\Actions\ManageAddon;

class CalendarAddon extends AppFeature implements Features
{
    public $name = 'calendar';

    public $type = 'checkbox';

    public $category = 'apps';

    public $var_name = 'CALENDAR_ADDON';

    public $input = 'enable_disable';

    public $display_activation = true;

    public $display_options = true;

    public $action = ManageAddon::class;

    public function __construct(?AppInstance $app_instance = null)
    {
        $this->app_instance = $app_instance;
        $this->label = __('actions.nextcloud.calendar_addon');
        $this->description = __('actions.nextcloud.calendar_addon_description');
    }

    public function pricing_options()
    {
        return [];
    }

    public function action() {}

    public function admin_settings()
    {
        return [];
    }

    public function status(AppInstance $app_instance)
    {
        return $app_instance->setting('features.calendar.status') == 'enabled';
    }
}
