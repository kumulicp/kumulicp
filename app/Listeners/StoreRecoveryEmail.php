<?php

namespace App\Listeners;

use App\Events\Users\UserCreated;
use App\Services\Email\RecoveryEmailService;

/**
 * The address a user is created with (the one their invite goes to) is kept as their
 * recovery email, so it is still there when an organization address replaces it as `mail`.
 */
class StoreRecoveryEmail
{
    public function __construct(private RecoveryEmailService $recovery_emails) {}

    public function handle(UserCreated $event): void
    {
        $username = (string) $event->user->attribute('username');
        $mail = (string) $event->user->attribute('email');

        if ($username === '' || $mail === '' || $this->recovery_emails->get($username) !== null) {
            return;
        }

        $this->recovery_emails->set($username, $mail);
    }
}
