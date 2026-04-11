<?php

namespace App\Integrations\ServerManagers\Rancher\Listeners;

use App\Events\Domains\DomainDeleted;
use App\Integrations\ServerManagers\Rancher\Services\DomainMiddlewareService;

class UpdateIngressMiddleware
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  \App\Events\DomainDeleted  $event
     * @return void
     */
    public function handle(DomainDeleted $event)
    {
        foreach ($event->organization->active_apps() as $app) {
            if ($app->web_server->server->setting('traefik_middleware')) {
                $domain_middleware = new DomainMiddlewareService($event->organization, $app->web_server, $app);
                $domain_middleware->update();
            }
        }
    }
}
