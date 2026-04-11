<?php

namespace App\Http\Controllers\Account\Web;

use App\AppInstance;
use App\Http\Controllers\Controller;
use App\OrgDomain;
use App\OrgSubdomain;
use App\Rules\SubdomainNotExists;
use App\Support\Facades\Action;
use App\Support\Facades\Application;
use App\Support\Facades\Domain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Fluent;

class Subdomains extends Controller
{
    public function index() {}

    public function store(Request $request, OrgDomain $domain)
    {
        $organization = auth()->user()->organization;
        $validated = $this->validation($request, $domain);

        $subdomain = new OrgSubdomain;
        $subdomain->organization()->associate($organization);
        $subdomain->domain()->associate($domain);
        $subdomain->host = $validated['host'] === '' ? '*' : $validated['host'];
        $subdomain->name = in_array($validated['host'], ['@', '*', '']) ? $domain->name : $validated['host'].'.'.$domain->name;
        $subdomain->type = $validated['type'];
        if ($validated['type'] !== 'app') {
            $subdomain->value = $validated['value'];
        }
        $subdomain->ttl = $validated['ttl'];
        $subdomain->save();

        // Update domain redirect. null app_instance will make sure it's removed
        $app_instance = $validated['type'] === 'app' ? AppInstance::find($validated['app']) : null;
        Domain::updateAppInstance($subdomain, $app_instance);

        if (Domain::isIntegratedRegistrar($domain)) {
            Action::dispatch('system', 'update_dns_records', [$organization, $domain]);
        }

        return redirect('/settings/domains/'.$domain->name)->with('success', __('organization.domain.updating'));
    }

    public function edit(OrgDomain $domain) {}

    public function update(Request $request, OrgDomain $domain, OrgSubdomain $subdomain)
    {
        $this->authorize('edit-domain', $domain);
        $this->authorize('redirect-domain', $subdomain);

        $organization = auth()->user()->organization;

        $validated = $this->validation($request, $domain, $subdomain);

        $subdomain->host = $validated['host'];
        $subdomain->type = $validated['type'];
        $subdomain->name = in_array($validated['host'], ['@', '*', '']) ? $domain->name : $validated['host'].'.'.$domain->name;
        if ($validated['type'] !== 'app') {
            $subdomain->value = $validated['value'];
        }
        $subdomain->ttl = $validated['ttl'];
        $subdomain->save();

        // Update domain redirect. null app_instance will make sure it's removed
        $app_instance = $validated['type'] === 'app' ? AppInstance::find($validated['app']) : null;
        Domain::updateAppInstance($subdomain, $app_instance);

        if (Domain::isIntegratedRegistrar($domain)) {
            Action::dispatch('system', 'update_dns_records', [$organization, $domain]);
        }

        return redirect('/settings/domains/'.$domain->name)->with('success', __('organization.domain.updating'));
    }

    public function destroy(Request $request, OrgDomain $domain, OrgSubdomain $subdomain)
    {
        if (! Gate::allows('remove-subdomain', $subdomain)) {
            return back()->with('error', __('organization.domain.remove_denied', ['domain' => $subdomain->name]));
        }

        $app_instance = $subdomain->app_instance;
        $subdomain_name = $subdomain->name;
        $subdomain->delete();

        if ($app_instance) {
            $app_instance = Application::instance($app_instance);
            $app_instance->updateRedirectDomains();
        }

        if (Domain::isIntegratedRegistrar($domain)) {
            Action::dispatch('system', 'update_dns_records', [$domain->organization, $domain]);
        }

        return redirect('/settings/domains/'.$domain->name)->with('success', __('organization.domain.removed', ['domain' => $subdomain_name]));
    }

    private function validation(Request $request, OrgDomain $domain, ?OrgSubdomain $subdomain = null)
    {
        $organization = auth()->user()->organization;

        $validated = $request->validate([
            'host' => ['string', 'required', new SubdomainNotExists($domain, $subdomain)],
            'type' => ['in:A,AAAA,ALIAS,CAA,CNAME,MX,MXE,NS,TXT,URL,URL301,FRAME,app'],
            'value' => ['required_unless:type,app'],
            'ttl' => ['exclude_if:type,app', 'numeric', 'required'],
        ]);

        $appValidation = Validator::make($request->all(), [])
            ->sometimes('app', [
                function (string $attribute, mixed $value, \Closure $fail) use ($organization) {
                    if (! is_null($value) && ! AppInstance::where('id', $value)->with('web_server.server')->first()->belongsToOrganization($organization)) {
                        $fail("App doesn't exist");

                        return;
                    }

                },
                function (string $attribute, mixed $value, \Closure $fail) use ($domain, $validated) {
                    $app_instance = AppInstance::find($value);
                    $domain_name = in_array($validated['host'], ['@', '*']) ? $domain->name : $validated['host'].'.'.$domain->name;
                    if ($domain->type !== 'managed' && (! $app_instance || gethostbyname($domain_name) !== $app_instance->web_server->server->ip)) {
                        $fail(__('organization.domain.denied.ip', ['domain' => $domain_name, 'ip' => $app_instance->web_server->server->ip]));
                    }
                },
            ], function (Fluent $request) {
                return $request->type === 'app';
            })->validate();

        $hostValidation = Validator::make($request->all(), [])
            ->sometimes('value', ['required', 'ipv4'], function (Fluent $request) {
                return $request->type === 'A';
            })->sometimes('value', 'required|ipv6', function (Fluent $request) {
                return $request->type === 'AAAA';
            })->sometimes('value', ['required', function (string $attribute, mixed $value, \Closure $fail) {
                $preg_matched = preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i", $value) // valid chars check
                    && preg_match('/^.{1,253}$/', $value) // overall length check
                    && preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $value) // length of each label
                    && substr_count($value, '.') > 0; // confirm at least a dot present

                if (! $preg_matched) {
                    $fail('This value must be a valid domain name');
                }
            }], function (Fluent $request) {
                return in_array($request->type, ['CNAME', 'MX', 'MXE', 'NS']);
            })->sometimes('value', 'required|string', function (Fluent $request) {
                return in_array($request->type, ['ALIAS', 'TXT', 'CAA']);
            })->validate();

        return $request->all();
    }
}
