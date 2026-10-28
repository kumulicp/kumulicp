<?php

use App\Console\Calls\ActivateBackup;
use App\Console\Calls\CheckVersionUpdates;
use App\Console\Calls\DeactivatedAppInstanceCheck;
use App\Console\Calls\DeleteBackups;
use App\Console\Calls\ExpiredSubscriptionsCheck;
use App\Console\Calls\NextcloudStorageChecks;
use App\Console\Calls\NotifyBillingManagers;
use App\Console\Calls\PrerequisiteChecks;
use App\Console\Calls\RecurringBackups;
use App\Console\Calls\SystemTasks;
use App\Console\Calls\TaskCleanup;
use App\Console\Calls\TaskReaper;
use App\Integrations\Registrars\Namecheap\Jobs\UpdateTransferStatus;
use Illuminate\Support\Facades\Schedule;
use Spatie\UptimeMonitor\Commands\CheckCertificates;
use Spatie\UptimeMonitor\Commands\CheckUptime;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

// The task runners are single-flight: a slow pass must not overlap the next
// minute's (two passes would claim and run the same tasks), and only one server
// runs each. The mutex expiry keeps a crashed run from blocking tasks for the
// default 24 hours.
Schedule::call(new TaskCleanup)->name('task-cleanup')->everyMinute()->withoutOverlapping(15)->onOneServer();
Schedule::call(new PrerequisiteChecks)->name('prerequisite-checks')->everyMinute()->withoutOverlapping(15)->onOneServer();
Schedule::call(new SystemTasks)->name('system-tasks')->everyMinute()->withoutOverlapping(15)->onOneServer();
Schedule::call(new TaskReaper)->name('task-reaper')->everyMinute()->withoutOverlapping(15)->onOneServer();
Schedule::call(new RecurringBackups)->everyMinute();
Schedule::call(new UpdateTransferStatus)->everyMinute();
Schedule::call(new NextcloudStorageChecks)->daily();
Schedule::call(new ActivateBackup)->everyMinute();
Schedule::call(new DeleteBackups)->everyMinute();
Schedule::call(new NotifyBillingManagers)->daily();
Schedule::call(new ExpiredSubscriptionsCheck)->everyMinute();
Schedule::call(new DeactivatedAppInstanceCheck)->everyMinute();
Schedule::call(new CheckVersionUpdates)->daily()->at('03:00');
Schedule::command('backup:clean')->daily()->at('01:00');
Schedule::command('backup:run')->daily()->at('01:30');
Schedule::command('queue:prune-batches')->daily();
Schedule::command(CheckUptime::class)->everyMinute();
Schedule::command(CheckCertificates::class)->daily();
