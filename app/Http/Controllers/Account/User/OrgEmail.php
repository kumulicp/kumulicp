<?php

namespace App\Http\Controllers\Account\User;

use App\Http\Controllers\Controller;
use App\Mailbox;
use App\Rules\ConfirmOldPassword;
use App\Services\Email\MailboxException;
use App\Services\Email\MailboxService;
use App\Services\Email\RecoveryEmailRequired;
use App\Support\Facades\AccountManager;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A user accepting (or undoing) the organization address an administrator gave them. Only an
 * administrator can give one, and only the user can accept it: what they sign in with decides
 * which account every app finds them by.
 */
class OrgEmail extends Controller
{
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
}
