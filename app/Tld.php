<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string|null $default_driver
 * @property int|null $min_register_years
 * @property int|null $max_register_years
 * @property int|null $min_renew_years
 * @property int|null $max_renew_years
 * @property int|null $renewal_min_days
 * @property int|null $renewal_max_days
 * @property int|null $reactivate_max_days
 * @property int|null $min_transfer_years
 * @property int|null $max_transfer_years
 * @property int|null $non_real_time
 * @property int|null $sequence_number
 * @property int|null $add_grace_period_days
 * @property bool|null $is_api_registerable
 * @property bool|null $is_api_renewable
 * @property bool|null $is_api_transferable
 * @property bool|null $is_epp_required
 * @property bool|null $is_disable_mod_contact
 * @property bool|null $is_disable_wgallot
 * @property bool|null $is_include_in_extended_search_only
 * @property bool|null $is_supports_idn
 * @property bool|null $supports_registrar_lock
 * @property bool|null $whois_verification
 * @property bool|null $provider_api_delete
 * @property bool $registration_disabled
 * @property float $standard_price
 * @property string|null $type
 * @property string|null $sub_type
 * @property string|null $category
 * @property string|null $tld_state
 * @property string|null $search_group
 * @property string|null $registry
 */
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
