<?php

namespace App\Support\Security;

/**
 * Extracts Pod Security Admission warnings from `helm`/`kubectl` output.
 *
 * With a `warn` label on the namespace, the API server answers a request
 * that would create a violating pod with a warning, which the client prints
 * as e.g.
 *
 *   Warning: would violate PodSecurity "restricted:latest": allowPrivilegeEscalation != false (container "a" must set ...), runAsNonRoot != true (...)
 *   W1003 12:00:00.123456       1 warnings.go:70] would violate PodSecurity "restricted:latest": ...
 *
 * Pure: no framework dependencies.
 */
class PodSecurityWarnings
{
    /**
     * @return list<array{profile: string, violations: list<string>}> de-duplicated, in order of appearance
     */
    public static function parse(string $output): array
    {
        $warnings = [];

        if (! preg_match_all('/would violate PodSecurity "([^"]+)": (.+)$/m', $output, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as [, $profile, $detail]) {
            $warning = ['profile' => $profile, 'violations' => self::splitViolations(trim($detail))];

            if (! in_array($warning, $warnings, true)) {
                $warnings[] = $warning;
            }
        }

        return $warnings;
    }

    /**
     * The API server joins violations with ", ", but the parenthesised
     * detail of each one also contains commas, so only split at depth 0.
     *
     * @return list<string>
     */
    public static function splitViolations(string $detail): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $length = strlen($detail);

        for ($i = 0; $i < $length; $i++) {
            $char = $detail[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            }

            if ($depth === 0 && $char === ',' && ($detail[$i + 1] ?? '') === ' ') {
                $parts[] = trim($current);
                $current = '';
                $i++;

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }
}
