<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Organization;
use App\OrgDomain;
use App\Support\Facades\Domain;

class Domains extends Controller
{
    public function index()
    {
        $domains = Domain::registrar(config('domains.default'))->list();

        return inertia()->render('Admin/Domains/DomainsList', [
            'domains' => $domains,
            'breadcrumbs' => [
                [
                    'label' => __('admin.domains.domains'),
                ],
            ],
        ]);
    }

    public function update($domain_name)
    {
        $org_domain = OrgDomain::where('name', $domain_name)->first();

        if ($org_domain) {
            $domain = Domain::registrar($org_domain)->info($domain_name);

            $organization = Organization::where('id', $org_domain->organization_id)->first();
            $org_domain->is_premium = $domain['is_premium'];
            $org_domain->registered_at = $domain['created_date'];
            $org_domain->expires_at = $domain['expired_date'];
            $org_domain->whois_guard_enabled = $domain['whois_guard']['enabled'];
            $org_domain->whois_guard_id = $domain['whois_guard']['id'];
            $org_domain->save();

            return redirect('/admin/service/domains')->with('success', __('admin.domains.updated', ['domain' => $domain_name]));
        }

        return redirect('/admin/service/domains')->with('error', __('admin.domains.denied.update', ['domain' => $domain_name]));
    }
}
