<?php

namespace App\Jobs\Users;

use App\Organization;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Support\Facades\Application;
use App\Support\Facades\Organization as OrganizationFacade;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateUserStorage implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(private Organization $organization) {}

    /**
     * Handle the event.
     *
     * @param  UpdateUserStorage  $event
     * @return void
     */
    public function handle()
    {
        OrganizationFacade::setOrganization($this->organization);
        $apps = $this->organization->app_instances;

        foreach ($apps as $app) {
            if (Application::instance($app)->plan()->hasUserStorage()) {
                $users = AccountManager::users()->appUsers($app);

                foreach ($users as $user) {
                    // Process user options on an app-by-app basis to make necessary adjustments directly through the apps API
                    Action::dispatch($app->application->slug, 'process_user_options', [$app, $user, []]);
                }
            }
        }
    }
}
