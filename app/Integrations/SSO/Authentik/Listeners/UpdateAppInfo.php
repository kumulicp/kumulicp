<?php

namespace App\Integrations\SSO\Authentik\Listeners;

use App\Events\Apps\AppInstanceDomainChanged;
use App\Support\Facades\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateAppInfo implements ShouldQueue
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
     * @param  UserPermissionsUpdated  $event
     * @return void
     */
    public function handle(AppInstanceDomainChanged $event)
    {
        $sso_server = Application::instance($event->app_instance)->connect('sso');
        $sso_server->update();
    }
}
