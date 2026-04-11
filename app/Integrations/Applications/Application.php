<?php

namespace App\Integrations\Applications;

use App\AppInstance;
use App\Integrations\Integration;
use App\Support\Facades\Application as ApplicationFacade;

class Application extends Integration
{
    private $base_uri = '';

    public $action_description = '';

    public $action = '';

    public function __construct(
        public AppInstance $app_instance
    ) {
        $organization = $app_instance->organization;

        parent::__construct($organization, $app_instance->web_server);
    }

    public function baseURI()
    {
        return $this->app_instance->address();
    }

    public function support_user()
    {
        $app = ApplicationFacade::instance($this->app_instance);

        return $app->configuration('username') ?? 'support';
    }

    public function setAppInstance($app_instance)
    {
        $this->app_instance = $app_instance;
        $this->organization = $app_instance->organization;

        return $this;
    }

    public function body()
    {
        return $this->body;
    }

    public function appName()
    {
        $class = explode('\\', get_called_class());

        return end($class);
    }
}
