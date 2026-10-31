<?php

namespace App\Enums;

enum VersionTestRunStatus: string
{
    case RUNNING = 'running';

    case PASSED = 'passed';

    case FAILED = 'failed';

    // The tests were skipped on purpose; counts as approved
    case BYPASSED = 'bypassed';

    case CANCELLED = 'cancelled';

    /**
     * Whether a run in this status lets its version be enabled.
     */
    public function approves(): bool
    {
        return $this === self::PASSED || $this === self::BYPASSED;
    }
}
