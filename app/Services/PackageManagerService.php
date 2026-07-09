<?php

namespace App\Services;

use App\Support\Facades\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Nwidart\Modules\Facades\Module;
use Symfony\Component\Process\Process;
use ZipArchive;

class PackageManagerService
{
    protected string $registry_url;

    protected Client $http;

    /** @var array<string,string>|null */
    protected ?array $installedVersions = null;

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
        return collect(Module::all())->map(function ($module) {
            $composer_json = $this->readModuleComposer($module);

            $composer_name = $composer_json['name'] ?? '';

            return [
                'name' => $module->getName(),
                'composer_name' => $composer_name,
                'description' => $composer_json['description'] ?? $module->getDescription(),
                'version' => $this->installedComposerVersions()[$composer_name] ?? null,
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
     * Install a package via composer require.
     */
    public function install(string $package, ?string $version = null): array
    {
        $spec = $version ? "{$package}:{$version}" : $package;
        $process = new Process(
            ['composer', 'require', $spec, '--no-interaction', '--no-ansi'],
            base_path(),
            $this->composerEnv(),
            null,
            300
        );
        $process->run();

        if ($process->isSuccessful()) {
            Artisan::call('optimize:clear');
        }

        return [
            'success' => $process->isSuccessful(),
            'output' => trim($process->getOutput()),
            'error' => trim($process->getErrorOutput()),
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
            $this->composerEnv(),
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
            'output' => trim($process->getOutput()),
            'error' => trim($process->getErrorOutput()),
        ];
    }

    /**
     * Upgrade a package to its latest version via composer require.
     */
    public function upgrade(string $package, ?string $version = null): array
    {
        $spec = $version ? "{$package}:{$version}" : $package;
        $process = new Process(
            ['composer', 'require', $spec, '--no-interaction', '--no-ansi'],
            base_path(),
            $this->composerEnv(),
            null,
            300
        );
        $process->run();

        if ($process->isSuccessful()) {
            $this->installedVersions = null; // bust the cache
            Artisan::call('optimize:clear');
        }

        return [
            'success' => $process->isSuccessful(),
            'output' => trim($process->getOutput()),
            'error' => trim($process->getErrorOutput()),
        ];
    }

    /**
     * Install a module from an uploaded zip file.
     *
     * @return array{success: bool, error?: string, module?: string}
     */
    public function installFromZip(UploadedFile $file): array
    {
        $tmpDir = sys_get_temp_dir().'/module_upload_'.uniqid();

        try {
            $zip = new ZipArchive;
            if ($zip->open($file->getRealPath()) !== true) {
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
            $moduleDirName = basename($moduleRoot);

            // Validate required files
            $errors = $this->validateModuleDirectory($moduleRoot);
            if (! empty($errors)) {
                return ['success' => false, 'error' => implode(' ', $errors)];
            }

            // Move into the modules path (overwrite if exists)
            $destination = base_path("modules/{$moduleDirName}");
            if (File::isDirectory($destination)) {
                File::deleteDirectory($destination);
            }

            File::copyDirectory($moduleRoot, $destination);
            Artisan::call('optimize:clear');

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
        $module = Module::find($moduleName);
        if (! $module) {
            return ['success' => false, 'error' => __('admin.packages.error_module_not_found', ['module' => $moduleName])];
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
            return ['success' => false, 'error' => __('admin.packages.error_module_not_found', ['module' => $moduleName])];
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
        $registry_info = $this->getRegistryPackageInfo($vendor, $name);
        $moduleName = ucfirst($name);
        $module = Module::find($moduleName);

        $installed = (bool) $module;
        $enabled = $module?->isEnabled() ?? false;
        $version = $this->installedComposerVersions()["{$vendor}/{$name}"] ?? null;
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
        $composer = json_decode(File::get(base_path('composer.json')), true);

        foreach ($composer['repositories'] ?? [] as $repo) {
            if (($repo['name'] ?? '') === 'kumulicp') {
                return $repo['url'] ?? '';
            }
        }

        return '';
    }

    protected function composerEnv(): array
    {
        $env = getenv();
        if (empty($env['HOME']) && empty($env['COMPOSER_HOME'])) {
            $env['HOME'] = posix_getpwuid(posix_getuid())['dir'] ?? '/root';
        }

        return $env;
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

    protected function installedComposerVersions(): array
    {
        if ($this->installedVersions === null) {
            $path = base_path('vendor/composer/installed.json');
            $data = File::exists($path) ? json_decode(File::get($path), true) ?? [] : [];
            $packages = $data['packages'] ?? $data; // Composer 2 nests under 'packages'; v1 is a flat array
            $this->installedVersions = collect($packages)->keyBy('name')->map(fn ($p) => $p['version'])->all();
        }

        return $this->installedVersions;
    }

    protected function readModuleComposer($module): array
    {
        $path = $module->getPath().'/composer.json';

        return File::exists($path) ? json_decode(File::get($path), true) ?? [] : [];
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
