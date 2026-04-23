<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Nwidart\Modules\Facades\Module;
use Symfony\Component\Process\Process;

class PackageManagerService
{
    protected string $registryUrl = '';

    protected Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => rtrim($this->registryUrl, '/'),
            'timeout'  => 30,
        ]);
    }

    /**
     * Fetch the list of available packages from the private registry.
     * Returns an array keyed by "vendor/name" => [ versions => [...], description, ... ]
     */
    public function getRegistryPackages(): array
    {
        if (empty($this->registryUrl)) {
            return [];
        }

        try {
            $response = $this->http->get('/packages.json');
            $data     = json_decode((string) $response->getBody(), true);

            $packages = [];
            foreach ($data['packages'] ?? [] as $packageName => $versions) {
                $latest = $this->resolveLatestVersion($versions);
                $packages[$packageName] = [
                    'name'        => $packageName,
                    'description' => $latest['description'] ?? '',
                    'versions'    => array_keys($versions),
                    'latest'      => array_key_first($versions),
                    'type'        => $latest['type'] ?? 'library',
                    'keywords'    => $latest['keywords'] ?? [],
                    'homepage'    => $latest['homepage'] ?? '',
                    'authors'     => $latest['authors'] ?? [],
                ];
            }

            return $packages;
        } catch (RequestException $e) {
            return [];
        }
    }

    /**
     * Fetch details for a single package from the registry.
     */
    public function getRegistryPackageInfo(string $vendor, string $name): ?array
    {
        if (empty($this->registryUrl)) {
            return null;
        }

        $packageName = "{$vendor}/{$name}";

        try {
            $response = $this->http->get('/p2/' . $packageName . '.json');
            $data     = json_decode((string) $response->getBody(), true);
            $versions = $data['packages'][$packageName] ?? [];

            if (empty($versions)) {
                return null;
            }

            $latest = $this->resolveLatestVersion($versions);

            return [
                'name'        => $packageName,
                'description' => $latest['description'] ?? '',
                'versions'    => array_column($versions, 'version'),
                'latest'      => $latest['version'] ?? '',
                'type'        => $latest['type'] ?? 'library',
                'keywords'    => $latest['keywords'] ?? [],
                'homepage'    => $latest['homepage'] ?? '',
                'authors'     => $latest['authors'] ?? [],
                'require'     => $latest['require'] ?? [],
                'license'     => $latest['license'] ?? [],
            ];
        } catch (RequestException $e) {
            return null;
        }
    }

    /**
     * Get all installed modules with their status from nwidart/laravel-modules.
     */
    public function getInstalledModules(): array
    {
        return collect(Module::all())->map(function ($module) {
            return [
                'name'        => $module->getName(),
                'alias'       => $module->getAlias(),
                'description' => $module->getDescription(),
                'version'     => $module->get('version', 'unknown'),
                'enabled'     => $module->isEnabled(),
                'path'        => $module->getPath(),
                'composer'    => $module->get('composer', []),
                'vendor'      => $this->resolveVendorFromModule($module),
            ];
        })->values()->toArray();
    }

    /**
     * Return combined list: registry packages with installed status merged in.
     */
    public function getPackageList(): array
    {
        $registry  = $this->getRegistryPackages();
        $installed = collect($this->getInstalledModules())->keyBy(function ($m) {
            $vendor = $m['vendor'] ?: 'unknown';
            return strtolower("{$vendor}/{$m['name']}");
        });

        // Packages available in registry
        $list = collect($registry)->map(function ($pkg) use ($installed) {
            $key     = strtolower($pkg['name']);
            $module  = $installed->get($key);

            return [
                'name'        => $pkg['name'],
                'description' => $pkg['description'],
                'latest'      => $pkg['latest'],
                'type'        => $pkg['type'],
                'installed'   => (bool) $module,
                'enabled'     => $module ? $module['enabled'] : false,
                'version'     => $module ? $module['version'] : null,
                'source'      => 'registry',
            ];
        })->values();

        // Installed modules not present in registry
        $registryNames = array_keys($registry);
        $localOnly = $installed->filter(function ($m) use ($registryNames) {
            $key = strtolower("{$m['vendor']}/{$m['name']}");
            return ! in_array($key, $registryNames);
        })->map(function ($m) {
            return [
                'name'        => "{$m['vendor']}/{$m['name']}",
                'description' => $m['description'],
                'latest'      => null,
                'type'        => 'laravel-module',
                'installed'   => true,
                'enabled'     => $m['enabled'],
                'version'     => $m['version'],
                'source'      => 'local',
            ];
        })->values();

        return $list->merge($localOnly)->toArray();
    }

    /**
     * Install a package via composer require.
     */
    public function install(string $package, ?string $version = null): array
    {
        $spec    = $version ? "{$package}:{$version}" : $package;
        $process = new Process(
            ['composer', 'require', $spec, '--no-interaction', '--no-ansi'],
            base_path(),
            null,
            null,
            300
        );
        $process->run();

        if ($process->isSuccessful()) {
            Artisan::call('optimize:clear');
        }

        return [
            'success' => $process->isSuccessful(),
            'output'  => trim($process->getOutput()),
            'error'   => trim($process->getErrorOutput()),
        ];
    }

    /**
     * Uninstall a package via composer remove, then delete its module directory.
     */
    public function uninstall(string $package): array
    {
        // Disable the module first if it is active
        $moduleName = $this->packageToModuleName($package);
        if ($moduleName && Module::find($moduleName)?->isEnabled()) {
            $this->disable($moduleName);
        }

        $process = new Process(
            ['composer', 'remove', $package, '--no-interaction', '--no-ansi'],
            base_path(),
            null,
            null,
            300
        );
        $process->run();

        // Remove leftover module directory from modules/
        if ($moduleName) {
            $modulePath = base_path("modules/{$moduleName}");
            if (File::isDirectory($modulePath)) {
                File::deleteDirectory($modulePath);
            }
        }

        if ($process->isSuccessful()) {
            Artisan::call('optimize:clear');
        }

        return [
            'success' => $process->isSuccessful(),
            'output'  => trim($process->getOutput()),
            'error'   => trim($process->getErrorOutput()),
        ];
    }

    /**
     * Enable a module via artisan module:enable.
     */
    public function enable(string $moduleName): array
    {
        $module = Module::find($moduleName);
        if (! $module) {
            return ['success' => false, 'error' => "Module '{$moduleName}' not found."];
        }

        Artisan::call('module:enable', ['module' => $moduleName]);
        Artisan::call('optimize:clear');

        return ['success' => true];
    }

    /**
     * Disable a module via artisan module:disable.
     */
    public function disable(string $moduleName): array
    {
        $module = Module::find($moduleName);
        if (! $module) {
            return ['success' => false, 'error' => "Module '{$moduleName}' not found."];
        }

        Artisan::call('module:disable', ['module' => $moduleName]);
        Artisan::call('optimize:clear');

        return ['success' => true];
    }

    /**
     * Get full info for a package: registry metadata merged with local module state.
     */
    public function getPackageInfo(string $vendor, string $name): array
    {
        $registryInfo = $this->getRegistryPackageInfo($vendor, $name);
        $moduleName   = ucfirst($name);
        $module       = Module::find($moduleName);

        $installed = (bool) $module;
        $enabled   = $module?->isEnabled() ?? false;
        $version   = $module?->get('version', 'unknown') ?? null;
        $path      = $module?->getPath() ?? null;

        return [
            'name'        => "{$vendor}/{$name}",
            'vendor'      => $vendor,
            'package'     => $name,
            'description' => $registryInfo['description'] ?? ($module?->getDescription() ?? ''),
            'versions'    => $registryInfo['versions'] ?? [],
            'latest'      => $registryInfo['latest'] ?? null,
            'type'        => $registryInfo['type'] ?? 'laravel-module',
            'keywords'    => $registryInfo['keywords'] ?? [],
            'homepage'    => $registryInfo['homepage'] ?? '',
            'authors'     => $registryInfo['authors'] ?? [],
            'require'     => $registryInfo['require'] ?? [],
            'license'     => $registryInfo['license'] ?? [],
            'installed'   => $installed,
            'enabled'     => $enabled,
            'version'     => $version,
            'path'        => $path,
            'module_name' => $moduleName,
        ];
    }

    // -------------------------------------------------------------------------

    protected function resolveLatestVersion(array $versions): array
    {
        // Registry packages.json lists versions newest-first
        return reset($versions) ?: [];
    }

    protected function resolveVendorFromModule($module): string
    {
        $composer = $module->get('name', '');
        if (str_contains($composer, '/')) {
            return explode('/', $composer)[0];
        }
        return '';
    }

    protected function packageToModuleName(string $package): ?string
    {
        // "vendor/my-module" => "MyModule"  (PascalCase of the package slug)
        if (str_contains($package, '/')) {
            $slug = explode('/', $package)[1];
            return str_replace(['-', '_'], '', ucwords($slug, '-_'));
        }
        return null;
    }
}
