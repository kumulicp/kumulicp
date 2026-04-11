<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Support\Facades\Settings as SettingsFacade;
use Illuminate\Http\Request;

class InvoiceSettings extends Controller
{
    public function index()
    {
        return inertia()->render('Admin/Settings/InvoiceSettings', [
            'settings' => [
                'invoice_vendor_name' => SettingsFacade::get('invoice_vendor_name'),
                'invoice_vendor_product' => SettingsFacade::get('invoice_vendor_product'),
                'invoice_vendor_street' => SettingsFacade::get('invoice_vendor_street'),
                'invoice_vendor_location' => SettingsFacade::get('invoice_vendor_location'),
                'invoice_vendor_phone_number' => SettingsFacade::get('invoice_vendor_phone_number'),
                'invoice_vendor_email' => SettingsFacade::get('invoice_vendor_email'),
                'invoice_vendor_url' => SettingsFacade::get('invoice_vendor_url'),
                'invoice_vendor_vat' => SettingsFacade::get('invoice_vendor_vat'),
            ],
            'breadcrumbs' => [
                [
                    'label' => 'Server Settings',
                ],
            ],
        ]);
    }

    public function update(Request $request)
    {

        /* Validate */
        $validated = $request->validate([
            'invoice_vendor_name' => 'string|required',
            'invoice_vendor_product' => 'string|required',
            'invoice_vendor_street' => 'string|required',
            'invoice_vendor_location' => 'string|required',
            'invoice_vendor_phone_number' => 'string|required',
            'invoice_vendor_email' => 'string|required',
            'invoice_vendor_url' => 'string|required',
            'invoice_vendor_vat' => 'string|nullable',
        ]);

        SettingsFacade::update('invoice_vendor_name', $validated['invoice_vendor_name']);
        SettingsFacade::update('invoice_vendor_product', $validated['invoice_vendor_product']);
        SettingsFacade::update('invoice_vendor_street', $validated['invoice_vendor_street']);
        SettingsFacade::update('invoice_vendor_location', $validated['invoice_vendor_location']);
        SettingsFacade::update('invoice_vendor_phone_number', $validated['invoice_vendor_phone_number']);
        SettingsFacade::update('invoice_vendor_email', $validated['invoice_vendor_email']);
        SettingsFacade::update('invoice_vendor_url', $validated['invoice_vendor_url']);
        SettingsFacade::update('invoice_vendor_vat', $validated['invoice_vendor_vat']);

        return redirect('admin/settings/invoice')->with('success', 'Settings have been updated!');
    }
}
