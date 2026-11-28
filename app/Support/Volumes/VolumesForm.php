<?php

namespace App\Support\Volumes;

use App\AppInstance;
use Illuminate\Support\Arr;

/**
 * The admin's form for the volumes of one app instance: what the page shows, what it accepts and what it writes.
 *
 * A volume's settings can be given while it is being enabled, and not after. Kubernetes does not change a
 * claim's class or access mode once it is made, and does not change the claim templates of a StatefulSet at all,
 * so a release that changed them would fail. A volume that is in use is shown as it is. The one thing that may be
 * changed is which existing claim the volume that holds the files uses, and only while the app can be released.
 */
final class VolumesForm
{
    /** Settings can be given only to a volume that is not in use yet */
    public static function locked(Volume $volume): bool
    {
        return $volume->enabled();
    }

    /** The claim can be changed for the volume that takes one, while it is in use and the app is not being worked on */
    public static function canChangeClaim(AppInstance $app_instance, Volume $volume): bool
    {
        return $volume->takesClaim() && $volume->enabled() && $app_instance->status === 'active';
    }

    /**
     * What the page shows for each volume.
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
            'locked' => self::locked($volume),
            'can_change_claim' => self::canChangeClaim($app_instance, $volume),
            // Blank follows the plan
            'own' => Arr::only(Arr::get($settings, "volumes.{$name}", []), ['enabled', 'size', 'storage_class', 'access_mode']),
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
     * The rules for giving a volume its settings: only for one that is not in use.
     *
     * @return array<string, mixed>
     */
    public static function settingsRules(Volume $volume): array
    {
        $rules = VolumeRules::rules();

        return [
            // A volume that is not in use is being enabled: that is when its settings are given
            'enabled' => ['required', 'accepted'],
            // The quota volume's size is the quota: it is not set here, and only grows with the subscription
            'size' => $volume->followsQuota() ? ['prohibited'] : $rules['size'],
            'storage_class' => $rules['storage_class'],
            'access_mode' => $rules['access_mode'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function claimRules(): array
    {
        $rules = VolumeRules::rules()['claim'];

        return ['claim' => ['required', ...array_filter($rules, fn ($rule) => ! in_array($rule, ['nullable'], true) && ! str_starts_with((string) $rule, 'required_if'))]];
    }
}
