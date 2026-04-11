<?php

namespace App\Integrations\Applications\Nextcloud\Features;

use App\AppInstance;
use App\Integrations\Applications\AppFeature;
use App\Integrations\Applications\Nextcloud\Actions\ManageAddon;

class ContactsAddon extends AppFeature
{
    public $name = 'contacts';

    public $category = 'apps';

    public $type = 'checkbox';

    public $input = 'enable_disable';

    public $display_activation = true;

    public $display_options = true;

    public $var_name = 'CONTACTS_ADDON';

    public $action = ManageAddon::class;

    public function __construct()
    {
        $this->label = __('actions.nextcloud.contacts_addon');
        $this->description = __('actions.nextcloud.contacts_addon_description');
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
        return $app_instance->setting('features.contacts.status') == 'enabled';
    }
}
