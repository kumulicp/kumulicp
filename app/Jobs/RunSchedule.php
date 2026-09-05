<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

// schedule:run invokes TaskCleanup/PrerequisiteChecks/SystemTasks/etc.
// synchronously, including blocking calls like checkConnection()'s
// retry-with-sleep loop per stuck task. Running that inline in a web
// request ties up an Octane worker for the whole duration; queueing it
// keeps the "Run Schedule" admin button from starving other requests.
class RunSchedule implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Artisan::call('schedule:run');
    }
}
