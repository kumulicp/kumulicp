<?php

namespace App\Services;

use App\Support\Facades\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Nwidart\Modules\Contracts\RepositoryInterface;
use ZipArchive;

class PackageManagerService
{
    protected string $registry_url;

    protected Client $http;

    protected bool $allowUnstable;

    public function __construct()
    {
        $this->allowUnstable = (bool) Settings::get('packages_allow_unstable', false);
        $this->registry_url = $this->resolveRegistryUrl();

        // TEMPORARY: remove second line below once repo is public and auth is handled by composer
        $token = $this->resolveRegistryToken();
        $this->http = new Client([
            'base_uri' => rtrim($this->registry_url, '/').'/',
            'timeout' => 30,
            'headers' => $token ? ['Authorization' => "Bearer {$token}"] : [],
        ]);
    }

    private function modules(): RepositoryInterface
    {
        return app(RepositoryInterface::class);
    }

    /**
     * Fetch the list of available packages from the private registry.
     * Returns an array keyed by "vendor/name" => [ versions => [...], description, ... ]
     */
    public function getRegistryPackages(): array
    {
        if (empty($this->registry_url)) {
            return [];
        }

        try {
            $listData = json_decode((string) $this->http->get('list.json')->getBody(), true);
            $package_names = $listData['packageNames'] ?? [];

            $packages = [];
            foreach ($package_names as $package_name) {
                $meta_data = json_decode((string) $this->http->get('p2/'.$package_name.'.json')->getBody(), true);
                $versions = $meta_data['packages'][$package_name] ?? [];

                if (empty($versions)) {
                    continue;
                }

                $latest = $this->resolveLatestVersion($versions);

                // No version passes the current stability filter (e.g. every
                // release is beta and "allow unstable" is off) -- nothing
                // installable, so don't list the package at all.
                if (empty($latest)) {
                    continue;
                }

                $packages[$package_name] = [
                    'name' => $package_name,
                    'label' => $this->packageLabel($package_name),
                    'description' => $latest['description'] ?? '',
                    'versions' => array_column($this->filterByStability($versions), 'version'),
                    'latest' => $latest['version'] ?? '',
                    'isUnstable' => isset($latest['version']) && ! $this->isStable($latest['version']),
                    'type' => $latest['type'] ?? 'library',
                    'keywords' => $latest['keywords'] ?? [],
                    'homepage' => $latest['homepage'] ?? '',
                    'authors' => $latest['authors'] ?? [],
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
        if (empty($this->registry_url)) {
            return null;
        }

        $package_name = "{$vendor}/{$name}";

        try {
            $response = $this->http->get('p2/'.$package_name.'.json');
            $data = json_decode((string) $response->getBody(), true);
            $versions = $data['packages'][$package_name] ?? [];

            if (empty($versions)) {
                return null;
            }

            $latest = $this->resolveLatestVersion($versions);

            return [
                'name' => $package_name,
                'description' => $latest['description'] ?? '',
                'versions' => array_column($this->filterByStability($versions), 'version'),
                'latest' => $latest['version'] ?? '',
                'isUnstable' => isset($latest['version']) && ! $this->isStable($latest['version']),
                'type' => $latest['type'] ?? 'library',
                'keywords' => $latest['keywords'] ?? [],
                'homepage' => $latest['homepage'] ?? '',
                'authors' => $latest['authors'] ?? [],
                'require' => $latest['require'] ?? [],
                'license' => $latest['license'] ?? [],
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
        return collect($this->modules()->all())->map(function ($module) {
            $composer_json = $this->readModuleComposer($module);

            $composer_name = $composer_json['name'] ?? '';

            return [
                'name' => $module->getName(),
                'composer_name' => $composer_name,
                'description' => $composer_json['description'] ?? $module->getDescription(),
                'version' => $composer_json['version'] ?? null,
                'enabled' => $module->isEnabled(),
                'path' => $module->getPath(),
                'vendor' => $composer_name ? explode('/', $composer_name)[0] : '',
            ];
        })->values()->toArray();
    }

    /**
     * Return combined list: registry packages with installed status merged in.
     */
    public function getPackageList(): array
    {
        $registry = $this->getRegistryPackages();
        $installed = collect($this->getInstalledModules())->keyBy(function ($m) {
            return strtolower($m['composer_name'] ?: "{$m['vendor']}/{$m['name']}");
        });

        // Packages available in registry
        $list = collect($registry)->map(function ($pkg) use ($installed) {
            $module = $installed->get(strtolower($pkg['name']));

            $version = $module ? $module['version'] : null;

            return [
                'name' => $pkg['name'],
                'label' => $pkg['label'],
                'description' => $pkg['description'],
                'latest' => $pkg['latest'],
                'type' => $pkg['type'],
                'installed' => (bool) $module,
                'enabled' => $module ? $module['enabled'] : false,
                'version' => $version,
                'updateAvailable' => $this->isUpdateAvailable($version, $pkg['latest']),
                'isUnstable' => $pkg['isUnstable'],
                'source' => 'registry',
            ];
        })->values();

        // Installed modules not present in registry
        $registryNames = array_map('strtolower', array_keys($registry));
        $localOnly = $installed->filter(function ($m) use ($registryNames) {
            return ! in_array(strtolower($m['composer_name'] ?: "{$m['vendor']}/{$m['name']}"), $registryNames);
        })->map(function ($m) {
            $composer_name = $m['composer_name'] ?: "{$m['vendor']}/{$m['name']}";

            return [
                'name' => $composer_name,
                'label' => $this->packageLabel($composer_name),
                'description' => $m['description'],
                'latest' => null,
                'type' => 'laravel-module',
                'installed' => true,
                'enabled' => $m['enabled'],
                'version' => $m['version'],
                'updateAvailable' => false,
                'isUnstable' => false,
                'source' => 'local',
            ];
        })->values();

        return $list->merge($localOnly)->toArray();
    }

    /**
     * Install a package by downloading its dist zip from the registry and
     * extracting it straight into modules/ -- no composer require, no lock file.
     */
    public function install(string $package, ?string $version = null): array
    {
        return $this->installFromRegistry($package, $version);
    }

    /**
     * Disable the module (if active) and delete its directory from modules/.
     */
    public function uninstall(string $package): array
    {
        $moduleName = $this->packageToModuleName($package);
        if (! $moduleName) {
            return ['success' => false, 'error' => __('admin.packages.error_module_not_found', ['module' => $package])];
        }

        if ($this->modules()->find($moduleName)?->isEnabled()) {
            $this->disable($moduleName);
        }

        $modulePath = base_path("modules/{$moduleName}");
        if (File::isDirectory($modulePath)) {
            File::deleteDirectory($modulePath);
        }

        // deleteDirectory() swallows individual unlink/rmdir failures and
        // always returns true, so the only reliable signal that the
        // directory is actually gone is checking for it again afterward.
        if (File::isDirectory($modulePath)) {
            return ['success' => false, 'error' => __('admin.packages.error_module_delete_failed', ['module' => $package])];
        }

        Artisan::call('optimize:clear');
        $this->reloadOctane();

        return ['success' => true];
    }

    /**
     * Upgrade a package to a newer version. Since installing overwrites the
     * module directory in place, this is the same operation as install().
     */
    public function upgrade(string $package, ?string $version = null): array
    {
        return $this->installFromRegistry($package, $version);
    }

    /**
     * Install a module from an uploaded zip file.
     *
     * @return array{success: bool, error?: string, module?: string}
     */
    public function installFromZip(UploadedFile $file): array
    {
        return $this->installModuleZip($file->getRealPath());
    }

    /**
     * Resolve a package/version to a registry dist URL, download it, and
     * extract it into modules/ via installModuleZip().
     *
     * @return array{success: bool, error?: string, module?: string}
     */
    protected function installFromRegistry(string $package, ?string $version = null): array
    {
        [$vendor, $name] = array_pad(explode('/', $package, 2), 2, null);
        if (! $vendor || ! $name) {
            return ['success' => false, 'error' => __('admin.packages.error_module_not_found', ['module' => $package])];
        }

        $version = $version ?: $this->resolveInstallableVersion($vendor, $name);
        if (! $version) {
            return ['success' => false, 'error' => __('admin.packages.error_module_not_found', ['module' => $package])];
        }

        $distUrl = $this->findRegistryVersion($package, $version)['dist']['url'] ?? null;
        if (! $distUrl) {
            return ['success' => false, 'error' => __('admin.packages.error_module_not_found', ['module' => $package])];
        }

        $zipPath = sys_get_temp_dir().'/module_dist_'.uniqid().'.zip';

        try {
            $this->downloadDist($distUrl, $zipPath);

            return $this->installModuleZip($zipPath, $version);
        } catch (RequestException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            if (File::exists($zipPath)) {
                File::delete($zipPath);
            }
        }
    }

    /**
     * Look up the full registry metadata (including dist.url) for one exact version.
     */
    protected function findRegistryVersion(string $package_name, string $version): ?array
    {
        try {
            $response = $this->http->get('p2/'.$package_name.'.json');
            $versions = json_decode((string) $response->getBody(), true)['packages'][$package_name] ?? [];
        } catch (RequestException $e) {
            return null;
        }

        foreach ($versions as $v) {
            if (($v['version'] ?? null) === $version) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Download a dist archive to a local path. Registry auth is only sent when
     * the dist URL is on the registry's own host, so the bearer token never
     * leaks to third-party hosts (e.g. a VCS-backed dist mirror).
     */
    protected function downloadDist(string $url, string $destination): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $registryHost = parse_url($this->registry_url, PHP_URL_HOST);

        $client = ($host && $host === $registryHost) ? $this->http : new Client(['timeout' => 60]);

        $client->get($url, ['sink' => $destination]);
    }

    /**
     * Extract, validate, and move a module zip archive into modules/{ModuleName}.
     * When $version is given it is written into the module's own composer.json,
     * which is the sole source of truth for installed version -- there is no
     * shared composer.lock to keep in sync across instances.
     *
     * @return array{success: bool, error?: string, module?: string}
     */
    protected function installModuleZip(string $zipPath, ?string $version = null): array
    {
        $tmpDir = sys_get_temp_dir().'/module_extract_'.uniqid();

        try {
            $zip = new ZipArchive;
            if ($zip->open($zipPath) !== true) {
                return ['success' => false, 'error' => __('admin.packages.error_zip_open')];
            }

            $zip->extractTo($tmpDir);
            $zip->close();

            // Determine the module root inside the zip (first top-level directory)
            $entries = File::directories($tmpDir);
            if (empty($entries)) {
                return ['success' => false, 'error' => __('admin.packages.error_zip_no_dir')];
            }

            $moduleRoot = $entries[0];

            // Validate required files
            $errors = $this->validateModuleDirectory($moduleRoot);
            if (! empty($errors)) {
                return ['success' => false, 'error' => implode(' ', $errors)];
            }

            // Destination must match module.json's "name" (the studly-cased name
            // nwidart/laravel-modules and the "Modules\\" => "modules/" PSR-4 rule
            // expect), not the zip's raw top-level folder name -- otherwise the
            // module's provider class can never be autoloaded.
            $moduleJson = json_decode(File::get("{$moduleRoot}/module.json"), true);
            $moduleDirName = $moduleJson['name'] ?? basename($moduleRoot);

            if ($version) {
                $composerJsonPath = "{$moduleRoot}/composer.json";
                $composerJson = json_decode(File::get($composerJsonPath), true) ?? [];
                $composerJson['version'] = $version;
                File::put($composerJsonPath, json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            // Move into the modules path (overwrite if exists)
            $destination = base_path("modules/{$moduleDirName}");
            if (File::isDirectory($destination)) {
                File::deleteDirectory($destination);
            }

            File::copyDirectory($moduleRoot, $destination);
            Artisan::call('optimize:clear');
            $this->reloadOctane();

            return ['success' => true, 'module' => $moduleDirName];
        } finally {
            if (File::isDirectory($tmpDir)) {
                File::deleteDirectory($tmpDir);
            }
        }
    }

    /**
     * Validate the unpacked module directory against nwidart/laravel-modules requirements.
     *
     * @return string[] List of validation error messages (empty = valid)
     */
    protected function validateModuleDirectory(string $path): array
    {
        $errors = [];

        // module.json — required, must be valid JSON with name/alias/providers
        $moduleJsonPath = "{$path}/module.json";
        if (! File::exists($moduleJsonPath)) {
            $errors[] = __('admin.packages.error_missing_module_json');
        } else {
            $moduleJson = json_decode(File::get($moduleJsonPath), true);
            if (! is_array($moduleJson)) {
                $errors[] = __('admin.packages.error_invalid_module_json');
            } else {
                foreach (['name', 'alias', 'providers'] as $field) {
                    if (empty($moduleJson[$field])) {
                        $errors[] = __('admin.packages.error_module_json_missing_field', ['field' => $field]);
                    }
                }
                if (! empty($moduleJson['providers']) && ! is_array($moduleJson['providers'])) {
                    $errors[] = __('admin.packages.error_module_json_providers_array');
                }
            }
        }

        // composer.json — required, must have a valid name field
        $composer_json_path = "{$path}/composer.json";
        if (! File::exists($composer_json_path)) {
            $errors[] = __('admin.packages.error_missing_composer_json');
        } else {
            $composer_json = json_decode(File::get($composer_json_path), true);
            if (! is_array($composer_json)) {
                $errors[] = __('admin.packages.error_invalid_composer_json');
            } elseif (empty($composer_json['name']) || ! str_contains($composer_json['name'], '/')) {
                $errors[] = __('admin.packages.error_composer_json_name');
            }
        }

        // Providers directory — must exist and contain at least one PHP file
        $providers_path = "{$path}/Providers";
        if (! File::isDirectory($providers_path) || empty(File::files($providers_path))) {
            $errors[] = __('admin.packages.error_missing_providers');
        }

        // Routes directory — should exist (web.php or api.php)
        $routes_path = "{$path}/Routes";
        if (! File::isDirectory($routes_path)) {
            $errors[] = __('admin.packages.error_missing_routes');
        }

        return $errors;
    }

    /**
     * Enable a module via artisan module:enable.
     */
    public function enable(string $moduleName): array
    {
        $module = $this->modules()->find($moduleName);
        if (! $module) {
            return ['success' => false, 'error' => __('admin.packages.error_module_not_found', ['module' => $moduleName])];
        }

        Artisan::call('module:enable', ['module' => $moduleName]);
        Artisan::call('optimize:clear');
        $this->reloadOctane();

        return ['success' => true];
    }

    /**
     * Disable a module via artisan module:disable.
     */
    public function disable(string $moduleName): array
    {
        $module = $this->modules()->find($moduleName);
        if (! $module) {
            return ['success' => false, 'error' => __('admin.packages.error_module_not_found', ['module' => $moduleName])];
        }

        Artisan::call('module:disable', ['module' => $moduleName]);
        Artisan::call('optimize:clear');
        $this->reloadOctane();

        return ['success' => true];
    }

    /**
     * Get full info for a package: registry metadata merged with local module state.
     */
    public function getPackageInfo(string $vendor, string $name): array
    {
        $registry_info = $this->getRegistryPackageInfo($vendor, $name);
        $moduleName = $this->packageToModuleName("{$vendor}/{$name}");
        $module = $moduleName ? $this->modules()->find($moduleName) : null;

        $installed = (bool) $module;
        $enabled = $module?->isEnabled() ?? false;
        $version = $module ? ($this->readModuleComposer($module)['version'] ?? null) : null;
        $path = $module?->getPath() ?? null;

        return [
            'name' => "{$vendor}/{$name}",
            'label' => $this->packageLabel($name),
            'vendor' => $vendor,
            'package' => $name,
            'description' => $registry_info['description'] ?? ($module?->getDescription() ?? ''),
            'versions' => $registry_info['versions'] ?? [],
            'latest' => $registry_info['latest'] ?? null,
            'type' => $registry_info['type'] ?? 'laravel-module',
            'keywords' => $registry_info['keywords'] ?? [],
            'homepage' => $registry_info['homepage'] ?? '',
            'authors' => $registry_info['authors'] ?? [],
            'require' => $registry_info['require'] ?? [],
            'license' => $registry_info['license'] ?? [],
            'installed' => $installed,
            'enabled' => $enabled,
            'version' => $version,
            'updateAvailable' => $this->isUpdateAvailable($version, $registry_info['latest'] ?? null),
            'isUnstable' => $registry_info['isUnstable'] ?? false,
            'path' => $path,
            'module_name' => $moduleName,
        ];
    }

    /**
     * Whether installing/showing unstable versions (beta, alpha, RC, dev) is currently allowed.
     */
    public function isAllowUnstable(): bool
    {
        return $this->allowUnstable;
    }

    /**
     * Persist the "allow unstable packages" preference.
     */
    public function setAllowUnstable(bool $allow): void
    {
        Settings::update('packages_allow_unstable', $allow ? '1' : null);
        $this->allowUnstable = $allow;
    }

    /**
     * Resolve the version that should be installed/upgraded to for a package,
     * honoring the "allow unstable" preference.
     */
    public function resolveInstallableVersion(string $vendor, string $name): ?string
    {
        $info = $this->getRegistryPackageInfo($vendor, $name);

        return ! empty($info['latest']) ? $info['latest'] : null;
    }

    // -------------------------------------------------------------------------

    // TEMPORARY: delete this method once repo is public and auth is handled by composer
    protected function resolveRegistryToken(): ?string
    {
        $auth_path = base_path('auth.json');
        if (! File::exists($auth_path)) {
            return null;
        }

        $auth = json_decode(File::get($auth_path), true);
        $domain = parse_url($this->registry_url, PHP_URL_HOST);

        return $auth['bearer'][$domain] ?? null;
    }

    protected function resolveRegistryUrl(): string
    {
        return config('services.plugin_registry.url') ?? '';
    }

    protected function packageLabel(string $name): string
    {
        $slug = str_contains($name, '/') ? explode('/', $name)[1] : $name;

        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    protected function resolveLatestVersion(array $versions): array
    {
        // Registry packages.json lists versions newest-first
        $candidates = $this->filterByStability($versions);

        return reset($candidates) ?: [];
    }

    /**
     * Drop unstable (beta/alpha/RC/dev) versions from the list unless allowed.
     */
    protected function filterByStability(array $versions): array
    {
        if ($this->allowUnstable) {
            return $versions;
        }

        return array_values(array_filter($versions, fn (array $v) => $this->isStable($v['version'] ?? '')));
    }

    protected function isStable(string $version): bool
    {
        return $this->versionStability($version) === 'stable';
    }

    /**
     * Mirrors composer's Composer\Package\Version\VersionParser::parseStability().
     */
    protected function versionStability(string $version): string
    {
        $version = preg_replace('{#.+$}i', '', $version) ?? $version;

        if (str_starts_with($version, 'dev-') || str_ends_with($version, '-dev')) {
            return 'dev';
        }

        preg_match('{-?(beta|alpha|rc|dev)(?:[.-]?\d+)?$}i', $version, $match);
        if (! empty($match[1])) {
            return match (strtolower($match[1])) {
                'beta' => 'beta',
                'alpha' => 'alpha',
                'rc' => 'RC',
                default => 'dev',
            };
        }

        return 'stable';
    }

    protected function isUpdateAvailable(?string $installed, ?string $latest): bool
    {
        if (! $installed || ! $latest) {
            return false;
        }

        return version_compare(ltrim($latest, 'v'), ltrim($installed, 'v'), '>');
    }

    protected function readModuleComposer($module): array
    {
        $path = $module->getPath().'/composer.json';

        return File::exists($path) ? json_decode(File::get($path), true) ?? [] : [];
    }

    /**
     * Resolve a "vendor/package" name to its installed module directory name.
     * The module's own module.json declares its real name (e.g. "ERPNextApp",
     * casing that a naive PascalCase-of-slug guess can't reproduce), so an
     * installed module must be matched by its actual composer.json "name"
     * rather than derived from the slug -- otherwise lookups silently miss
     * the real directory (e.g. "erpnext-app" guesses "ErpnextApp", not the
     * real "ERPNextApp") and callers like uninstall() no-op instead of failing.
     */
    public function packageToModuleName(string $package): ?string
    {
        $installed = collect($this->modules()->all())->first(
            fn ($module) => strcasecmp($this->readModuleComposer($module)['name'] ?? '', $package) === 0
        );

        if ($installed) {
            return $installed->getName();
        }

        // Not installed yet -- fall back to a best-effort guess (e.g. used to
        // predict the destination path before a module.json exists on disk).
        if (str_contains($package, '/')) {
            $slug = explode('/', $package)[1];

            return str_replace(['-', '_'], '', ucwords($slug, '-_'));
        }

        return null;
    }

    /**
     * Reload Octane workers so a module's ServiceProvider actually takes
     * effect on the already-running worker. A no-op outside Octane.
     *
     * Deferred via App::terminating() rather than called inline: FrankenPHP's
     * reload gracefully recycles the worker handling the *current* request,
     * so calling it before the response is sent deadlocks the request
     * against its own reload. Octane's Worker::handle() sends the response,
     * then calls terminate() -- by then this worker is no longer "in
     * flight" from FrankenPHP's point of view, so the reload can proceed.
     */
    protected function reloadOctane(): void
    {
        if (! config('octane.server')) {
            return;
        }

        App::terminating(fn () => Artisan::call('octane:reload'));
    }
}
