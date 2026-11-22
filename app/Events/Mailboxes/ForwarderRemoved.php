<?php

namespace App\Events\Mailboxes;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A forwarder was removed. It carries what the mail server needs to stop forwarding, since the
 * forwarder itself is gone.
 */
class ForwarderRemoved
{
    use Dispatchable;

    public function __construct(public int $organization_id, public int $domain_id, public string $address) {}
}
