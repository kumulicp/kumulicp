<?php

namespace App\Support\Billing;

use App\Enums\TaskStatus;
use App\Organization;
use App\Task;

/**
 * Where the queued SubscriptionUpdate task of an organization stands, for the billing pages.
 *
 * The plan itself changes immediately; what the billing provider and the invoices show catches up
 * when that task runs. This lets the pages say so instead of looking out of date, or broken when
 * the task failed.
 */
class BillingSyncState
{
    /**
     * Deliberately without the reason for a failure: it is the billing provider's own message (a missing
     * coupon or price, say). Admins find it on the task.
     *
     * @return array{pending: bool, failed: bool}
     */
    public static function for(Organization $organization): array
    {
        $latest = Task::where('organization_id', $organization->id)
            ->where('action_slug', 'subscription_update')
            ->latest('id')
            ->first();

        $status = $latest?->status;

        // Failed and blocked tasks are open too, but nothing is running: they need attention
        $failed = in_array($status, [TaskStatus::FAILED, TaskStatus::BLOCKED], true);

        return [
            'pending' => (bool) $status?->isOpen() && ! $failed,
            'failed' => $failed,
        ];
    }
}
