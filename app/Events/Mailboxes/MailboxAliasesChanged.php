<?php

namespace App\Events\Mailboxes;

use App\Mailbox;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The aliases of a mailbox changed (one was added or removed, or they were all dropped because
 * the mailbox stopped being active). The mail server plugin listens for this and reads the
 * mailbox's addresses from `mailbox_addresses`, the source of truth; with LDAP the directory is
 * written separately.
 */
class MailboxAliasesChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(public Mailbox $mailbox) {}
}
