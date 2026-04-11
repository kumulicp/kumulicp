<?php

namespace App\Integrations\SSO\Authentik\Listeners;

use App\Integrations\SSO\Authentik\API\Sources;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncLDAP implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
     * @return void
     */
    public function handle($event)
    {

        $app_instances = $event->organization->app_instances()->whereNotNull('sso_server_id')->get();
        $sso_servers = [];

        foreach ($app_instances as $app_instance) {
            if (! in_array($app_instance->sso_server_id, $sso_servers)) {
                $sources = (new Sources($event->organization, $app_instance->sso_server))->LDAPSync($app_instance);
            }
            $sso_servers[] = $app_instance->sso_server_id;
        }
    }
}
