<?php

namespace App\Events\Mailboxes;

use App\EmailForwarder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A forwarder was made, or its destinations changed (one was added, removed or confirmed). The
 * mail server plugin listens for this and applies the forwarder's live destinations
 * (`EmailForwarder::liveDestinations()`), reading them from the database, which is the source
 * of truth. The forwarder is `pending` until the plugin says it applied the change.
 */
class ForwarderChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(public EmailForwarder $forwarder) {}
}
