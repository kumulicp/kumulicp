<?php

namespace App\Support;

/**
 * What a single-organization install calls things differently: an app plan is a
 * "profile" there, since nobody subscribes to it. The words live in
 * resources/lang/{locale}/single.php, one flat 'group.key' line each, and are laid
 * over the normal strings so the rest of the code keeps asking for the same keys.
 */
class SingleOrganizationTerms
{
    /**
     * Lay the single-organization strings over the translator, for the current
     * language and the fallback one.
     */
    public static function apply(?string $locale = null): void
    {
        $translator = app('translator');

        foreach (array_unique([$locale ?? app()->getLocale(), app()->getFallbackLocale()]) as $language) {
            $lines = self::lines($language);

            // A group that hasn't been read yet would be taken as just these lines,
            // hiding the rest of it, so read it first
            foreach (array_unique(array_map(fn (string $key) => explode('.', $key, 2)[0], array_keys($lines))) as $group) {
                $translator->load('*', $group, $language);
            }

            $translator->addLines($lines, $language);
        }
    }

    /**
     * @return array<string, string>
     */
    public static function lines(string $language): array
    {
        $path = app()->langPath("{$language}/single.php");

        return is_file($path) ? require $path : [];
    }
}
