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

        foreach (Entry::get() as $entry) {
            $objectClasses = $entry->getAttribute('objectClass') ?? [];

            if (! $this->fitsAModel(array_map('strtolower', $objectClasses), $requirements)) {
                $issues[] = [
                    'dn' => $entry->getDn(),
                    'object_classes' => $objectClasses,
                ];
            }
        }

        return $issues;
    }

    /**
     * Attempt to correct an entry that doesn't fit any model by adding the
     * object classes of its closest-matching model. Entries are never
     * deleted, recreated, or stripped of object classes to fix them - some
     * directory servers won't allow that anyway - so if the directory
     * rejects the change, the entry is reported as unable to be fixed.
     */
    public function attemptFix(string $dn): array
    {
        $requirements = $this->requirements();
        $entry = Entry::find($dn);

        if (! $entry) {
            return ['dn' => $dn, 'fixed' => false, 'message' => __('system_checks.ldap_model_validation.entry_missing')];
        }

        $objectClasses = $entry->getAttribute('objectClass') ?? [];
        $lowerObjectClasses = array_map('strtolower', $objectClasses);

        $closest = $this->closestRequirement($lowerObjectClasses, $requirements);
        $missingKeys = array_keys(array_diff($closest['lower'], $lowerObjectClasses));

        if (! $missingKeys) {
            return ['dn' => $dn, 'fixed' => true, 'message' => null];
        }

        $missing = array_map(fn ($key) => $closest['classes'][$key], $missingKeys);

        try {
            $entry->setAttribute('objectClass', array_values(array_unique(array_merge($objectClasses, $missing))));
            $entry->save();
        } catch (\Throwable $e) {
            return ['dn' => $dn, 'fixed' => false, 'message' => __('system_checks.ldap_model_validation.entry_unfixable', ['error' => $e->getMessage()])];
        }

        $entry = Entry::find($dn);
        $updatedObjectClasses = array_map('strtolower', $entry?->getAttribute('objectClass') ?? []);

        if ($this->fitsAModel($updatedObjectClasses, $requirements)) {
            return ['dn' => $dn, 'fixed' => true, 'message' => null];
        }

        return ['dn' => $dn, 'fixed' => false, 'message' => __('system_checks.ldap_model_validation.entry_still_broken')];
    }

    /**
     * @param  string[]  $lowerObjectClasses
     * @param  array<int, array{classes: string[], lower: string[]}>  $requirements
     */
    protected function fitsAModel(array $lowerObjectClasses, array $requirements): bool
    {
        foreach ($requirements as $required) {
            if (! array_diff($required['lower'], $lowerObjectClasses)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The model requirements an entry is missing the fewest object classes
     * for - i.e. the closest fit worth attempting to correct it into.
     *
     * @param  string[]  $lowerObjectClasses
     * @param  array<int, array{classes: string[], lower: string[]}>  $requirements
     * @return array{classes: string[], lower: string[]}
     */
    protected function closestRequirement(array $lowerObjectClasses, array $requirements): array
    {
        $closest = $requirements[0];
        $fewestMissing = null;

        foreach ($requirements as $required) {
            $missing = count(array_diff($required['lower'], $lowerObjectClasses));

            if ($fewestMissing === null || $missing < $fewestMissing) {
                $fewestMissing = $missing;
                $closest = $required;
            }
        }

        return $closest;
    }

    /**
     * The required object classes of each model (original casing, plus a
     * lowercased copy for case-insensitive comparison), ordered from most
     * to least specific so an entry is matched against the closest fit
     * first.
     *
     * @return array<int, array{classes: string[], lower: string[]}>
     */
    protected function requirements(): array
    {
        $requirements = array_map(function (string $class) {
            $classes = (new $class)::$objectClasses;

            return [
                'classes' => $classes,
                'lower' => array_map('strtolower', $classes),
            ];
        }, $this->models);

        usort($requirements, fn ($a, $b) => count($b['classes']) <=> count($a['classes']));

        return $requirements;
    }
}
