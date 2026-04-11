<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\SsoProvider;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SsoProviders extends Controller
{
    public function index()
    {
        return inertia('Admin/Settings/SsoProviders/SsoProviderList', [
            'providers' => SsoProvider::all()->map(function ($provider) {
                return [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'label' => $provider->label,
                    'client_id' => $provider->client_id,
                    'client_secret' => $provider->client_secret,
                    'base_url' => $provider->base_url,
                    'redirect_url' => $provider->redirect_url,
                    'scopes' => $provider->scopes,
                    'enabled' => $provider->enabled,
                ];
            }),
            'breadcrumbs' => [
                [
                    'label' => 'Settings',
                    'url' => '/admin/settings',
                ],
                [
                    'label' => 'SSO Providers',
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'alpha_dash', 'unique:sso_providers,name'],
            'label' => ['required', 'string'],
        ]);

        $provider = new SsoProvider;
        $provider->name = $validated['name'];
        $provider->label = $validated['label'];
        $provider->enabled = false;
        $provider->scopes = 'openid email profile';
        $provider->save();

        return redirect('/admin/settings/sso-providers/'.$provider->id)->with('success', 'Provider added');
    }

    public function show(SsoProvider $provider)
    {
        return inertia('Admin/Settings/SsoProviders/SsoProviderEdit', [
            'provider' => [
                'id' => $provider->id,
                'name' => $provider->name,
                'label' => $provider->label,
                'client_id' => $provider->client_id,
                'client_secret' => $provider->client_secret,
                'base_url' => $provider->base_url,
                'redirect_url' => $provider->redirect_url,
                'scopes' => $provider->scopes,
                'enabled' => $provider->enabled,
            ],
            'breadcrumbs' => [
                [
                    'label' => 'Settings',
                    'url' => '/admin/settings',
                ],
                [
                    'label' => 'Providers',
                    'url' => '/admin/settings/sso-providers',
                ],
                [
                    'label' => $provider->name,
                ],
            ],
        ]);
    }

    public function update(Request $request, SsoProvider $provider)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'alpha_dash',
                Rule::unique('sso_providers')->ignore($provider->id),
            ],
            'label' => ['required', 'string'],
            'driver' => ['sometimes', 'string'],
            'client_id' => ['nullable', 'required_if:enabled,true', 'string'],
            'client_secret' => ['nullable', 'required_if:enabled,true', 'string'],
            'redirect_url' => ['nullable', 'required_if:enabled,true', 'url'],
            'base_url' => ['nullable', 'required_if:enabled,true', 'url'],
            'scopes' => ['nullable', 'required_if:enabled,true'],
            'enabled' => ['boolean'],
        ]);

        $provider->name = $validated['name'];
        $provider->label = $validated['label'];
        $provider->driver = 'oidc';
        $provider->client_id = $validated['client_id'];
        $provider->client_secret = $validated['client_secret'];
        $provider->redirect_url = $validated['redirect_url'];
        $provider->base_url = $validated['base_url'];
        $provider->enabled = $validated['enabled'];
        $provider->scopes = $validated['scopes'];
        $provider->save();

        return redirect('/admin/server/settings/sso-providers/'.$provider->id)->with('success', 'Provider updated');
    }

    public function destroy(SsoProvider $ssoProvider)
    {
        $ssoProvider->delete();

        return response()->json(['message' => 'Provider deleted']);
    }
}
