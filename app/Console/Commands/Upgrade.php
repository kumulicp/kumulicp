<?php

namespace App\Console\Commands;

use App\AppVersion;
use App\Organization;
use App\Plan;
use App\RepoSecret;
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
        '0.159' => 'alpha_159',
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

        Settings::update('installed_version', array_key_last($this->versions));

        return 0;
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

    // Migrate the legacy "image_registry" version setting to the new
    // RepoSecret structure, so registries can be managed centrally and
    // reused between apps.
    private function alpha_159()
    {
        $registries_by_name = [];

        foreach (AppVersion::all() as $version) {
            $registry = $version->setting('image_registry');

            if (! $registry || $version->image_registry_id) {
                continue;
            }

            if (! array_key_exists($registry, $registries_by_name)) {
                $registries_by_name[$registry] = RepoSecret::firstOrCreate(
                    ['registry' => $registry],
                    ['name' => $registry]
                );
            }

            $version->image_registry_id = $registries_by_name[$registry]->id;

            $settings = $version->settings ?? [];
            unset($settings['image_registry']);
            $version->settings = $settings;

            $version->save();
        }
    }
}
