<?php

namespace App\Console\Calls;

use App\Services\Tasks\TaskDispatcher;

class DispatchTasks
{
    public function __invoke()
    {
        app(TaskDispatcher::class)->tick();
    }
}
