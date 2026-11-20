<?php

namespace App\Http\Controllers\Account\Email;

use App\Http\Controllers\Controller;
use App\Mailbox;
use App\Ldap\Models\Email;
use App\OrgDomain;
use App\Rules\EmailLocalPart;
use App\Rules\MailAddressAvailable;
use App\Rules\OrgDomainName;
use App\Support\Email\LdapEmailAttributes;
use App\Support\Facades\Domain;
use App\Support\Facades\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class Accounts extends Controller
{
    public function index()
    {
        Gate::authorize('view-emails');

        $organization = Organization::account();
        $domains = $organization->domains()->emailEnabled()->primary()->get();
        $default_domain = $domains->filter(function ($domain, int $key) {
            return $domain->is_primary;
        })->first();

        $page = isset($_GET['page']) ? $_GET['page'] : 1;

        $emails = [];
        foreach ($domains as $domain) {
            $emails = array_merge($emails, Domain::connect($domain, 'email')->emailList($domain));
        }

        // The panel's record of each standalone mailbox, for its aliases
        $mailboxes = Mailbox::where('organization_id', $organization->id)->whereNull('username')
            ->whereIn('address', array_map(fn ($email) => strtolower($email['email'] ?? ''), $emails))
            ->with('addresses')->get()->keyBy('address');
        $emails = array_map(function ($email) use ($mailboxes) {
            $mailbox = $mailboxes->get(strtolower($email['email'] ?? ''));

            return $email + [
                'mailbox_id' => $mailbox?->id,
                'aliases' => $mailbox ? $mailbox->addresses->where('is_primary', false)->pluck('address')->values()->all() : [],
            ];
        }, $emails);

        return inertia('Organization/Settings/EmailAccounts/EmailAccountsList', [
            'accounts' => $emails,
            'aliases_enabled' => LdapEmailAttributes::aliasesEnabled(),
            'can' => [
                'add_email_accounts' => Gate::allows('add-email-accounts'),
            ],
            'domains' => $domains->map(function ($domain) {
                return [
                    'text' => $domain->name,
                    'value' => $domain->id,
                ];
            }),
            'alias_domains' => $organization->domains()->emailEnabled()->get()->map(fn ($domain) => ['id' => $domain->id, 'name' => $domain->name])->values(),
            'default_domain' => $default_domain ? $default_domain->id : (count($domains) > 0 ? $domains->first()->id : 0),
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('add-email-accounts');

        /* Validate */
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => ['required', 'string', new EmailLocalPart, new MailAddressAvailable],
            'domain' => ['required', 'integer', new OrgDomainName],
            'password' => 'required|confirmed',
        ]);

        $domain = OrgDomain::find($validated['domain']);

        /* Add email account to server */
        $email_address = $validated['email'].'@'.$domain->name;

        $email_server = Domain::connect($domain, 'email');
        if (! $email_server->existsEmail($domain, $email_address)) {
            try {
                $email_server->addEmail(
                    domain: $domain,
                    name: $validated['name'],
                    username: $validated['email'],
                    password: $validated['password'],
                );
            } catch (\Throwable $e) {
                report($e);

                try {
                    $email_server->deleteEmail($domain, $email_address);
                } catch (\Throwable $cleanup_exception) {
                    report($cleanup_exception);
                }

                return redirect('/settings/email/accounts')->with('error', __('organization.email.denied.glitch', ['email' => $email_address]));
            }
        }

        // The panel's own record of the mailbox, which is what counts it and what keeps its address taken
        Mailbox::firstOrCreate(['address' => strtolower($email_address)], [
            'organization_id' => $domain->organization_id,
            'org_domain_id' => $domain->id,
            'local_part' => strtolower($validated['email']),
            'display_name' => $validated['name'],
            'status' => Mailbox::ACTIVE,
            'activated_at' => now(),
        ]);

        return redirect('/settings/email/accounts')->with('success', __('organization.email.created', ['email' => $email_address]));
    }

    public function update(Request $request, $email_address)
    {
        Gate::authorize('edit-email-settings');

        $organization = Organization::account();
        $domain = $this->ownDomain($organization, $email_address);

        if (! $domain) {
            return redirect('/settings/email/accounts')->with('error', __('organization.email.denied.exists', ['email' => $email_address]));
        }

        /* Validate */
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'password' => 'confirmed|nullable',
        ]);

        $email_server = Domain::connect($domain, 'email');
        if ($email_server->existsEmail($domain, $email_address)) {
            $email_server->updateEmail(
                domain: $domain,
                name: $validated['name'],
                address: $email_address,
                password: $validated['password'] ?? null,
            );

            Mailbox::where('address', strtolower($email_address))->whereNull('username')->update(['display_name' => $validated['name']]);
        }

        return redirect('/settings/email/accounts')->with('success', __('organization.email.password_updated', ['email' => $email_address]));
    }

    public function destroy($email)
    {
        if (! Gate::allows('edit-email-settings')) {
            return redirect('/settings/email/accounts')->with('error', __('organization.email.denied.delete'));
        }

        $email_domain = $this->ownDomain(Organization::account(), $email);

        // Not one of this organization's domains, or not an address at all: there is nothing to connect to
        if (! $email_domain) {
            return redirect('/settings/email/accounts')->with('error', __('organization.email.denied.exists', ['email' => $email]));
        }

        $email_server = Domain::connect($email_domain, 'email');

        if ($email_server->existsEmail($email_domain, $email)) {
            $email_server->deleteEmail($email_domain, $email);
        }

        // Only a standalone mailbox: one that belongs to a user is removed from that user's page
        Mailbox::where('address', strtolower($email))->whereNull('username')->delete();

        return redirect('/settings/email/accounts')->with('success', __('organization.email.deleted', ['email' => $email]));
    }

    /**
     * The organization's domain an address belongs to, if it has one.
     */
    private function ownDomain($organization, string $email_address): ?OrgDomain
    {
        $parts = explode('@', $email_address);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return $organization->domains()->where('name', $parts[1])->first();
    }
}
