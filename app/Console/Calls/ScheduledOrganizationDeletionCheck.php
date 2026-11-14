<?php

namespace App\Console\Calls;

use App\Actions\Organizations\DeleteOrganization;
use App\Enums\TaskStatus;
use App\Organization;
use App\Support\Facades\Action;

class ScheduledOrganizationDeletionCheck
{
    public function __invoke()
    {
        $organizations = Organization::whereNotNull('deletion_scheduled_at')
            ->where('deletion_scheduled_at', '<=', now())
            ->get();

        foreach ($organizations as $organization) {
            // Deactivating the organization stops its apps; deleting while that
            // (or an earlier delete) is still in flight would collide with it
            $in_flight = $organization->tasks()
                ->whereIn('action_slug', ['deactivate_organization', 'delete_organization'])
                ->whereIn('status', TaskStatus::openValues())
                ->exists();

            if ($in_flight) {
                continue;
            }

            try {
                Action::execute(new DeleteOrganization($organization));
            } catch (\Throwable $e) {
                // Don't let one organization stop the rest of the sweep
                report($e);
            }
        }
    }
}
