<?php

namespace App\Ldap\Actions;

use App\Organization;

class Dn
{
    public static function split($dn)
    {
        $dnArray = [];
        $explode = explode(',', $dn);

        foreach ($explode as $var) {
            $split = explode('=', $var);

            if (! array_key_exists($split[0], $dnArray)) {
                $n = 0;
            }

            $dnArray[$split[0]][$n] = $split[1];

            $n++;
        }

        foreach ($dnArray as $key => $value) {
            $final_array[$key] = array_reverse($value);
        }

        return $final_array;
    }

    public static function create(Organization|string $o, string|array|null $ou = null, string|array|null $cn = null)
    {
        $n = 0;
        if (is_array($cn) && count($cn) > 1) {
            $implode = implode(',cn=', $cn);
            $dn[$n] = 'cn='.$implode;
            $n++;
        } elseif ($cn) {
            $dn[$n] = 'cn='.$cn;
            $n++;
        }
        if (is_array($ou) && count($ou) > 1) {
            $implode = implode(',ou=', $ou);
            $dn[$n] = 'ou='.$implode;
            $n++;
        } elseif ($ou) {
            $dn[$n] = 'ou='.$ou;
            $n++;
        }
        if ($o) {
            if (is_string($o)) {
                $dn[$n] = 'o='.$o;
            } elseif ($o->parent_organization) {
                $dn[$n] = 'o='.$o->parent_organization->slug;
            } else {
                $dn[$n] = 'o='.$o->slug;
            }
            $n++;
        }
        $dn[$n] = env('LDAP_BASE_DN');

        $fullDn = implode(',', $dn);

        return $fullDn;
    }
}
