<?php

namespace App\Ldap\Actions;

use App\Organization;
use LdapRecord\Models\Attributes\DistinguishedName;
use LdapRecord\Models\Attributes\EscapedValue;

class Dn
{
    public static function split($dn)
    {
        $components = array_map(
            fn ($values) => array_map(fn ($value) => EscapedValue::unescape($value), $values),
            DistinguishedName::make($dn)->assoc()
        );

        return array_map('array_reverse', $components);
    }

    public static function create(Organization|string $o, string|array|null $ou = null, string|array|null $cn = null)
    {
        $n = 0;
        if (is_array($cn) && count($cn) > 1) {
            $implode = implode(',cn=', array_map(fn ($v) => self::escape($v), $cn));
            $dn[$n] = 'cn='.$implode;
            $n++;
        } elseif ($cn) {
            $dn[$n] = 'cn='.self::escape($cn);
            $n++;
        }
        if (is_array($ou) && count($ou) > 1) {
            $implode = implode(',ou=', array_map(fn ($v) => self::escape($v), $ou));
            $dn[$n] = 'ou='.$implode;
            $n++;
        } elseif ($ou) {
            $dn[$n] = 'ou='.self::escape($ou);
            $n++;
        }
        if ($o) {
            if (is_string($o)) {
                $dn[$n] = 'o='.self::escape($o);
            } elseif ($o->parent_organization) {
                $dn[$n] = 'o='.self::escape($o->parent_organization->slug);
            } else {
                $dn[$n] = 'o='.self::escape($o->slug);
            }
            $n++;
        }
        $dn[$n] = config('ldap.connections.default.base_dn');

        $fullDn = implode(',', $dn);

        return $fullDn;
    }

    public static function escape($value): string
    {
        return (new DistinguishedName)->escape((string) $value)->forDn()->get();
    }
}
