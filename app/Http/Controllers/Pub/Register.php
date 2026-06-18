<?php

namespace App\Http\Controllers\Pub;

use App\Events\Users\UserCreated as UserCreatedEvent;
use App\Http\Controllers\Controller;
use App\NewUserCode;
use App\Notifications\OrgRegistrationVerification;
use App\Notifications\UserCreated;
use App\Organization;
use App\OrgUserRegistration;
use App\Rules\EmailAddressExists;
use App\Rules\UserNotExists;
use App\SuborgUser;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Support\Facades\Settings as SettingsFacade;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Str;

class Register extends Controller
{
    public function show(Organization $organization)
    {
        $this->abortIfDisabled($organization);

        return inertia('Auth/OrgRegister', [
            'organization' => [
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'captchaProvider' => SettingsFacade::get('captcha_provider'),
            'captchaSiteKey' => SettingsFacade::get('captcha_site_key'),
        ]);
    }

    public function submit(Request $request, Organization $organization)
    {
        $this->abortIfDisabled($organization);

        $key = 'org-register:' . $organization->slug . ':' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => __('messages.org_registration.too_many_attempts', ['seconds' => $seconds]),
            ]);
        }
        RateLimiter::hit($key, 600);

        if (SettingsFacade::get('captcha_provider')) {
            if (! $this->verifyCaptcha($request->input('captcha_token'))) {
                throw ValidationException::withMessages([
                    'email' => __('messages.org_registration.captcha_failed'),
                ]);
            }
        }

        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = $request->input('email');

        // Check email isn't already registered in this org
        OrganizationFacade::setOrganization($organization);
        if (AccountManager::checkEmail($email)) {
            return back()->withErrors(['email' => __('messages.rule.email_address_exists')]);
        }

        $registration = OrgUserRegistration::generate($organization, $email);

        (new AnonymousNotifiable)
            ->route('mail', $email)
            ->notify(new OrgRegistrationVerification($organization, $registration));

        return redirect()->route('public.org.register.pending', ['organization' => $organization->slug]);
    }

    public function pending(Organization $organization)
    {
        $this->abortIfDisabled($organization);

        return inertia('Auth/OrgRegisterPending', [
            'organization' => [
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
        ]);
    }

    public function verify(Organization $organization, string $token)
    {
        $this->abortIfDisabled($organization);

        $registration = OrgUserRegistration::where('organization_id', $organization->id)
            ->where('token', $token)
            ->first();

        if (! $registration || $registration->isExpired()) {
            return redirect()
                ->route('public.org.register', ['organization' => $organization->slug])
                ->withErrors(['email' => __('messages.org_registration.token_invalid')]);
        }

        return inertia('Auth/OrgRegisterComplete', [
            'organization' => [
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'token' => $token,
            'email' => $registration->email,
        ]);
    }

    public function complete(Request $request, Organization $organization, string $token)
    {
        $this->abortIfDisabled($organization);

        $registration = OrgUserRegistration::where('organization_id', $organization->id)
            ->where('token', $token)
            ->first();

        if (! $registration || $registration->isExpired()) {
            return redirect()
                ->route('public.org.register', ['organization' => $organization->slug])
                ->withErrors(['email' => __('messages.org_registration.token_invalid')]);
        }

        OrganizationFacade::setOrganization($organization);

        $request->validate([
            'username' => ['required', 'alpha_num', 'lowercase', 'max:100', new UserNotExists],
            'first_name' => ['required', 'max:100'],
            'last_name' => ['required', 'max:100'],
            'phone_number' => ['nullable', 'max:30'],
        ]);

        $input = [
            'username' => $request->input('username'),
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'name' => $request->input('first_name').' '.$request->input('last_name'),
            'email' => $registration->email,
            'password' => Str::password(20, true, true, false, false),
            'phone_number' => $request->input('phone_number'),
        ];

        $user = AccountManager::users($organization)->add($input);
        $user->addToDefaultUserGroups();
        $user->permissions()->updateUserAccessType();

        $new_user_code = new NewUserCode;
        $new_user_code->organization()->associate($organization);
        $new_user_code->generate($user->attribute('username'));
        $new_user_code->status = 'pending';
        $new_user_code->save();

        if ($organization->parent_organization_id) {
            $suborg_user = new SuborgUser;
            $suborg_user->organization()->associate($organization);
            $suborg_user->username = $input['username'];
            $suborg_user->save();
        }

        // Send UserCreated notification with the code (mirrors permissions controller flow)
        $user->notify(new UserCreated($user, $new_user_code->code));
        $new_user_code->status = 'sent';
        $new_user_code->save();

        event(new UserCreatedEvent($user));

        $registration->delete();

        return redirect()->route('public.password.set', ['code' => $new_user_code->code]);
    }

    private function verifyCaptcha(?string $token): bool
    {
        if (! $token) {
            return false;
        }

        $provider = SettingsFacade::get('captcha_provider');
        $url = $provider === 'turnstile'
            ? 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            : 'https://hcaptcha.com/siteverify';

        $response = Http::asForm()->post($url, [
            'secret' => SettingsFacade::get('captcha_secret_key'),
            'response' => $token,
        ]);

        return $response->json('success') === true;
    }

    private function abortIfDisabled(Organization $organization): void
    {
        if (! SettingsFacade::get('registration_enabled') || ! $organization->setting('self_registration_enabled')) {
            abort(404);
        }
    }
}
