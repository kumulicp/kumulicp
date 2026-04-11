<?php

namespace App\Http\Controllers\Account\Web;

use App\Actions\Domains\TransferDomainName;
use App\Http\Controllers\Controller;
use App\Rules\DomainName;
use App\Support\Facades\Action;
use App\Support\Facades\Domain;
use App\Tld;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class Transfer extends Controller
{
    public function setup()
    {
        $this->authorize('transfer-domains');

        return inertia('Organization/Settings/WebDomains/WebDomainsNewTransfer');
    }

    public function price(Request $request)
    {
        if (! Gate::allows('transfer-domains')) {
            return response()->json([
                'status' => 'error',
                'messages' => ['error' => [__('organization.domain.denied.add')]],
            ]);
        }

        $validator = Validator::make($request->all(), [
            'domain_name' => 'required|string|unique:org_domains,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed_validation',
                'messages' => $validator->messages(),
            ]);
        }

        $organization = auth()->user()->organization;
        $domain_tld = Domain::getTld($request->domain_name);

        $tld = Tld::where('name', $domain_tld)->first();

        if ($tld) {
            $registrar_price = Domain::registrar($tld)->pricing($tld, $request->domain_name);
            $transfer_price = $registrar_price->transferPrices($organization)[1];
        } else {
            return response()->json([
                'status' => 'failed_validation',
                'messages' => ['domain_name' => [__('organization.domain.denied.illegal_tld', ['tld' => $domain_tld])]],
            ]);
        }

        return response()->json([
            'status' => 'success',
            'price' => $transfer_price,
        ]);
    }

    public function transfer(Request $request)
    {
        $this->authorize('transfer-domains');

        $validated = $request->validate([
            'domain_name' => ['required', 'string', 'max:70', 'unique:org_domains,name', 'lowercase', new DomainName],
            'epp_code' => 'required|string|max:20',
        ]);

        $organization = auth()->user()->organization;
        $domain_tld = Domain::getTld($validated['domain_name']);

        $tld_driver = Tld::where('name', $domain_tld)->first()?->default_driver ?? config('domains.default');

        $domain = Domain::add(organization: $organization, name: $validated['domain_name'], source: $tld_driver, type: 'managed', status: 'transferring');

        $registrar_price = Domain::registrar($tld)->pricing($tld, $request->domain_name);
        $transfer_price = $registrar_price->transferPrices($organization)[1];

        Action::execute(new TransferDomainName($organization, $domain->get(), $validated['epp_code'], $registrar_price->transferPrice(1)));

        return redirect('/settings/domains')->with('success', __('organization.domain.transferring', ['domain' => $validated['domain_name']]));
    }
}
