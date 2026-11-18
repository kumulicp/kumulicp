<?php

namespace App\Events\Mailboxes;

use App\Mailbox;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user's mailbox stopped being live (switched back, or removed). A mail server driver
 * that keeps its own accounts listens for this to turn the account off.
 */
class MailboxDeactivated
{
    use Dispatchable;

    public function __construct(public Mailbox $mailbox) {}
}
