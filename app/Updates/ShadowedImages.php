<?php

namespace App\Updates;

use App\AppInstance;

/**
 * The settings of an instance that name a part of an image its version sets
 * itself (see AppVersion::extraImages()), and say something other than the
 * version does. The version's image is the one that is used: it is the one
 * that was tested and is checked for updates. An instance's setting, from
 * before the version had the image or made on purpose, is left in its settings
 * and ignored, so admins are told rather than left to find out.
 *
 * Only what the instance itself set counts: a plan seeds every default, which
 * says nothing about what anyone chose.
 */
class ShadowedImages
{
    private const FIELDS = ['registry', 'repository', 'tag', 'digest'];

    /**
     * @return array<int, array{image: string, setting: string, value: string, used: string}>
     */
    public function for(AppInstance $app_instance): array
    {
        $version = $app_instance->version;

        if (! $version) {
            return [];
        }

        $settings = (array) $app_instance->settings;
        $shadowed = [];

        foreach ($version->extraImages() as $image) {
            $path = trim($image->values_path, '. ');

            if ($path === '') {
                continue;
            }

            $used = ['registry' => $image->effectiveRegistry(), 'repository' => $image->repository, 'tag' => $image->effectiveTag(), 'digest' => $image->digest];

            foreach (self::FIELDS as $field) {
                // A configuration is named like the part of the values it sets: imaginary-image-tag
                $setting = str_replace('.', '-', $path.'.'.$field);
                $own = $settings['override'][$setting] ?? $settings['configurations'][$setting] ?? null;

                if (! is_scalar($own) || (string) $own === '' || (string) $own === (string) ($used[$field] ?? '')) {
                    continue;
                }

                $shadowed[] = [
                    'image' => $image->label ?: $image->key,
                    'setting' => $setting,
                    'value' => (string) $own,
                    'used' => (string) ($used[$field] ?? ''),
                ];
            }
        }

        return $shadowed;
    }
}
