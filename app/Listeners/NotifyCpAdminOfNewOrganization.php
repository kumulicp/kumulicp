<?php

namespace App\Listeners;

use App\Events\OrganizationCreated;
use App\Notifications\NewOrganizationCreated;
use App\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyCpAdminOfNewOrganization implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct() {}

    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle(OrganizationCreated $event)
    {
        $superaccount = Organization::where('type', 'superaccount')->first();

        if ($superaccount) {
            $superaccount->notifyAdmins(new NewOrganizationCreated($event->organization));
        }
    }
}
