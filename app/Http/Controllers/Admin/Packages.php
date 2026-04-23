<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PackageManagerService;
use Illuminate\Http\Request;

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
            'packages'    => $packages,
            'breadcrumbs' => [['label' => 'Package Manager']],
        ]);
    }

    /**
     * Show detailed info for one package.
     */
    public function show(string $vendor, string $package)
    {
        $info = $this->manager->getPackageInfo($vendor, $package);

        return inertia()->render('Admin/Packages/PackageInfo', [
            'package'     => $info,
            'breadcrumbs' => [
                ['label' => 'Package Manager', 'href' => '/admin/packages'],
                ['label' => "{$vendor}/{$package}"],
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
            return redirect()->back()->with('success', "Package '{$request->package}' installed successfully.");
        }

        return redirect()->back()->with('error', [
            'title'   => 'Installation failed',
            'message' => $result['error'] ?: $result['output'],
        ]);
    }

    /**
     * Remove an installed package.
     */
    public function destroy(string $vendor, string $package)
    {
        $result = $this->manager->uninstall("{$vendor}/{$package}");

        if ($result['success']) {
            return redirect('/admin/packages')->with('success', "Package '{$vendor}/{$package}' removed successfully.");
        }

        return redirect()->back()->with('error', [
            'title'   => 'Removal failed',
            'message' => $result['error'] ?: $result['output'],
        ]);
    }

    /**
     * Enable a module.
     */
    public function enable(string $vendor, string $package)
    {
        $moduleName = $this->moduleNameFrom($package);
        $result     = $this->manager->enable($moduleName);

        if ($result['success']) {
            return redirect()->back()->with('success', "Module '{$moduleName}' enabled.");
        }

        return redirect()->back()->with('error', [
            'title'   => 'Enable failed',
            'message' => $result['error'],
        ]);
    }

    /**
     * Disable a module.
     */
    public function disable(string $vendor, string $package)
    {
        $moduleName = $this->moduleNameFrom($package);
        $result     = $this->manager->disable($moduleName);

        if ($result['success']) {
            return redirect()->back()->with('success', "Module '{$moduleName}' disabled.");
        }

        return redirect()->back()->with('error', [
            'title'   => 'Disable failed',
            'message' => $result['error'],
        ]);
    }

    // -------------------------------------------------------------------------

    protected function moduleNameFrom(string $packageSlug): string
    {
        return str_replace(['-', '_'], '', ucwords($packageSlug, '-_'));
    }
}
