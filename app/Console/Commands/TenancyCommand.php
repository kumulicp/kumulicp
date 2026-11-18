<?php

namespace App\Console\Commands;

use App\Enums\TenancyMode;
use App\Support\Facades\Settings;
use App\Support\Facades\Tenancy;
use Illuminate\Console\Command;

/**
 * Whether an install is for one organization or for many is chosen when it is
 * installed, and this is the only way to change it afterwards: it can't be done
 * from the control panel, because it changes who the control panel is for.
 */
class TenancyCommand extends Command
{
    protected $signature = 'kumulicp:tenancy
        {mode? : single or multi. Leave it out to see the current mode}
        {--force : Do not ask for confirmation}';

    protected $description = 'Show, or change, whether this install is for one organization or for many';

    public function handle(): int
    {
        $current = Tenancy::mode();
        $requested = $this->argument('mode');

        if ($requested === null) {
            $this->line("This install is {$current->value}: {$current->description()}");

            return self::SUCCESS;
        }

        $mode = TenancyMode::tryFrom((string) $requested);

        if (! $mode) {
            $this->error("Unknown mode \"{$requested}\". Use single or multi.");

            return self::FAILURE;
        }

        if ($mode === $current) {
            $this->info("This install is already {$mode->value}. Nothing to change.");

            return self::SUCCESS;
        }

        if (Settings::get('installed') != 1) {
            $this->error('This control panel is not installed yet. Choose the mode on the setup screen, or set TENANCY_MODE before installing.');

            return self::FAILURE;
        }

        if ($mode === TenancyMode::Single) {
            if ($reasons = Tenancy::whySingleIsNotPossible()) {
                $this->error("This install can't be switched to single yet:");
                foreach ($reasons as $reason) {
                    $this->line("  - {$reason}");
                }

                return self::FAILURE;
            }

            $this->warn('Switching to single hides plans, subscriptions, billing and sign-up. The one organization gets every app and no limits, and installing apps becomes admin-only.');
        } else {
            $this->warn('Switching to multi brings back plans, subscriptions and billing. The one organization keeps its unlimited plan, and billing and sign-up still have to be set up, so nobody can subscribe until they are.');
        }

        if (! $this->option('force') && ! $this->confirm("Switch this install to {$mode->value}?", false)) {
            $this->line('Nothing was changed.');

            return self::FAILURE;
        }

        Tenancy::switchTo($mode);

        // Workers started before the change still hold the old mode
        $this->callSilently('queue:restart');

        $this->info("This install is now {$mode->value}.");
        $this->line('Queue workers were told to restart. If you run Octane, reload it too (php artisan octane:reload) so it picks up the change.');

        return self::SUCCESS;
    }
}
