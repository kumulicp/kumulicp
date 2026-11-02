<?php

namespace App\Services\Tickets\Integrations;

/**
 * What a driver can do.
 */
final class Capability
{
    public const CREATE_ISSUE = 'create_issue';

    public const COMMENT = 'comment';

    public const STATE_SYNC = 'state_sync';

    /** Time is recorded natively by the tracker (otherwise it is left as a comment) */
    public const TIME_TRACKING = 'time_tracking';

    public const EVENT_PUSH = 'event_push';
}
