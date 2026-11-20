<?php

namespace App\Http\Controllers;

use App\Rules\AccountEmailChecks;
use App\Rules\ConfirmOldPassword;
use App\Rules\EmailAddressExists;
use App\Rules\MainContact;
use App\Rules\RecoveryEmail;
use App\Mailbox;
use App\Services\Email\MailboxService;
use App\Support\Email\AppIdentity;
use App\Support\Email\HostedDomains;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use App\Services\Email\RecoveryEmailService;
use App\Support\Facades\AccountManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class Profile extends Controller
{
    public function index()
    {
        $profile = auth()->user();
        $recovery_emails = app(RecoveryEmailService::class);
        $mailbox = Mailbox::where('username', $profile->username)->where('status', Mailbox::ACTIVE)->first();

        return inertia('Organization/Profile/ProfileEdit', [
            'profile' => [
                'id' => $profile->username,
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'personal_email' => $profile->email,
                'phone_number' => $profile->phone_number,
                'locale' => $profile->locale,
                // With an organization address the email is that address, and only the recovery email is the person's to change
                'has_mailbox' => $mailbox !== null,
            ],
            'org_email' => $this->orgEmail($profile, $mailbox),
            'recovery' => [
                'email' => $recovery_emails->get($profile->username),
                'usable' => $recovery_emails->usable($profile->username, $profile->email) !== null,
                'pending' => $recovery_emails->pending($profile->username),
            ],
            'locales' => config('locales.available'),
        ]);
    }

    /**
     * What the profile needs to offer, show, accept or undo an organization address.
     *
     * @return array<string, mixed>
     */
    private function orgEmail($profile, ?Mailbox $active): array
    {
        $mailboxes = app(MailboxService::class);
        $user = AccountManager::users()->find($profile->username);
        $mailbox = $active ?? $mailboxes->forUser($profile->username);
        $domains = $mailbox ? collect() : $profile->organization->domains()->emailEnabled()->get();

        return [
            'mailbox' => $mailbox ? [
                'address' => $mailbox->address,
                'status' => $mailbox->status,
                'can_undo' => $mailbox->canBeUndone(),
                'previous_mail' => $mailbox->canBeUndone() ? $mailbox->previous_mail : null,
                'undo_until' => $mailbox->canBeUndone() ? $mailbox->swapped_at->copy()->addDays((int) config('email.undo_days', 7))->toDayDateTimeString() : null,
                // Whether accepting an offer changes the email, and what the person has to give
                'plan' => $mailbox->status === Mailbox::PENDING && $user ? $mailboxes->plan($user, $mailbox) : null,
            ] : null,
            'can_get' => ! $mailbox && $domains->isNotEmpty() && Gate::allows('add-user-email-account', $profile->username),
            'domains' => $domains->map(fn ($domain) => ['id' => $domain->id, 'name' => $domain->name])->values(),
            'default_name' => Str::lower($profile->username),
            // When the email now is on a domain hosted here it can't become the recovery email
            'current_hosted' => HostedDomains::isHosted($profile->email),
            'apps' => collect($user ? $user->allUserApps() : [])
                ->map(fn ($app) => ['name' => $app->label, 'identity' => AppIdentity::for($app)])
                ->values(),
        ];
    }

    public function update(Request $request)
    {
        $user = auth()->user();
        $organization = $user->organization;
        $has_mailbox = Mailbox::where('username', $user->username)->where('status', Mailbox::ACTIVE)->exists();

        /* Validate */
        $rules = [
            'first_name' => 'required|max:100',
            'last_name' => 'required|max:100',
            'phone_number' => [new MainContact($user, $organization)],
            'locale' => 'nullable|string|in:'.implode(',', array_keys(config('locales.available'))),
        ];

        // The email of someone with an organization address is that address: it isn't edited here
        if (! $has_mailbox) {
            $rules['personal_email'] = ['email:rfc,filter', 'required', new AccountEmailChecks, new EmailAddressExists($user->username)];
        }

        $validatedData = $request->validate($rules);

        if (! $has_mailbox) {
            $user->email = $request->personal_email;
        }
        $user->phone_number = $request->phone_number;
        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;
        $user->name = $request->first_name.' '.$request->last_name;
        $user->locale = $request->locale;
        $user->save();

        $validatedData['name'] = $validatedData['first_name'].' '.$validatedData['last_name'];
        unset($validatedData['locale']);

        if (AccountManager::driver() != 'direct') {
            AccountManager::users()->find($user->username)->update($validatedData);
        }

        return redirect('/profile')->with('success', __('profile.updated'));
    }

    /**
     * Starts a change of the recovery email: a link is sent to the new address, and nothing
     * changes until it is opened. Asks for the password, since this decides where a reset goes.
     */
    public function requestRecoveryEmail(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'recovery_email' => ['required', 'email:rfc,filter', 'max:255', new RecoveryEmail($user->email)],
            'current_password' => ['required', 'string', new ConfirmOldPassword($user->email, $user->username)],
        ]);

        app(RecoveryEmailService::class)->request($user->username, $validated['recovery_email'], $user->email);

        return redirect('/profile')->with('success', __('profile.recovery.requested', ['email' => $validated['recovery_email']]));
    }

    public function confirmRecoveryEmail(string $hash)
    {
        $user = auth()->user();

        $confirmed = app(RecoveryEmailService::class)->confirm($user->username, $hash, $user->email);

        return redirect('/profile')->with(
            $confirmed ? 'success' : 'error',
            __($confirmed ? 'profile.recovery.confirmed' : 'profile.recovery.invalid'),
        );
    }

    public function updatePassword(Request $request)
    {
        $user = auth()->user();

        /* Validate */
        $validatedData = $request->validate([
            'current_password' => ['required', 'string', 'min:8', new ConfirmOldPassword($user->email, $user->username)],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised()],
        ]);

        // Validate the input and return correct response
        $user = AccountManager::users()->find($user->username);
        $user->setPassword($validatedData['password']);
        $user->save();

        return redirect('/profile')->with('success', __('profile.password.updated'));

    }
}
