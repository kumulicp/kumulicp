<?php

namespace App\Http\Controllers\Account\Email;

use App\EmailForwarder;
use App\Http\Controllers\Controller;
use App\Rules\OrgDomainName;
use App\Support\Facades\Domain;
use App\Support\Facades\Organization;
use Illuminate\Http\Request;

class Forwarders extends Controller
{
    public function index()
    {
        $this->authorize('view-emails');

        $organization = Organization::account();
        $custom_domains = $organization->domains()->emailEnabled()->active()->where('type', 'connection')->get();
        $email_forwarders = $organization->email_forwarders;

        $email_domains = $organization->domains()->emailEnabled()->active()->where('type', '!=', 'connection')->get();
        $default_domain = $email_domains->filter(function ($domain, int $key) {
            return $domain->is_primary;
        })->first();

        $forwarders = [];

        foreach ($email_forwarders as $forwarder) {
            if ($forwarder->domain) {
                $email_server = Domain::connect($forwarder->domain, 'email');
                $forwarder_listing = $email_server->emailForwarders($forwarder->email);

                $forwarders[] = [
                    'id' => $forwarder->id,
                    'address' => $forwarder->email,
                    'destinations' => $forwarder_listing['list'],
                ];
            }
        }

        return inertia('Organization/Settings/EmailAccounts/EmailForwarders', [
            'forwarders' => $forwarders,
            'domains' => $email_domains->map(function ($domain) {
                return [
                    'text' => $domain->name,
                    'value' => $domain->name,
                ];
            }),
            'default_domain' => $default_domain ? $default_domain->id : (count($email_domains) > 0 ? $email_domains->first()->id : 0),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('add-email-accounts');

        /* Validate */
        $validated = $request->validate([
            'forwarder' => 'required',
            'domain' => ['required_if:forwarder,new', 'integer', new OrgDomainName],
            'new_forwarder' => 'required_if:forwarder,new',
            'destination' => 'required',
        ]);

        $organization = Organization::account();
        $domain = $organization->domains()->find($validated['domain']);
        $forwarder_name = $validated['forwarder'] == 'new' ? $validated['new_forwarder'] : $validated['forwarder'];
        $forwarder_email = $validated['forwarder'] != 'new' ? $validated['forwarder'] : $validated['new_forwarder'].'@'.$domain->name;

        $email_server = Domain::connect($domain, 'email');
        $email_response = $email_server->addEmail($domain, $validated['forwarder'], 'forwarder', ['forwarder' => $forwarder_email, 'destination' => $validated['destination']]);

        $forwarder = EmailForwarder::where('organization_id', $organization->id)->where('email', $forwarder_email)->first();
        if (! $forwarder && ! $email_server->hasEmailError()) {
            // Add forwarder to database
            $forwarder = new EmailForwarder;
            $forwarder->organization_id = $organization->id;
            $forwarder->domain_id = $domain->id;
            $forwarder->email = $forwarder_email;
            $forwarder->server_email_id = $email_response['id'];
            $forwarder->save();
        }

        if (isset($error)) {
            return redirect('/settings/email/forwarders')->with('error', $error);
        } else {
            return redirect('/settings/email/forwarders')->with('success', __('organization.email.created', ['email' => $forwarder_email]));
        }
    }

    public function remove(EmailForwarder $forwarder, $destination)
    {
        $this->authorize('edit-email-settings');

        $email_server = Domain::connect($forwarder->domain, 'email');
        if ($email_server) {
            $email_server->deleteEmailForwarders($forwarder->email, $destination);
        }

        return redirect('/settings/email/forwarders/')->with('success', __('organization.email.deleted', ['email' => $forwarder->email]));
    }
}
