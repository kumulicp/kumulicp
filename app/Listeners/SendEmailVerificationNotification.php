<?php

namespace App\Listeners;

use App\Events\OrganizationRegistered;

class SendEmailVerificationNotification
{
    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle(OrganizationRegistered $event)
    {
        if (! $event->user->hasVerifiedEmail()) {
            $event->user->sendEmailVerificationNotification();
        }
    }
}
