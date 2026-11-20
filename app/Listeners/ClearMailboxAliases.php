<?php

namespace App\Listeners;

use App\Events\Mailboxes\MailboxDeactivated;
use App\Services\Email\MailboxAliasService;

/**
 * A mailbox that is no longer active (the user switched back, or it was removed) has no aliases.
 */
class ClearMailboxAliases
{
    public function __construct(private MailboxAliasService $aliases) {}

    public function handle(MailboxDeactivated $event): void
    {
        $this->aliases->removeAll($event->mailbox);
    }
}
