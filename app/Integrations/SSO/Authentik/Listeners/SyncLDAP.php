<?php

namespace App\Integrations\SSO\Authentik\Listeners;

use App\Exceptions\ConnectionFailedException;
use App\Integrations\SSO\Authentik\API\Sources;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
                try {
                    $sources = (new Sources($event->organization, $app_instance->sso_server))->LDAPSync($app_instance);
                } catch (ConnectionFailedException $exception) {
                    Log::warning('Skipping LDAP sync for app instance '.$app_instance->id.': '.$exception->getMessage(), ['organization_id' => $event->organization->id]);
                }
            }
            $sso_servers[] = $app_instance->sso_server_id;
        }
    }
}
