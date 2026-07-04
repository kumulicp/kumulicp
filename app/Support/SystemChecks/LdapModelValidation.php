<?php

namespace App\Support\SystemChecks;

use App\Ldap\Models\Account;
use App\Ldap\Models\AccountGroup;
use App\Ldap\Models\Admin;
use App\Ldap\Models\Domain;
use App\Ldap\Models\EmailUser;
use App\Ldap\Models\Group;
use App\Ldap\Models\Organization;
use App\Ldap\Models\OrganizationalUnit;
use App\Ldap\Models\User;
use LdapRecord\Models\OpenLDAP\Entry;

/**
 * Verifies that every entry in the LDAP directory satisfies the required
 * object classes of one of the application's LDAP models. Entries that
 * don't fit any model (e.g. a user missing an object class) get resolved
 * to LdapRecord's generic Entry model instead, which breaks any code that
 * expects a typed model such as App\Ldap\Models\User or ...\Group.
 */
class LdapModelValidation
{
    /**
     * The LDAP models an entry is expected to satisfy the requirements of.
     */
    protected array $models = [
        EmailUser::class,
        User::class,
        Group::class,
        AccountGroup::class,
        Account::class,
        Admin::class,
        Domain::class,
        Organization::class,
        OrganizationalUnit::class,
    ];

    public function run(): array
    {
        $requirements = $this->requirements();
        $issues = [];
        $checked = 0;

        foreach (Entry::get() as $entry) {
            $checked++;

            $objectClasses = array_map('strtolower', $entry->getAttribute('objectClass') ?? []);

            $fitsAModel = false;
            foreach ($requirements as $required) {
                if (! array_diff($required, $objectClasses)) {
                    $fitsAModel = true;
                    break;
                }
            }

            if (! $fitsAModel) {
                $issues[] = [
                    'dn' => $entry->getDn(),
                    'object_classes' => $entry->getAttribute('objectClass') ?? [],
                ];
            }
        }

        return [
            'checked' => $checked,
            'issues' => $issues,
        ];
    }

    /**
     * The required object classes of each model, ordered from most to
     * least specific so an entry is matched against the closest fit first.
     */
    protected function requirements(): array
    {
        $requirements = array_map(function (string $class) {
            $model = new $class;

            return array_map('strtolower', $model::$objectClasses);
        }, $this->models);

        usort($requirements, fn ($a, $b) => count($b) <=> count($a));

        return $requirements;
    }
}
