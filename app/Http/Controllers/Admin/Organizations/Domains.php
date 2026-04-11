<?php

namespace App\Http\Controllers\Admin\Organizations;

use App\Http\Controllers\Controller;
use App\Organization;

class Domains extends Controller
{
    public function index(Organization $organization)
    {
        $domains = $organization->domains();

        return inertia()->render('Admin/Organizations/Domains/DomainsList', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'domains' => $organization->domains()->with('app_instance')->get()->map(function ($domain) {
                return [
                    'id' => $domain->id,
                    'name' => $domain->name,
                    'type' => $domain->type,
                    'status' => $domain->status,
                    'app' => $domain->app_instance ? [
                        'id' => $domain->app_instance->id,
                        'name' => $domain->app_instance->label,
                    ] : [],
                ];
            }),
            'breadcrumbs' => [
                [
                    'label' => 'Organizations',
                    'url' => '/admin/organizations',
                ],
                [
                    'label' => $organization->name,
                    'url' => '/admin/organizations/'.$organization->id,
                ],
                [
                    'label' => 'Domains',
                ],
            ],
        ]);
    }
}
