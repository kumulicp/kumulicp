<?php

namespace App\Http\Controllers\Account\Email;

use App\EmailForwarder;
use App\EmailForwarderDestination;
use App\Http\Controllers\Controller;
use App\OrgDomain;
use App\Rules\OrgDomainName;
use App\Services\Email\ForwarderException;
use App\Services\Email\ForwarderService;
use App\Support\Facades\Organization;
use App\Support\Facades\ServerInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The organization's forwarders. The database is where they live (ForwarderService); the mail server
 * only applies them, from the events the service fires, so the page lists what the database has and
 * says how far the server got (`status`).
 */
class Forwarders extends Controller
{
    /**
     * Whether the domain's mail server can forward, asked of its interface without connecting: the page does not need
     * the server to be up, only the applying of a forwarder does (and that is done later, from the events).
     */
    private function supported(OrgDomain $domain): bool
    {
        return ServerInterface::supportsForwarders($domain->email_server);
    }

    private function unsupported()
    {
        return redirect('/settings/email/accounts')->with('error', __('organization.email.denied.forwarders'));
    }

    public function index()
    {
        Gate::authorize('view-emails');

        $organization = Organization::account();

        // Every domain of the organization that has email, the ones it brought itself as well as the ones it was given
        $email_domains = $organization->domains()->emailEnabled()->active()->primary()->orderBy('name')->get();

        $first_domain = $email_domains->first();
        if ($first_domain && ! $this->supported($first_domain)) {
            return $this->unsupported();
        }
        $default_domain = $email_domains->filter(function ($domain, int $key) {
            return $domain->is_primary;
        })->first();

        $forwarders = $organization->email_forwarders()->with('destinations')->orderBy('email')->get()->map(fn (EmailForwarder $forwarder) => [
            'id' => $forwarder->id,
            'address' => $forwarder->email,
            'status' => $forwarder->status,
            'destinations' => $forwarder->destinations->map(fn (EmailForwarderDestination $destination) => [
                'address' => $destination->destination,
                // An outside address only receives mail once its owner has opened the link sent to it
                'waiting' => ! $destination->isLive(),
            ])->values(),
        ])->values();

        return inertia('Organization/Settings/EmailAccounts/EmailForwarders', [
            'forwarders' => $forwarders,
            'domains' => $email_domains->map(function ($domain) {
                return [
                    'text' => $domain->name,
                    // What the form sends back: store() looks the domain up by id
                    'value' => $domain->id,
                ];
            }),
            'default_domain' => $default_domain ? $default_domain->id : (count($email_domains) > 0 ? $email_domains->first()->id : 0),
        ]);
    }

    /**
     * A new forwarder with its first destination, or another destination for one that exists.
     */
    public function store(Request $request, ForwarderService $forwarders)
    {
        Gate::authorize('add-email-accounts');

        $validated = $request->validate([
            'forwarder' => 'required',
            'domain' => ['required_if:forwarder,new', 'integer', new OrgDomainName],
            'new_forwarder' => 'required_if:forwarder,new',
            'destination' => 'required',
        ]);

        $organization = Organization::account();

        if ($validated['forwarder'] === 'new') {
            $domain = $organization->domains()->findOrFail($validated['domain']);
        } else {
            $forwarder = $this->own($validated['forwarder']);
            Gate::authorize('edit-email-settings', $forwarder);
            $domain = $forwarder->domain;
        }

        if (! $this->supported($domain)) {
            return $this->unsupported();
        }

        try {
            if (isset($forwarder)) {
                $row = $forwarders->addDestination($forwarder, $validated['destination']);
                $message = __('organization.email.forwarder.added', ['email' => $row->destination]);
            } else {
                $forwarder = $forwarders->create($organization, $domain, $validated['new_forwarder'], $validated['destination']);
                $row = $forwarder->destinations()->first();
                $message = __('organization.email.forwarder.created', ['email' => $forwarder->email]);
            }
        } catch (ForwarderException $e) {
            return redirect('/settings/email/forwarders')->with('error', $e->getMessage());
        }

        if ($row->kind === EmailForwarderDestination::EXTERNAL) {
            $message .= ' '.__('organization.email.forwarder.confirmation_sent', ['email' => $row->destination]);
        }

        return redirect('/settings/email/forwarders')->with('success', $message);
    }

    /**
     * Only the organization's own: another one's is not found, whatever the gate says.
     */
    private function own(int|string $id): EmailForwarder
    {
        return Organization::account()->email_forwarders()->findOrFail($id);
    }

    public function destroy(int $forwarder, ForwarderService $forwarders)
    {
        $forwarder = $this->own($forwarder);
        Gate::authorize('edit-email-settings', $forwarder);

        $forwarders->remove($forwarder);

        return redirect('/settings/email/forwarders')->with('success', __('organization.email.forwarder.deleted', ['email' => $forwarder->email]));
    }

    public function removeDestination(int $forwarder, string $destination, ForwarderService $forwarders)
    {
        $forwarder = $this->own($forwarder);
        Gate::authorize('edit-email-settings', $forwarder);

        try {
            $forwarders->removeDestination($forwarder, $destination);
        } catch (ForwarderException $e) {
            return redirect('/settings/email/forwarders')->with('error', $e->getMessage());
        }

        return redirect('/settings/email/forwarders')->with('success', __('organization.email.forwarder.removed', ['email' => $destination]));
    }
}
