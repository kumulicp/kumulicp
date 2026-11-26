<?php

namespace App\Support\Volumes;

use Illuminate\Validation\Rule;

/**
 * The rules for a form that gives an app's chart a volume that already exists, under a name
 * chosen on purpose (a restore on another cluster comes back under its own claim, and a chart that
 * made a claim of the same name would collide with it). Which fields a volume can be given is what
 * the chart declares for it (VolumeSpec::$claim_mode).
 */
final class VolumeRules
{
    public const ACCESS_MODES = ['ReadWriteOnce', 'ReadWriteMany', 'ReadWriteOncePod', 'ReadOnlyMany'];

    // Names of claims and storage classes are DNS subdomain names
    private const NAME = '/^[a-z0-9]([-a-z0-9.]*[a-z0-9])?$/';

    /**
     * The rules for the fields under $prefix when the form is part of a bigger one ('storage.').
     *
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = ''): array
    {
        $rules = [
            // Not a default: moving to an empty volume has to be what was meant
            'volume' => ['required', Rule::in(['existing', 'new'])],
            'claim' => ['required_if:volume,existing', 'nullable', 'string', 'max:253', 'regex:'.self::NAME],
            'access_mode' => ['nullable', Rule::in(self::ACCESS_MODES)],
            'storage_class' => ['nullable', 'string', 'max:253', 'regex:'.self::NAME],
            'size' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];

        $prefixed = [];

        foreach ($rules as $field => $field_rules) {
            // A rule that names the other field names it where it now is
            $prefixed[$prefix.$field] = array_map(
                fn ($rule) => is_string($rule) ? str_replace('required_if:volume,', "required_if:{$prefix}volume,", $rule) : $rule,
                $field_rules,
            );
        }

        return $prefixed;
    }

    /**
     * The rules for this volume. One the chart takes a claim name for needs the name of an existing
     * volume's claim. One that is restored under the name its chart makes (a StatefulSet's) has no
     * name to give. One that can't be given either can only start with a new volume.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(VolumeSpec $spec, string $prefix = ''): array
    {
        $rules = self::rules($prefix);

        if ($spec->claim_mode === 'by_name') {
            $rules["{$prefix}claim"] = ['prohibited'];
        }

        if ($spec->claim_mode === 'none') {
            $rules["{$prefix}volume"] = ['required', Rule::in(['new'])];
            $rules["{$prefix}claim"] = ['prohibited'];
        }

        return $rules;
    }
}
