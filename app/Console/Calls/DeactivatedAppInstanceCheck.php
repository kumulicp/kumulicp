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
            Action::execute(new ApplicationDelete($app));
        }
    }
}
