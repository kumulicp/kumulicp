<?php

namespace App\Http\Controllers\Account\Email;

use App\Http\Controllers\Controller;
use App\Ldap\Models\Email;
use App\OrgDomain;
use App\Rules\LdapEmailNotExists;
use App\Rules\OrgDomainName;
use App\Support\Facades\Domain;
use App\Support\Facades\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class Accounts extends Controller
{
    public function index()
    {
        $this->authorize('view-emails');

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

        return inertia('Organization/Settings/EmailAccounts/EmailAccountsList', [
            'accounts' => $emails,
            'can' => [
                'add_email_accounts' => Gate::allows('add-email-accounts'),
            ],
            'domains' => $domains->map(function ($domain) {
                return [
                    'text' => $domain->name,
                    'value' => $domain->id,
                ];
            }),
            'default_domain' => $default_domain ? $default_domain->id : (count($domains) > 0 ? $domains->first()->id : 0),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('add-email-accounts');

        /* Validate */
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => ['required', 'alpha_num', new LdapEmailNotExists],
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

                if ($email_server->existsEmail($domain, $email_address)) {
                    $email_server->deleteEmail($domain, $email_address);
                }
                $email_account->delete();

                return redirect('/settings/email/accounts')->with('error', __('organization.email.denied.glitch', ['email' => $email_address]));
            }
        }

        return redirect('/settings/email/accounts')->with('success', __('organization.email.created', ['email' => $email_address]));
    }

    public function update(Request $request, $email_address)
    {
        $this->authorize('edit-email-settings');

        $organization = Organization::account();
        $email_parts = explode('@', $email_address);
        $domain_name = $email_parts[1];
        $domain = $organization->domains()->where('name', $domain_name)->first();

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
                password: $validated['password'],
            );
        }

        return redirect('/settings/email/accounts')->with('success', __('organization.email.password_updated', ['email' => $email_address]));
    }

    public function destroy($email)
    {
        if (! Gate::allows('edit-email-settings')) {
            return redirect('/settings/email/accounts')->with('error', __('organization.email.denied.delete'));
        }

        $email_address = explode('@', $email)[1];

        $organization = Organization::account();
        $email_domain = $organization->domains()->where('name', $email_address)->first();
        $email_server = Domain::connect($email_domain, 'email');

        if ($email_domain) {
            if ($email_server->existsEmail($email_domain, $email)) {
                $email_server->deleteEmail($email_domain, $email);
            }
        }

        return redirect('/settings/email/accounts')->with('success', __('organization.email.deleted', ['email' => $email]));
    }
}
