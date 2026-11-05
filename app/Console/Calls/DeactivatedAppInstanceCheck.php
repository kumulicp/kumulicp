<?php

namespace App\Console\Calls;

use App\Actions\Apps\ApplicationDelete;
use App\AppInstance;
use App\Support\Facades\Action;

class DeactivatedAppInstanceCheck
{
    public function __invoke()
    {
        // Compare the full timestamp (not whereDate) so an app due "now" is
        // picked up on the next pass rather than the next calendar day.
        // 'deleting' already has an ApplicationDelete task in flight, and apps
        // of a deactivated organization are handled by DeactivateOrganization's
        // ApplicationDeactivate tasks -- sweeping either would collide.
        $apps = AppInstance::whereNotIn('status', ['deactivated', 'deleting'])
            ->where('deactivate_at', '<=', now())
            ->whereDoesntHave('organization', fn ($query) => $query->where('status', 'deactivated'))
            ->get();

        foreach ($apps as $app) {
            try {
                Action::execute(new ApplicationDelete($app));
            } catch (\Throwable $e) {
                // e.g. a shared-app hub still has children registered
                // against it -- don't let one blocked app stop the rest of
                // the sweep.
                report($e);
            }
        }
    }
}
