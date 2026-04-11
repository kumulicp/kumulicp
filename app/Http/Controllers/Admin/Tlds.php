<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Facades\Domain;
use App\Tld;
use Illuminate\Http\Request;

class Tlds extends Controller
{
    public function index()
    {
        $tlds = Tld::paginate(20);

        return inertia()->render('Admin/Domains/TLDsList', [
            'tlds' => $tlds->map(function ($tld) {
                return [
                    'id' => $tld->id,
                    'name' => $tld->name,
                    'standard_price' => $tld->standard_price,
                    'registration_allowed' => ! $tld->registration_disabled,
                ];
            }),
            'meta' => [
                'total' => $tlds->total(),
                'pages' => $tlds->lastPage(),
                'page' => $tlds->currentPage(),
            ],
            'breadcrumbs' => [
                [
                    'url' => '/admin/service/domains',
                    'label' => 'Domains',
                ],
                [
                    'label' => 'TLDs',
                ],
            ],
        ]);
    }

    public function refresh()
    {
        $organization = auth()->user()->organization;
        $registrar = Domain::registrar(config('domains.default')); // TODO: Need default registrar setting
        $tlds = $registrar->tldList();

        foreach ($tlds as $tld) {
            $flight = Tld::updateOrCreate(
                [
                    'name' => $tld['Name'],
                    'default_driver' => config('domains.default'),
                ],
                [
                    'non_real_time' => $tld['non_real_time'],
                    'min_register_years' => $tld['min_register_years'],
                    'max_register_years' => $tld['max_register_years'],
                    'min_renew_years' => $tld['min_renew_years'],
                    'max_renew_years' => $tld['max_renew_years'],
                    'min_transfer_years' => $tld['min_transfer_years'],
                    'max_transfer_years' => $tld['max_transfer_years'],
                    'is_api_registerable' => $tld['is_api_registerable'],
                    'is_api_renewable' => $tld['is_api_renewable'],
                    'is_api_transferable' => $tld['is_api_transferable'],
                    'is_epp_required' => $tld['is_epp_required'],
                    'is_disable_mod_contact' => $tld['is_disable_mod_contact'],
                    'is_disable_wgallot' => $tld['is_disable_wgallot'],
                    'type' => $tld['type'],
                    'is_supports_idn' => $tld['is_supports_idn'],
                    'supports_registrar_lock' => $tld['supports_registrar_lock'],
                    'add_grace_period_days' => $tld['add_grace_period_days'],
                    'whois_verification' => $tld['whois_verification'],
                    'provider_api_delete' => $tld['provider_api_delete'],
                ]
            );

        }

        return redirect('/admin/service/domains/tlds')->with('success', 'Your TLDs have been updated');
    }

    public function store(Request $request)
    {
        $this->authorize('add-tld');

        $validated = $request->validate([
            'tld' => 'required|max:15|string|unique:tlds,name',
        ]);

        $tld = new Tld;
        $tld->default_driver = config('domains.default') ?? 'default';
        $tld->name = $validated['tld'];
        $tld->save();

        return redirect('/admin/service/domains/tlds/'.$tld->id)->with('success', $tld->name.' was added successfully');
    }

    public function show(Tld $tld)
    {
        return inertia()->render('Admin/Domains/TLDEdit', [
            'tld' => [
                'id' => $tld->id,
                'name' => $tld->name,
                'standard_price' => $tld->standard_price,
                'registration_allowed' => ! $tld->registration_disabled,
            ],
            'breadcrumbs' => [
                [
                    'url' => '/admin/service/domains',
                    'label' => 'Domains',
                ],
                [
                    'url' => '/admin/service/domains/tlds',
                    'label' => 'TLDs',
                ],
                [
                    'label' => $tld->name,
                ],
            ],
        ]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'standard_price' => 'required|numeric|decimal:0,2',
            'registration_allowed' => 'required|boolean',
        ]);

        $tld = Tld::find($id);
        $tld->standard_price = $validated['standard_price'];
        $tld->registration_disabled = ! $validated['registration_allowed'];
        $tld->save();

        return redirect('/admin/service/domains/tlds')->with('success', $tld->name.' was updated successfully.');

    }

    public function destroy($id)
    {
        $tld = Tld::where('id', $id)->delete();

        return redirect('/admin/service/domains/tlds/')->with('success', 'TLD delete successfully');
    }
}
