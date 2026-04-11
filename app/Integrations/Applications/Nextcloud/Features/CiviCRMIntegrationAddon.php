<?php

namespace App\Integrations\Applications\Nextcloud\Features;

use App\AppInstance;
use App\Integrations\Applications\AppFeature;
use App\Integrations\Applications\Nextcloud\Actions\ManageAddon;

class CiviCRMIntegrationAddon extends AppFeature
{
    public $name = 'integration_civicrm';

    public $category = 'apps';

    public $type = 'checkbox';

    public $input = 'enable_disable';

    public $display_activation = true;

    public $display_options = true;

    public $var_name = 'CIVICRM_ADDON';

    public $action = ManageAddon::class;

    public function __construct()
    {
        $this->label = __('actions.nextcloud.civicrm_integration');
        $this->description = __('actions.nextcloud.civicrm_integration_description');
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
        return $app_instance->setting('features.civicrm.status') == 'enabled';
    }
}
