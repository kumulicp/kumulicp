<?php

namespace App\Console\Commands;

use App\AppVersion;
use App\Organization;
use App\Plan;
use App\RepoSecret;
use App\Support\Facades\Settings;
use Composer\InstalledVersions;
use Database\Seeders\WorldSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Nnjeim\World\Models\Country;

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

        $this->syncWorldData();

        return 0;
    }

    // The country/state data ships inside the nnjeim/world package, so it only
    // needs reloading on first install or when the package version changes.
    private function syncWorldData()
    {
        $package_version = InstalledVersions::getPrettyVersion('nnjeim/world');

        if (Country::exists() && Settings::get('world_data_version') === $package_version) {
            return;
        }

        $this->info('Loading country and state data ('.$package_version.')');

        $this->call('db:seed', ['--class' => WorldSeeder::class, '--force' => true]);

        Cache::forget('locations.countries');
        foreach (Country::pluck('iso2') as $iso2) {
            Cache::forget('locations.states.'.$iso2);
        }

        Settings::update('world_data_version', $package_version);
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
