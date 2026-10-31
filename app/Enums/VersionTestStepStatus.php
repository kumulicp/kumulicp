<?php

namespace App\Enums;

enum VersionTestStepStatus: string
{
    case PENDING = 'pending';

    // The test org is being created and the app installed or upgraded
    case PROVISIONING = 'provisioning';

    // Ready for the admin to try out and confirm
    case AWAITING_CONFIRMATION = 'awaiting_confirmation';

    // The test org is being deleted; the outcome is already decided
    case CLEARING = 'clearing';

    case PASSED = 'passed';

    case FAILED = 'failed';

    /**
     * A step in one of these statuses holds the single test slot.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::PROVISIONING, self::AWAITING_CONFIRMATION, self::CLEARING], true);
    }

    /**
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->isActive())
        ));
    }
}
