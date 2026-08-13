<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Ldap\Models\User as LdapUser;
use App\SsoProvider;
use App\Support\Facades\Settings;
use App\User;
use App\UserSsoAccount;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use LdapRecord\Laravel\Import\Importer;
use LdapRecord\Query\Collection;

class SsoController extends Controller
{
    public function redirect(string $providerSlug)
    {
        $provider = SsoProvider::where('name', $providerSlug)
            ->where('enabled', true)
            ->firstOrFail();

        $this->setDynamicConfig($provider);

        $socialite_driver = Socialite::driver($provider->driver);

        if ($socialite_driver instanceof \Laravel\Socialite\Two\AbstractProvider) {
            $socialite_driver->scopes($provider->scopes ? explode(' ', $provider->scopes) : []);
        }

        return $socialite_driver->redirect();
    }

    public function callback(string $providerSlug, Request $request)
    {
        $provider = SsoProvider::where('name', $providerSlug)
            ->where('enabled', true)
            ->firstOrFail();

        $this->setDynamicConfig($provider);

        $social_user = Socialite::driver($provider->driver)->user();

        $web_provider = config('auth.guards.web.provider');
        $provider_driver = config("auth.providers.$web_provider.driver");

        $user = null;

        if ($provider_driver === 'ldap') {
            // Find the user in LDAP
            $ldap_user = LdapUser::where(Settings::get('ldap_personal_email', 'mail'), '=', $social_user->getEmail())->first();
            $sync_attributes = config("auth.providers.$web_provider.database.sync_attributes");

            if ($ldap_user instanceof LdapUser && $ldap_user->hasControlPanelAccess()) {
                // Import the user
                (new Importer)
                    ->setLdapObjects(Collection::make([$ldap_user]))
                    ->setEloquentModel(User::class)
                    ->setSyncAttributes($sync_attributes)
                    ->execute();

                $user = User::where('email', $social_user->getEmail())->first();
                $user->is_allowed = true;
                $user->email_verified_at = now();
                $user->save();
            } else {
                throw ValidationException::withMessages([
                    'email' => [__('auth.sso_failed')],
                ])->redirectTo('/login');
            }
        } elseif ($provider_driver === 'eloquent') {
            $user = User::where('email', $social_user->getEmail())->first();

            if (! $user->is_allowed) {
                throw ValidationException::withMessages([
                    'email' => [__('auth.sso_failed')],
                ])->redirectTo('/login');
            }
        }

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => [__('auth.sso_failed')],
            ])->redirectTo('/login');
        }

        $account = UserSsoAccount::where('sso_provider_id', $provider->id)
            ->where('provider_user_id', $social_user->getId())
            ->first();

        if ($account && ! $user->is($account->user)) {
            $account->delete();
            $account = null;
        }

        if (! $account) {
            $account = UserSsoAccount::create([
                'user_id' => $user->id,
                'sso_provider_id' => $provider->id,
                'provider_user_id' => $social_user->getId(),
                'email' => $social_user->getEmail(),
            ]);
        }

        // Update tokens
        if ($social_user instanceof \Laravel\Socialite\Two\User) {
            $account->update([
                'access_token' => $social_user->token,
                'refresh_token' => $social_user->refreshToken,
                'token_expires_at' => $social_user->expiresIn
                ? Carbon::now()->addSeconds($social_user->expiresIn)
                : null,
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    protected function setDynamicConfig(SsoProvider $provider): void
    {
        Config::set("services.{$provider->driver}", [
            'client_id' => $provider->client_id,
            'client_secret' => $provider->client_secret,
            'redirect' => $provider->redirect_url,
            'base_url' => $provider->base_url,
        ]);
    }
}
