<?php

namespace App\Enums;

/**
 * Why an organization was deactivated. 'custom' means the admin wrote their
 * own reason, kept in the organization's deactivation_reason_note.
 */
enum DeactivationReason: string
{
    case REQUESTED_BY_ORGANIZATION = 'requested_by_organization';

    case NON_PAYMENT = 'non_payment';

    case POLICY_VIOLATION = 'policy_violation';

    case INACTIVITY = 'inactivity';

    // Set by the system when a subscription runs out, never picked by an admin
    case SUBSCRIPTION_ENDED = 'subscription_ended';

    case CUSTOM = 'custom';

    public function label(): string
    {
        return __('admin.organizations.deactivation_reasons.'.$this->value);
    }

    /**
     * The reasons an admin can pick from. The first is the default.
     *
     * @return array<int, self>
     */
    public static function selectable(): array
    {
        return [
            self::REQUESTED_BY_ORGANIZATION,
            self::NON_PAYMENT,
            self::POLICY_VIOLATION,
            self::INACTIVITY,
            self::CUSTOM,
        ];
    }

    public static function default(): self
    {
        return self::selectable()[0];
    }
}
