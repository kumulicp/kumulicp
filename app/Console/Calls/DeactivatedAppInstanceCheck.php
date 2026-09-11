<?php

namespace App\Console\Calls;

use App\Actions\Apps\ApplicationDelete;
use App\AppInstance;
use App\Support\Facades\Action;

class DeactivatedAppInstanceCheck
{
    public function __invoke()
    {
        $apps = AppInstance::whereNot('status', 'deactivated')->whereDate('deactivate_at', '<', now())->get();

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
