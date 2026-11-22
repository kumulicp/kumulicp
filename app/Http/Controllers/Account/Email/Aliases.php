<?php

namespace App\Http\Controllers\Account\Email;

use App\Http\Controllers\Controller;
use App\Mailbox;
use App\OrgDomain;
use App\Rules\OrgDomainName;
use App\Services\Email\MailboxAliasService;
use App\Services\Email\MailboxException;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Adding and removing the aliases of a mailbox: a user's organization address, from the
 * administrator's page for that user, or a standalone mailbox, from the email accounts page.
 */
class Aliases extends Controller
{
    public function store(Request $request, Mailbox $mailbox, MailboxAliasService $aliases)
    {
        $this->authorizeFor($mailbox);

        $validated = $request->validate([
            'local_part' => ['required', 'string', 'max:64'],
            'domain' => ['required', 'integer', new OrgDomainName],
        ]);

        try {
            $alias = $aliases->add($mailbox, $validated['local_part'], OrgDomain::findOrFail($validated['domain']));
        } catch (MailboxException $e) {
            return redirect($this->returnUrl($mailbox))->with('error', $e->getMessage());
        }

        return redirect($this->returnUrl($mailbox))->with('success', __('organization.email.alias.added', ['email' => $alias->address]));
    }

    public function destroy(Mailbox $mailbox, string $address, MailboxAliasService $aliases)
    {
        $this->authorizeFor($mailbox);

        try {
            $aliases->remove($mailbox, $address);
        } catch (MailboxException $e) {
            return redirect($this->returnUrl($mailbox))->with('error', $e->getMessage());
        }

        return redirect($this->returnUrl($mailbox))->with('success', __('organization.email.alias.removed', ['email' => $address]));
    }

    /**
     * Only the organization's own mailboxes, by someone who may change that user or those accounts.
     */
    private function authorizeFor(Mailbox $mailbox): void
    {
        abort_unless((int) $mailbox->organization_id === (int) Organization::account()->id, 404);

        if ($mailbox->username !== null) {
            $user = AccountManager::users()->find($mailbox->username);
            abort_unless($user, 404);

            Gate::authorize('view-user', $user);
            Gate::authorize('edit-user', $user);

            return;
        }

        Gate::authorize('edit-email-settings');
    }

    private function returnUrl(Mailbox $mailbox): string
    {
        return $mailbox->username !== null ? '/users/'.$mailbox->username.'/edit' : '/settings/email/accounts';
    }
}
