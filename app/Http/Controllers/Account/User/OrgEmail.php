<?php

namespace App\Http\Controllers\Account\User;

use App\Http\Controllers\Controller;
use App\Mailbox;
use App\OrgDomain;
use App\Rules\ConfirmOldPassword;
use App\Rules\EmailLocalPart;
use App\Rules\MailAddressAvailable;
use App\Rules\OrgDomainName;
use App\Services\Email\MailboxException;
use App\Services\Email\MailboxService;
use App\Services\Email\RecoveryEmailRequired;
use App\Support\Facades\AccountManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A user choosing to sign in with an organization address, from their own profile. Only the
 * user can accept one: what they sign in with decides which account every app finds them by.
 */
class OrgEmail extends Controller
{
    /**
     * The user picks a name and domain and accepts it in one go.
     */
    public function store(Request $request, MailboxService $mailboxes)
    {
        $user = AccountManager::users()->find(auth()->user()->username);
        $username = (string) $user->attribute('username');

        $validated = $request->validate([
            'domain' => ['required', 'integer', new OrgDomainName],
            'local_part' => ['required', 'string', new EmailLocalPart, new MailAddressAvailable],
            'recovery_email' => ['nullable', 'email:rfc,filter', 'max:255'],
            'current_password' => ['required', 'string', new ConfirmOldPassword(auth()->user()->email)],
            // `accepted` fails when the field is absent, so it is only asked for when the warning applies
            'acknowledge' => $this->sameAddress($request, $user) ? ['nullable'] : ['required', 'accepted'],
        ]);

        $domain = OrgDomain::findOrFail($validated['domain']);
        Gate::authorize('add-user-email-account-to-domain', [$username, $domain]);

        try {
            // Someone who has an offer (or an address already) accepts that, not a second one
            $mailbox = $mailboxes->offer($user, $domain, $validated['local_part'], $user->attribute('name'));
        } catch (MailboxException $e) {
            return redirect('/profile')->with('error', $e->getMessage());
        }

        // If it can't be accepted the offer made just now must not be left behind to block the next try
        return $this->accepting(
            fn () => $mailboxes->accept($user, $mailbox, $validated['recovery_email'] ?? null),
            fn () => $mailbox->delete(),
        );
    }

    /**
     * The user accepts the address an administrator offered them.
     */
    public function accept(Request $request, MailboxService $mailboxes)
    {
        $user = AccountManager::users()->find(auth()->user()->username);
        $username = (string) $user->attribute('username');
        $mailbox = $mailboxes->forUser($username);

        $validated = $request->validate([
            'recovery_email' => ['nullable', 'email:rfc,filter', 'max:255'],
            'current_password' => ['required', 'string', new ConfirmOldPassword(auth()->user()->email)],
            'acknowledge' => $mailbox && $mailboxes->plan($user, $mailbox)['changes_email'] ? ['required', 'accepted'] : ['nullable'],
        ]);

        if (! $mailbox || $mailbox->status !== Mailbox::PENDING) {
            return redirect('/profile')->with('error', __('organization.email.mailbox.not_pending'));
        }

        return $this->accepting(fn () => $mailboxes->accept($user, $mailbox, $validated['recovery_email'] ?? null));
    }

    public function undo(Request $request, MailboxService $mailboxes)
    {
        $request->validate(['current_password' => ['required', 'string', new ConfirmOldPassword(auth()->user()->email)]]);

        $user = AccountManager::users()->find(auth()->user()->username);
        $mailbox = $mailboxes->active((string) $user->attribute('username'));

        try {
            if (! $mailbox) {
                throw new MailboxException(__('organization.email.mailbox.undo_expired'));
            }

            $mailboxes->undo($user, $mailbox);
        } catch (MailboxException $e) {
            return redirect('/profile')->with('error', $e->getMessage());
        }

        return redirect('/profile')->with('success', __('organization.email.mailbox.undone', ['email' => $mailbox->previous_mail ?? '']));
    }

    private function accepting(\Closure $accept, ?\Closure $cleanup = null)
    {
        try {
            $mailbox = $accept();
        } catch (RecoveryEmailRequired $e) {
            $cleanup?->__invoke();

            throw ValidationException::withMessages(['recovery_email' => [$e->getMessage()]]);
        } catch (MailboxException $e) {
            $cleanup?->__invoke();

            return redirect('/profile')->with('error', $e->getMessage());
        }

        return redirect('/profile')->with('success', __('organization.email.mailbox.accepted', ['email' => $mailbox->address]));
    }

    /**
     * Whether the address asked for is the one the user signs in with already, which changes
     * nothing for their apps and needs no warning.
     */
    private function sameAddress(Request $request, $user): bool
    {
        $domain = OrgDomain::find($request->input('domain'));

        if (! $domain || ! is_string($request->input('local_part'))) {
            return false;
        }

        return Str::lower($request->input('local_part').'@'.$domain->name) === Str::lower((string) $user->attribute('email'));
    }
}
