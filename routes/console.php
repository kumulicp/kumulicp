<?php

use App\Console\Calls\ActivateBackup;
use App\Console\Calls\DeactivatedAppInstanceCheck;
use App\Console\Calls\DeleteBackups;
use App\Console\Calls\ExpiredSubscriptionsCheck;
use App\Console\Calls\NextcloudStorageChecks;
use App\Console\Calls\NotifyBillingManagers;
use App\Console\Calls\PrerequisiteChecks;
use App\Console\Calls\RecurringBackups;
use App\Console\Calls\SystemTasks;
use App\Console\Calls\TaskCleanup;
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

Schedule::call(new TaskCleanup)->everyMinute();
Schedule::call(new PrerequisiteChecks)->everyMinute();
Schedule::call(new SystemTasks)->everyMinute();
Schedule::call(new RecurringBackups)->everyMinute();
Schedule::call(new UpdateTransferStatus)->everyMinute();
Schedule::call(new NextcloudStorageChecks)->daily();
Schedule::call(new ActivateBackup)->everyMinute();
Schedule::call(new DeleteBackups)->everyMinute();
Schedule::call(new NotifyBillingManagers)->daily();
Schedule::call(new ExpiredSubscriptionsCheck)->everyMinute();
Schedule::call(new DeactivatedAppInstanceCheck)->everyMinute();
Schedule::command('backup:clean')->daily()->at('01:00');
Schedule::command('backup:run')->daily()->at('01:30');
Schedule::command('queue:prune-batches')->daily();
Schedule::command(CheckUptime::class)->everyMinute();
Schedule::command(CheckCertificates::class)->daily();
