<?php

namespace App\Http\Controllers\Account\Web;

use App\Actions\Domains\DomainDelete;
use App\Actions\Email\AddEmailDomain;
use App\Actions\Organizations\InvoiceOrganization;
use App\Http\Controllers\Controller;
use App\Mail\DomainTransferRequest;
use App\Organization;
use App\OrgDomain;
use App\Support\Facades\Action;
use App\Support\Facades\Domain;
use App\Support\Facades\Email;
use App\Support\Facades\Settings;
use App\Tld;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class Domains extends Controller
{
    public function index()
    {
        $this->authorize('view-domains');

        $organization = auth()->user()->organization;
        $base_domain = $organization->base_domain();
        $suborganizations = $organization->suborganizations()->with(['domains', 'domains.organization'])->get();

        $web_domains = OrgDomain::where(function ($query) use ($organization, $suborganizations) {
            $query->where('organization_id', $organization->id);
            foreach ($suborganizations as $suborg) {
                $query->orWhere('organization_id', $suborg->id);
            }
        })
            ->where('status', '!=', 'pending_registration')
            ->where('status', '!=', 'pending_transfer')
            ->with('organization')
            ->orderBy('name')
            ->paginate(15);

        return inertia('Organization/Settings/WebDomains/WebDomainsList', [
            'domains' => $web_domains->map(function ($domain) {
                $app_instance = $domain->app_instance;
                $app_instance_domain = ($app_instance && $app_instance->primary_domain_id != $domain->id) ? ' ('.$app_instance->domain().')' : '';

                return [
                    'id' => $domain->id,
                    'name' => $domain->name,
                    'status' => $domain->status,
                    'type' => $domain->type,
                    'registered' => $domain->registeredAt(),
                    'expires' => $domain->expiresAt(),
                    'email_status' => $domain->email_status,
                    'organization' => [
                        'id' => $domain->organization->id,
                        'name' => $domain->organization->name,
                    ],
                    'app' => $app_instance && (! $domain->organization->parent_organization_id || $domain->organization_id === $app_instance->organization_id) ? [
                        'id' => $app_instance->id,
                        'name' => $app_instance->application->name.$app_instance_domain,
                    ] : [],
                ];
            }),
            'suborganization_count' => count($suborganizations),
            'can' => [
                'add_domains' => Gate::allows('add-domains'),
                'connect_domains' => Gate::allows('connect-domains'),
                'register_domains' => Gate::allows('register-domains'),
                'transfer_domains' => Gate::allows('transfer-domains'),
            ],
            'meta' => [
                'total' => $web_domains->total(),
                'pages' => $web_domains->lastPage(),
                'page' => $web_domains->currentPage(),
            ],
            'breadcrumbs' => [
                [
                    'label' => 'Domains',
                ],
            ],
        ]);
    }

    public function edit(OrgDomain $domain)
    {
        $this->authorize('edit-domain', $domain);

        $organization = auth()->user()->organization;

        $suborganizations = $organization->suborganizations;
        $organizations = collect([$organization])->merge($suborganizations);

        $active_app_families = $organization->activeAppFamilies();
        $app_instance_families = [];
        $all_app_instance_families = [];
        $all_app_instance_families[] = [
            'value' => 0,
            'text' => 'None',
        ];
        $redirect_app_found = false;

        foreach ($active_app_families as $family) {
            if ($family->id === $domain->app_instance_id) {
                $redirect_app_found = true;
            }
            $all_app_instance_families[] = [
                'value' => $family->id,
                'text' => $family->appNameIncludingChildApps().' ('.$family->domain().')',
            ];
        }

        if ($domain->app_instance && ! $redirect_app_found) {
            $all_app_instance_families[] = [
                'value' => $domain->app_instance_id,
                'text' => "Managed by {$domain->app_instance->organization->name}",
            ];
        }

        $email_ip = '';
        if ($email_server = $domain->email_server) {
            $email_ip = $email_server->server->ip;
        }

        $tld = $domain->tld;
        $year = 0;
        $total = 0;
        if (method_exists(Domain::registrar($domain), 'maxRenewalYears')) {
            $max_renewal_years = Domain::registrar($domain)->maxRenewalYears();
        }

        return inertia('Organization/Settings/WebDomains/WebDomainsSettings', [
            'domain' => [
                'id' => $domain->id,
                'name' => $domain->name,
                'primary_app' => $domain->app_instance ? [
                    'id' => $domain->app_instance->id,
                    'name' => $domain->app_instance->application->slug,
                    'label' => $domain->app_instance->application->name,
                ] : [],
                'redirect_to' => $domain->app_instance ? $domain->app_instance->id : null,
                'email' => [
                    'status' => $domain->email_status,
                ],
                'organization' => [
                    'id' => $domain->organization->id,
                    'name' => $domain->organization->name,
                ],
                'required_records' => $email_server ? Email::requiredDNSRecords($domain) : [],
                'type' => $domain->type,
                'dkim_public_key' => $domain->dkim_public_key,
                'actions' => [
                    'enable_email' => Gate::allows('enable-email-domain', $domain),
                    'renew' => Gate::allows('renew-domain', $domain),
                    'reactivate' => Gate::allows('reactivated-domain', $domain),
                    'self_manage' => Gate::allows('self-manage-domain', $domain),
                    'transfer_in' => Gate::allows('transfer-in-domain', $domain),
                    'request_transfer' => Gate::allows('request-domain-transfer', $domain),
                    'remove' => Gate::allows('remove-domain', $domain),
                ],
                'renewal_price' => $tld ? collect(Domain::registrar($tld)->pricing($tld, $domain->name)->renewPrices())->mapWithKeys(function ($price, int $key) use (&$year, &$total) {
                    $total += $price;
                    $year++;
                    $year_string = Str::plural('year', $year);

                    return [
                        $key - 1 => [
                            'year' => $year,
                            'price' => $price,
                            'total' => $total,
                            'text' => "$year $year_string (\$$total)",
                        ],
                    ];

                })->filter(function ($value, int $key) use ($max_renewal_years) {
                    return $value['year'] < $max_renewal_years;
                })->all() : [],
                'can' => [
                ],
            ],
            'subdomains' => $domain->subdomains->map(function ($subdomain) {
                return [
                    'id' => $subdomain->id,
                    'name' => $subdomain->name,
                    'app' => $subdomain->app_instance ? [
                        'id' => $subdomain->app_instance->id,
                        'name' => $subdomain->app_instance->label,
                    ] : null,
                    'ttl' => $subdomain->ttl,
                    'host' => $subdomain->host,
                    'value' => $subdomain->value,
                    'type' => $subdomain->type,
                    'status' => $subdomain->status,
                    'can' => [
                        'edit' => true,
                        'delete' => $subdomain?->app_instance?->primary_domain_id !== $subdomain->id,
                    ],
                ];
            }),
            'organizations' => $organizations->map(function ($organization) {
                return [
                    'id' => $organization->id,
                    'name' => $organization->name,
                ];
            }),
            'all_app_instance_options' => $all_app_instance_families,
            'breadcrumbs' => [
                [
                    'url' => '/settings/domains',
                    'label' => 'Domains',
                ],
                [
                    'label' => $domain->name,
                ],
            ],
        ]);
    }

    public function update(Request $request, OrgDomain $domain)
    {
        $this->authorize('edit-domain', $domain);

        $organization = auth()->user()->organization;

        $validated = $request->validate([
            'organization' => [
                function (string $attribute, mixed $value, \Closure $fail) use ($domain) {
                    if ($organization = Organization::find($value)) {
                        return $domain->belongsToOrganization($organization);
                    }

                    return $fail('This organization doesn\'t exist');
                },
            ],
        ]);
        if ($validated['organization'] !== $domain->organization_id) {
            $domain->organization()->associate(Organization::find($validated['organization']))->save();
        }

        return redirect('/settings/domains')->with('success', __('organization.domain.updating'));
    }

    public function renew(Request $request, OrgDomain $domain)
    {
        $this->authorize('renew-domain', $domain);

        $max_renewal_years = Domain::registrar($domain)->maxRenewalYears();

        $validated = $request->validate([
            'years' => "required|min:1|max:$max_renewal_years|integer",
        ]);

        $organization = auth()->user()->organization;

        $registrar = Domain::registrar($domain)->renew($validated['years']);

        Action::execute(new InvoiceOrganization($organization, __('organization.domain.invoice.renewal', ['domain' => $domain->name]), $registrar['price']));

        return redirect('/settings/domains')->with('success', __('organization.domain.renewed', ['domain' => $domain->name]));
    }

    public function reactivate(Request $request, OrgDomain $domain)
    {
        $this->authorize('reactivate-domain', $domain);

        $validate = $request->validate([
            'years' => 'required|numeric|min:1|max:10',
        ]);

        $organization = auth()->user()->organization;
        $domain = Domain::registrar($domain)->reactivate();

        Action::execute(new InvoiceOrganization($organization, __('organization.domain.invoice.reactivation'), $domain['price']));

        return redirect('/settings/domains')->with('success', __('organization.domain.reactivated', ['domain' => $domain->name]));

    }

    public function self_manage(Request $request, OrgDomain $domain)
    {
        $this->authorize('self-manage-domain', $domain);

        $domain->transfer_id = null;
        $domain->status = 'active';
        $domain->transferred_at = null;
        $domain->status_id = null;
        $domain->source = 'organization';
        $domain->type = 'connection';
        $domain->save();

        return redirect('/settings/domains')->with('success', __('organization.domain.self_managed', ['domain' => $domain->name]));
    }

    public function remove(Request $request, OrgDomain $domain)
    {
        $this->authorize('remove-domain', $domain);

        $organization = auth()->user()->organization;

        $task = Action::execute(new DomainDelete($organization, $domain));

        $domain->status = 'removing';
        $domain->save();

        return redirect('/settings/domains')->with('success', __('organization.domain.removed', ['domain' => $domain->name]));
    }

    public function enable_email(OrgDomain $domain)
    {
        $this->authorize('enable-email-domain', $domain);

        $organization = auth()->user()->organization;

        $task = Action::execute(new AddEmailDomain($organization, $domain));

        return redirect('/settings/domains')->with('success', __('organization.domain.enable_email', ['domain' => $domain->name]))->with('reset_menu', true);
    }

    public function request_transfer(OrgDomain $domain)
    {
        $this->authorize('request-domain-transfer', $domain);

        $organization = auth()->user()->organization;
        $support_email = Settings::get('support_email');
        if ($support_email) {
            Mail::to($support_email)->send(new DomainTransferRequest($organization, auth()->user, $domain));
        }

        return redirect('/settings/domains')->with('success', __('organization.domain.request_transfer'));
    }

    public function transfer_in(Request $request, OrgDomain $domain)
    {
        $this->authorize('transfer-in-domain', $domain);

        $validated = $request->validate([
            'epp_code' => 'required|string|max:20',
        ]);

        $domain->status = 'transferring';
        $domain->save();

        $organization = auth()->user()->organization;
        $domain_tld = Domain::getTld($domain->name);

        $tld = Tld::where('name', $domain_tld)->first();

        $registrar_price = Domain::registrar($tld)->pricing($tld, $domain->name);
        $transfer_price = $registrar_price->transferPrices($organization)[1];

        Action::execute(new TransferDomainName($organization, $domain, $validated['epp_code'], $registrar_price->transferPrice(1)));

        // Charge organization for domain name
        $stripeCharge = $organization->invoiceFor(__('organization.domain.invoice.transfer', ['domain' => $domain->name]), $domain->stripePrice());

        return redirect('/settings/domains')->with('success', __('organization.domain.transferring', ['domain' => $response['DomainName']]));
    }
}
