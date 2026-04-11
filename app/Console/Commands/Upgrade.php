<?php

namespace App\Console\Commands;

use App\Organization;
use App\Plan;
use App\Support\Facades\Settings;
use Illuminate\Console\Command;

class Upgrade extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'upgrade';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '';

    private $versions = [
        '0.158' => 'alpha_158',
    ];

    public function __construct()
    {
        $this->description = __('actions.update').' '.config('app.name');
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $current_version = Settings::get('installed_version');

        ksort($this->versions);
        foreach ($this->versions as $version => $function) {
            if ($version > $current_version) {
                $this->$function();
            }
        }

        Settings::update('installed_version', $version);
    }

    private function alpha_215()
    {
        // Encrypt api_tokens
        foreach (Organization::all() as $org) {
            $original_api_token = $org->getRawOriginal('api_token');
            $org->api_token = $original_api_token;
            $org->save();
        }
    }

    private function alpha_158()
    {
        foreach (Plan::all() as $plan) {
            $plan->updateSettings(['suborganizations.enabled' => false]);
            $plan->save();
        }
    }
}
