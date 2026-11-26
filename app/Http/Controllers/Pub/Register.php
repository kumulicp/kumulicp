<?php

namespace App\Http\Controllers\Pub;

use App\Events\Users\UserCreated as UserCreatedEvent;
use App\Http\Controllers\Controller;
use App\NewUserCode;
use App\Notifications\OrgRegistrationComplete;
use App\Notifications\OrgRegistrationVerification;
use App\Organization;
use App\OrgUserRegistration;
use App\Rules\UserNotExists;
use App\Services\Organization\UserAllowance;
use App\SuborgUser;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Support\Facades\Settings as SettingsFacade;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
            'fullMessage' => $this->fullMessage($organization),
            'captchaProvider' => SettingsFacade::get('captcha_provider'),
            'captchaSiteKey' => SettingsFacade::get('captcha_site_key'),
        ]);
    }

    public function submit(Request $request, Organization $organization)
    {
        $this->abortIfDisabled($organization);

        $key = 'org-register:'.$organization->slug.':'.$request->ip();
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

        OrganizationFacade::setOrganization($organization);

        // No point sending a link that cannot be used. The same answer whatever the address is
        if (! (new UserAllowance($organization))->allowed()) {
            return back()->withErrors(['email' => __('messages.org_registration.full')]);
        }

        // Check email isn't already registered in this org
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
            'fullMessage' => $this->fullMessage($organization),
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

        // The lock the administrator's add-user uses, so that two people registering at once, or one
        // registering while an administrator adds someone, cannot both take the last place
        $lock = Cache::lock('add-user-'.$organization->id, 30);
        $lock->block(10);

        try {
            if (! (new UserAllowance($organization))->allowed()) {
                return back()->withErrors(['limit' => __('messages.org_registration.full')]);
            }

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
            $user->addToDefaultUserGroups($organization->selfRegistrationApps());
            $user->permissions()->updateUserAccessType();

            $new_user_code = new NewUserCode;
            $new_user_code->organization()->associate($organization);
            $new_user_code->generate($user->attribute('username'));
            $new_user_code->status = 'pending';
            $new_user_code->expires_at = now()->addHours(NewUserCode::LIFETIME_HOURS);
            $new_user_code->save();

            if ($organization->parent_organization_id) {
                $suborg_user = new SuborgUser;
                $suborg_user->organization()->associate($organization);
                $suborg_user->username = $input['username'];
                $suborg_user->save();
            }

            $user->notify(new OrgRegistrationComplete($user, $new_user_code->code));
            $new_user_code->status = 'sent';
            $new_user_code->save();

            event(new UserCreatedEvent($user));

            $registration->delete();

            return redirect()->route('public.password.set', ['code' => $new_user_code->code]);
        } finally {
            $lock->release();
        }
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

    /**
     * Why registering would fail right now, so the pages can say so before someone fills them in, not
     * after. Null while there is room. Submitting still checks, since the last place can go in between.
     */
    private function fullMessage(Organization $organization): ?string
    {
        OrganizationFacade::setOrganization($organization);

        return (new UserAllowance($organization))->allowed() ? null : __('messages.org_registration.full');
    }

    private function abortIfDisabled(Organization $organization): void
    {
        if ($organization->status === 'deactivated' || ! $organization->hasSelfRegistrationEnabled()) {
            abort(404);
        }
    }
}
