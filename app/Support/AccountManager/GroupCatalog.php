<?php

namespace App\Support\AccountManager;

use App\Organization;

/**
 * Helpers for the list of group categories an account manager returns (Groups::all()), so a parent can show
 * its own groups and its suborganizations' together. Both drivers return categories that each have an 'ou'
 * and a 'groups' list, though the DB driver keys them by category and the LDAP driver does not.
 */
class GroupCatalog
{
    /**
     * Tag every group in the categories with the organization it belongs to.
     *
     * @param  array<int|string, array<string, mixed>>  $categories
     * @return array<int|string, array<string, mixed>>
     */
    public static function tag(array $categories, Organization $organization): array
    {
        foreach ($categories as $key => $category) {
            $categories[$key]['groups'] = array_map(
                fn (array $group) => $group + ['organization_id' => $organization->id, 'organization' => $organization->name],
                array_values($category['groups'])
            );
        }

        return $categories;
    }

    /**
     * Add the groups of $other to the same categories of $categories.
     *
     * @param  array<int|string, array<string, mixed>>  $categories
     * @param  array<int|string, array<string, mixed>>  $other
     * @return array<int|string, array<string, mixed>>
     */
    public static function merge(array $categories, array $other): array
    {
        $keys = [];
        foreach ($categories as $key => $category) {
            $keys[$category['ou']] = $key;
        }

        foreach ($other as $category) {
            if (! array_key_exists($category['ou'], $keys)) {
                continue;
            }

            $key = $keys[$category['ou']];
            $categories[$key]['groups'] = array_merge(array_values($categories[$key]['groups']), array_values($category['groups']));
        }

        return $categories;
    }
}
