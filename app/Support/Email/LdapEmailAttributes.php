<?php

namespace App\Support\Email;

use App\Support\Facades\Settings;

/**
 * Which LDAP attributes carry email data, and what that means for the email features.
 *
 * Aliases and the recovery email are never stored in an attribute KumuliCP already
 * uses, so with LDAP an admin has to add one for each (and map it on the LDAP settings
 * page). Without LDAP there is no such limit: the mail server is managed directly.
 */
class LdapEmailAttributes
{
    /**
     * The attribute each mapping uses until an admin sets one.
     *
     * @var array<string, string>
     */
    public const DEFAULT_MAPPING = [
        'name' => 'displayName',
        'first_name' => 'givenName',
        'last_name' => 'sn',
        'email' => 'mail',
        'phone_number' => 'telephoneNumber',
        'username' => 'cn',
        'personal_email' => 'mail',
        'org_email' => 'mail',
        'access_type' => 'employeeType',
        'password' => 'userPassword',
    ];

    public static function usingLdap(): bool
    {
        return config('account_manager.driver') === 'ldap';
    }

    public static function aliasAttribute(): ?string
    {
        return self::setting('ldap_aliases');
    }

    public static function recoveryAttribute(): ?string
    {
        return self::setting('ldap_recovery_email');
    }

    /**
     * Without LDAP aliases always work. With it they need an attribute of their own.
     */
    public static function aliasesEnabled(): bool
    {
        return ! self::usingLdap() || self::aliasAttribute() !== null;
    }

    /**
     * Whether the recovery email is also written to the directory.
     */
    public static function recoverySyncEnabled(): bool
    {
        return self::usingLdap() && self::recoveryAttribute() !== null;
    }

    /**
     * The attribute every mapping reads, from the given LDAP settings input (or the
     * defaults for the ones it leaves out).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function mapping(array $input): array
    {
        $mapping = [];

        foreach (self::DEFAULT_MAPPING as $key => $default) {
            $mapping[$key] = self::clean($input[$key] ?? null) ?? $default;
        }

        return $mapping;
    }

    /**
     * The new attributes that clash with another mapping, so the data they hold would
     * be mixed up with it. LDAP attribute names are not case sensitive.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string> The setting that clashes, by the one it clashes with
     */
    public static function conflicts(array $input): array
    {
        $new_attributes = [
            'aliases' => self::clean($input['aliases'] ?? null),
            'recovery_email' => self::clean($input['recovery_email'] ?? null),
        ];
        $taken = self::mapping($input) + $new_attributes;
        $conflicts = [];

        foreach ($new_attributes as $field => $attribute) {
            if ($attribute === null) {
                continue;
            }

            foreach ($taken as $other => $other_attribute) {
                if ($other !== $field && $other_attribute !== null && strcasecmp($attribute, $other_attribute) === 0) {
                    $conflicts[$field] = $other;

                    break;
                }
            }
        }

        return $conflicts;
    }

    private static function setting(string $key): ?string
    {
        return self::clean(Settings::get($key));
    }

    private static function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
