<?php

namespace App\Integrations\Registrars\Namecheap\Helpers;

/**
 * Namecheap wants a contact phone as "+NNN.NNNNNNNNNN": a plus, a country code of one to three digits, a dot and
 * the national number, digits only. What users type, and what the country list holds, is looser: "(604) 555-0100",
 * "+1 604 555 0100", "07700 900123" with +44, and codes with an area part such as "+1 684" (American Samoa) or
 * "+7 840" (Abkhazia), which are really country code 1 or 7 with the area digits leading the number.
 */
class PhoneNumber
{
    // National numbers that keep their leading 0 in international form: Italy.
    private const KEEPS_LEADING_ZERO = ['39'];

    public static function format(?string $country_code, ?string $number): string
    {
        // "+1 684" is country code 1 and an area part 684
        $parts = preg_split('/[\s\-.]+/', trim((string) $country_code), -1, PREG_SPLIT_NO_EMPTY);
        $code = preg_replace('/\D/', '', $parts[0] ?? '');
        $area = preg_replace('/\D/', '', implode('', array_slice($parts, 1)));

        $number = (string) $number;

        // An extension cannot be sent in this format
        $number = preg_replace('/\s*(?:ext\.?|x)\s*\d+\s*$/i', '', $number);
        $international = (bool) preg_match('/^\s*(?:\+|00)/', $number);
        $national = preg_replace('/\D/', '', $number);

        if ($international) {
            $national = preg_replace('/^00/', '', $national);
            if ($code !== '' && str_starts_with($national, $code)) {
                $national = substr($national, strlen($code));
            }
        } elseif ($code === '1' && strlen($national) === 11 && str_starts_with($national, '1')) {
            // 1-604-555-0100
            $national = substr($national, 1);
        } elseif ($code !== '' && $code !== '1' && ! in_array($code, self::KEEPS_LEADING_ZERO, true) && str_starts_with($national, '0')) {
            // The national trunk prefix: 07700 900123 is +44 7700 900123
            $national = substr($national, 1);
        }

        // A number that already starts with the area part, or is already a complete 10 digit NANP number, is left alone
        if ($area !== '' && ! str_starts_with($national, $area) && ! ($code === '1' && strlen($national) >= 10)) {
            $national = $area.$national;
        }

        return $national === '' ? '' : '+'.$code.'.'.$national;
    }
}
