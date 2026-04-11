<?php

namespace App\Http\Middleware;

use App\Support\Facades\FastCache;
use App\Support\Facades\Menu;
use App\Support\Facades\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Middleware;
use Tightenco\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     *
     * @return string|null
     */
    public function version(Request $request)
    {
        return parent::version($request);
    }

    public function menu()
    {
        $path_info = Arr::get($_SERVER, 'REQUEST_URI', null);

        if ($path_info && Str::of($path_info)->explode('/')[1] == 'admin' && Gate::allows('admin')) {
            $menu = FastCache::retrieve('admin_menu', function () {
                return Menu::build('admin');
            });
        } else {
            $menu = FastCache::retrieve('org_menu', function () {
                return Menu::build('organization');
            });
        }

        $items = collect($menu)->map(function ($item) {
            if (Arr::has($item, 'submenu')) {
                $item['submenu'] = collect(Arr::get($item, 'submenu', []))->filter(function ($item) {
                    return $item['perm'];
                })->sortBy(function ($item, $key) {
                    return $item['order'];
                })->values();
            }

            return $item;
        })->filter(function ($item) {
            return $item['perm'];
        })->sortBy(function ($item, $key) {
            return $item['order'];
        })->values();

        return $items;
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array
     */
    public function share(Request $request)
    {
        $headers = $request->header();
        // if (array_key_exists('content-type', $headers) && in_array($headers['content-type'])) {
        if (! in_array(true, Arr::get($headers, 'x-inertia', [])) && in_array('application/json', Arr::get($headers, 'content-type', []))) {
            return;
        }

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user()?->only(['first_name', 'last_name', 'username', 'id', 'name']),
                'can' => $request->user() ? [
                    'admin' => $request->user()->can('admin'),
                ]
                : [
                    'admin' => false,
                ],
                'organization' => [
                    'name' => $request->user()?->organization->name,
                ],
                'status' => $request->user()?->organization?->status,
            ],
            'step' => $request->user()?->organization->setting('step') ?? 0,
            /*'ziggy' => function () use ($request) {
                return array_merge((new Ziggy)->toArray(), [
                    'location' => $request->url(),
                ]);
            },*/
            'menu' => $request->user() ? $this->menu() : null,
            'notices' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
            'reset' => [
                'menu' => session('reset_menu') !== null,
                'step' => session('reset_step') !== null,
            ],
            'documentation' => $request->user() ? Settings::get('docs_url') : null,
            'theme' => [
                'primary_color' => Settings::get('primary_color', '#6042B3'),
                'secondary_color' => Settings::get('secondary_color', '#d91698'),
            ],
        ]);
    }
}
