<?php

namespace App\Http\Controllers\Admin\Applications\Versions;

use App\Application;
use App\AppVersion;
use App\AppVersionImage;
use App\Http\Controllers\Controller;
use App\Support\VersionUpdates\ChartImageDiscovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The images of a version, and finding them in the version's chart.
 */
class Images extends Controller
{
    public function __construct(private ChartImageDiscovery $discovery) {}

    public function index(Application $app, AppVersion $version)
    {
        return $this->page($app, $version);
    }

    /**
     * The same page, with what the chart the version uses runs.
     */
    public function discover(Application $app, AppVersion $version)
    {
        try {
            return $this->page($app, $version, $this->discovery->discover($version));
        } catch (RuntimeException $exception) {
            return $this->page($app, $version, null, $exception->getMessage());
        }
    }

    public function store(Request $request, Application $app, AppVersion $version): RedirectResponse
    {
        $validated = $request->validate([
            'images' => 'required|array|min:1',
            'images.*.key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'images.*.label' => 'nullable|string|max:255',
            'images.*.values_path' => 'nullable|string|max:255',
            'images.*.registry' => 'nullable|string|max:255',
            'images.*.repository' => 'required|string|max:255',
            'images.*.tag' => 'nullable|string|max:255',
            'images.*.digest' => 'nullable|string|max:255',
            'images.*.source' => 'required|in:'.AppVersionImage::SOURCE_OVERRIDE.','.AppVersionImage::SOURCE_CHART,
        ]);

        $this->ensureAddable($version, $validated['images']);

        $added = $this->discovery->adopt($version, $validated['images']);

        return redirect($this->url($app, $version))->with('success', __('admin.applications.versions.images_added', ['count' => $added->count()]));
    }

    public function destroy(Application $app, AppVersion $version, AppVersionImage $image): RedirectResponse
    {
        abort_unless($image->app_version_id === $version->id, 404);

        // The image the version's name and settings describe is removed by removing them
        if ($image->tag_from_version_name) {
            return redirect($this->url($app, $version))->with('error', __('admin.applications.versions.image_follows_settings'));
        }

        $image->delete();

        return redirect($this->url($app, $version))->with('success', __('admin.applications.versions.image_removed', ['image' => $image->label ?: $image->key]));
    }

    /**
     * An image Kumulicp writes into the chart's values has to say where and with
     * what; and a key can only be there once, in the version and in what was sent.
     *
     * @param  array<int, array<string, mixed>>  $images
     *
     * @throws ValidationException
     */
    private function ensureAddable(AppVersion $version, array $images): void
    {
        $errors = [];
        $taken = $version->images()->pluck('key')->all();

        foreach ($images as $index => $image) {
            if (in_array($image['key'], $taken, true)) {
                $errors["images.$index.key"] = __('admin.applications.versions.image_key_taken', ['key' => $image['key']]);
            }

            $taken[] = $image['key'];

            if ($image['source'] === AppVersionImage::SOURCE_OVERRIDE && (empty($image['values_path']) || (empty($image['tag']) && empty($image['digest'])))) {
                $errors["images.$index.source"] = __('admin.applications.versions.image_override_incomplete', ['key' => $image['key']]);
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $discovered
     */
    private function page(Application $app, AppVersion $version, ?array $discovered = null, ?string $discover_error = null)
    {
        return inertia()->render('Admin/Applications/Versions/VersionImages', [
            'app' => ['id' => $app->id, 'name' => $app->name, 'slug' => $app->slug],
            'version' => [
                'id' => $version->id,
                'version' => $version->name,
                'chart' => $version->setting('chart_name') ? $version->setting('chart_name').' '.$version->setting('chart_version') : null,
            ],
            'images' => $version->images()->with('registrySecret')->get()->map(fn (AppVersionImage $image) => [
                'id' => $image->id,
                'key' => $image->key,
                'label' => $image->label ?: $image->key,
                'role' => $image->role,
                'source' => $image->source,
                'reference' => $this->reference($image, $version),
                'values_path' => $image->values_path,
                'update_policy' => $image->update_policy,
                'follows_settings' => $image->tag_from_version_name,
            ])->values(),
            'discovered' => $discovered,
            'discover_error' => $discover_error,
            'breadcrumbs' => [
                ['url' => '/admin/apps', 'label' => __('admin.applications.apps')],
                ['label' => $app->name, 'url' => '/admin/apps/'.$app->slug],
                ['url' => '/admin/apps/'.$app->slug.'/versions', 'label' => __('admin.applications.versions.versions')],
                ['url' => '/admin/apps/'.$app->slug.'/versions/'.$version->name, 'label' => $version->name],
                ['label' => __('admin.applications.versions.images')],
            ],
        ]);
    }

    private function reference(AppVersionImage $image, AppVersion $version): string
    {
        // The version's name is this image's tag, whichever way it was loaded
        $image->setRelation('version', $version);

        return $image->reference();
    }

    private function url(Application $app, AppVersion $version): string
    {
        return '/admin/apps/'.$app->slug.'/versions/'.$version->name.'/images';
    }
}
