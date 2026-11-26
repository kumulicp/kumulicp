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

    /**
     * The DN of an organization's groups, or of one group in it.
     *
     * A parent organization keeps its groups at `ou=<category>,ou=groups,o=<parent>`. A suborganization has no
     * `o=` entry of its own, so its groups sit inside the parent's tree, beside the parent's `ou=groups` and not
     * under it: `ou=<category>,ou=groups,ou=<slug>,ou=suborgs,o=<parent>`.
     *
     * That gives every organization one `ou=groups` base that holds only its own groups, so an app that can
     * only be pointed at a single group base (and searches it as a subtree) still sees just that organization's
     * groups. It also stays inside `o=<parent>`, so the ACLs and the apps' bind accounts, which are per `o=`,
     * keep working. Apps should get their base from LdapSupport::groupBase().
     */
    public static function group(Organization $organization, ?string $category = null, ?string $cn = null): string
    {
        $ou = $organization->parent_organization_id
            ? ['groups', $organization->slug, 'suborgs']
            : ['groups'];

        if ($category) {
            array_unshift($ou, $category);
        }

        return self::create($organization, count($ou) > 1 ? $ou : $ou[0], $cn);
    }

    /**
     * The containers a group needs, outermost first, as [ou, dn] pairs.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function groupContainers(Organization $organization, ?string $category = null): array
    {
        if ($organization->parent_organization_id) {
            $containers = [
                ['suborgs', self::create($organization, 'suborgs')],
                [$organization->slug, self::create($organization, [$organization->slug, 'suborgs'])],
                ['groups', self::group($organization)],
            ];
        } else {
            $containers = [['groups', self::group($organization)]];
        }

        if ($category) {
            $containers[] = [$category, self::group($organization, $category)];
        }

        return $containers;
    }

    public static function escape($value): string
    {
        return (new DistinguishedName)->escape((string) $value)->forDn()->get();
    }
}
