<?php

namespace App\Events\Mailboxes;

use App\Mailbox;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user's mailbox went live: the user is signed in with its address now, and the mail
 * server has to have a mailbox for it. The LDAP docker-mailserver driver is already
 * provisioned by the time this fires (its mailbox is the user's own directory entry);
 * a mail server driver that keeps its own accounts listens for this.
 */
class MailboxActivated
{
    use Dispatchable;

    public function __construct(public Mailbox $mailbox) {}
}
