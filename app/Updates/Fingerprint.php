<?php

namespace App\Updates;

/**
 * A hash of some data that comes out the same whenever the data is the same:
 * associative keys are sorted before encoding, so the order things were stored
 * in doesn't matter. Only put stable data in; a timestamp or a counter makes a
 * fingerprint that never matches.
 */
class Fingerprint
{
    public static function of(mixed $data): string
    {
        return hash('sha256', json_encode(self::normalize($data)));
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn ($item) => self::normalize($item), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
