<?php

namespace App\Support\Volumes;

use App\AppInstance;
use Illuminate\Support\Arr;

/**
 * The admin's form for the volumes of one app instance: what it shows, what it accepts and what it writes.
 * It is built from the app's own volumes (the profile's `$volumes`), so it offers each volume only the fields
 * that can mean something for it. A field left blank follows the plan, then the profile.
 */
final class VolumesForm
{
    /**
     * What the page shows for each volume: the instance's own value (blank follows the plan) and the one in
     * effect.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fields(AppInstance $app_instance, Volumes $volumes): array
    {
        $settings = $app_instance->settings ?? [];

        return collect($volumes->all())->map(fn (Volume $volume, string $name) => [
            'name' => $name,
            'label' => $volume->label(),
            'optional' => $volume->optional(),
            'follows_quota' => $volume->followsQuota(),
            'takes_claim' => $volume->takesClaim(),
            'own' => Arr::only(Arr::get($settings, "volumes.{$name}", []), Volumes::FIELDS),
            'effective' => [
                'enabled' => $volume->enabled(),
                'size' => $volume->size(),
                'storage_class' => $volume->storageClass(),
                'access_mode' => $volume->accessMode(),
                'claim' => $volume->claim(),
            ],
            'access_modes' => VolumeRules::ACCESS_MODES,
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(Volumes $volumes): array
    {
        $names = array_keys($volumes->all());
        $rules = [
            'volumes' => ['required', 'array', function ($attribute, $value, $fail) use ($names) {
                if (array_diff(array_keys($value), $names)) {
                    $fail(__('admin.volumes.unknown'));
                }
            }],
        ];

        foreach ($volumes->all() as $name => $volume) {
            $own = VolumeRules::rules("volumes.{$name}.");

            $rules["volumes.{$name}"] = ['sometimes', 'array'];
            $rules["volumes.{$name}.storage_class"] = $own["volumes.{$name}.storage_class"];
            $rules["volumes.{$name}.access_mode"] = $own["volumes.{$name}.access_mode"];
            // The quota volume's size is the quota: it can't be set here, only grown by the subscription
            $rules["volumes.{$name}.size"] = $volume->followsQuota() ? ['prohibited'] : $own["volumes.{$name}.size"];
            $rules["volumes.{$name}.enabled"] = $volume->optional() ? ['nullable', 'boolean'] : ['prohibited'];
            // Here a claim is only set or cleared. Whether the volume is new or an old one is the move's to ask
            $rules["volumes.{$name}.claim"] = $volume->takesClaim()
                ? array_values(array_filter($own["volumes.{$name}.claim"], fn ($rule) => ! str_starts_with((string) $rule, 'required_if')))
                : ['prohibited'];
        }

        return $rules;
    }

    /**
     * Writes what was sent. A field that is not sent is left alone, and a blank one is forgotten.
     *
     * @param  array<string, array<string, mixed>>  $input  validated, by volume name
     */
    public static function apply(Volumes $volumes, array $input): void
    {
        foreach ($input as $name => $values) {
            $volumes->assign($name, Arr::only($values, Volumes::FIELDS));
        }
    }
}
