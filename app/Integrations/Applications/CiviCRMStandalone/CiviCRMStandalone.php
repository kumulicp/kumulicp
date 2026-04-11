<?php

namespace App\Integrations\Applications\CiviCRMStandalone;

use App\Integrations\Applications\Application;

class CiviCRMStandalone extends Application
{
    public $name = 'civicrm-standalone';

    public function basePath()
    {
        return $this->app_instance->address().'/civicrm';
    }

    public function headers()
    {
        return [
            'X-Civi-Auth' => 'Basic '.base64_encode('support:'.$this->app_instance->parent->api_password()),
            'X-Requested-With' => 'XMLHttpRequest',
        ];

    }

    public function parseResponse($response)
    {
        $this->setResponse(json_decode($response, true));
    }
}
