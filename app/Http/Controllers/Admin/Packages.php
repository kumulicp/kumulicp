<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PackageManagerService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class Packages extends Controller
{
    public function __construct(protected PackageManagerService $manager) {}

    /**
     * Show the full package list (registry + installed modules).
     */
    public function index()
    {
        $packages = $this->manager->getPackageList();

        return inertia()->render('Admin/Packages/PackagesList', [
            'packages' => $packages,
            'breadcrumbs' => [['label' => __('labels.package_manager')]],
        ]);
    }

    /**
     * Show detailed info for one package.
     */
    public function show(string $vendor, string $package)
    {
        $info = $this->manager->getPackageInfo($vendor, $package);

        return inertia()->render('Admin/Packages/PackageInfo', [
            'package' => $info,
            'breadcrumbs' => [
                [
                    'label' => __('labels.package_manager'),
                    'url' => '/admin/packages',
                ],
                [
                    'label' => Arr::get($info, 'label'),
                ],
            ],
        ]);
    }

    /**
     * Download / install a package from the registry.
     */
    public function download(Request $request)
    {
        $request->validate([
            'package' => 'required|string|regex:/^[a-z0-9_\-]+\/[a-z0-9_\-]+$/i',
            'version' => 'nullable|string|max:64',
        ]);

        $result = $this->manager->install(
            $request->input('package'),
            $request->input('version')
        );

        if ($result['success']) {
            return redirect()->back()->with('success', __('admin.packages.installed', ['package' => $request->package]));
        }

        return redirect()->back()->with('error', $result['error'] ?: $result['output']);
    }

    /**
     * Upload and install a module from a zip file.
     */
    public function uploadModule(Request $request)
    {
        $request->validate([
            'module' => 'required|file|mimes:zip|max:51200',
        ]);

        $result = $this->manager->installFromZip($request->file('module'));

        if ($result['success']) {
            return redirect()->back()->with('success', __('admin.packages.module_installed', ['module' => $result['module']]));
        }

        return redirect()->back()->withErrors(['module' => $result['error']]);
    }

    /**
     * Install the latest version of a package from the registry.
     */
    public function install(string $vendor, string $package)
    {
        $result = $this->manager->install("{$vendor}/{$package}");

        if ($result['success']) {
            return redirect()->back()->with('success', __('admin.packages.installed', ['package' => "{$vendor}/{$package}"]));
        }

        return redirect()->back()->with('error', $result['error'] ?: $result['output']);
    }

    /**
     * Remove an installed package.
     */
    public function destroy(string $vendor, string $package)
    {
        $result = $this->manager->uninstall("{$vendor}/{$package}");

        if ($result['success']) {
            return redirect('/admin/packages')->with('success', __('admin.packages.removed', ['package' => "{$vendor}/{$package}"]));
        }

        return redirect()->back()->with('error', $result['error'] ?: $result['output']);
    }

    /**
     * Upgrade a package to its latest registry version.
     */
    public function upgrade(string $vendor, string $package)
    {
        $result = $this->manager->upgrade("{$vendor}/{$package}");

        if ($result['success']) {
            return redirect()->back()->with('success', __('admin.packages.upgraded', ['package' => "{$vendor}/{$package}"]));
        }

        return redirect()->back()->with('error', $result['error'] ?: $result['output']);
    }

    /**
     * Enable a module.
     */
    public function enable(string $vendor, string $package)
    {
        $moduleName = $this->moduleNameFrom($package);
        $result = $this->manager->enable($moduleName);

        if ($result['success']) {
            return redirect()->back()->with('success', __('admin.packages.enabled', ['module' => $moduleName]));
        }

        return redirect()->back()->with('error', $result['error']);
    }

    /**
     * Disable a module.
     */
    public function disable(string $vendor, string $package)
    {
        $moduleName = $this->moduleNameFrom($package);
        $result = $this->manager->disable($moduleName);

        if ($result['success']) {
            return redirect()->back()->with('success', __('admin.packages.disabled', ['module' => $moduleName]));
        }

        return redirect()->back()->with('error', $result['error']);
    }

    // -------------------------------------------------------------------------

    protected function moduleNameFrom(string $packageSlug): string
    {
        return str_replace(['-', '_'], '', ucwords($packageSlug, '-_'));
    }
}
