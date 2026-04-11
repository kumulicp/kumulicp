<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tld extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'default_driver',
        'renewal_max_days',
        'non_real_time',
        'min_register_years',
        'max_register_years',
        'min_renew_years',
        'max_renew_years',
        'renewal_min_days',
        'renewal_max_days',
        'reactivate_max_days',
        'min_transfer_years',
        'max_transfer_years',
        'is_api_registerable',
        'is_api_renewable',
        'is_api_transferable',
        'is_epp_required',
        'is_disable_mod_contact',
        'is_disable_wgallot',
        'is_include_in_extended_search_only',
        'sequence_number',
        'type',
        'sub_type',
        'is_supports_idn',
        'category',
        'supports_registrar_lock',
        'add_grace_period_days',
        'whois_verification',
        'provider_api_delete',
        'tld_state',
        'search_group',
        'registry',
    ];

    protected $casts = [
        'registration_disabled' => 'boolean',
        'standard_price' => 'float',
    ];
}
