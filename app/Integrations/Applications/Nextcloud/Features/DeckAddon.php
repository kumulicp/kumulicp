<?php

namespace App\Integrations\Applications\Nextcloud\Features;

use App\AppInstance;
use App\Integrations\Applications\AppFeature;
use App\Integrations\Applications\Nextcloud\Actions\ManageAddon;

class DeckAddon extends AppFeature
{
    public $name = 'deck';

    public $category = 'apps';

    public $type = 'checkbox';

    public $input = 'enable_disable';

    public $display_activation = true;

    public $display_options = true;

    public $var_name = 'DECK_ADDON';

    public $action = ManageAddon::class;

    public function __construct()
    {
        $this->label = __('actions.nextcloud.deck_addon');
        $this->description = __('actions.nextcloud.deck_addon_description');
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
        return $app_instance->setting('features.deck.status') == 'enabled';
    }
}
