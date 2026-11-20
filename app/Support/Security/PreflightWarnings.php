<?php

namespace App\Support\Security;

/**
 * Turns the `Warning` headers the API server returns for a dry-run change of a
 * namespace's `pod-security.kubernetes.io/enforce` label into the pods that
 * would violate the new level.
 *
 * The server sends a headline, then one warning per group of similar pods:
 *
 *   existing pods in namespace "demo" violate the new PodSecurity enforce level "baseline:latest"
 *   nextcloud-5d9f (and 1 other pod): allowPrivilegeEscalation != false (container "nextcloud" must set ...), runAsNonRoot != true (...)
 *
 * Pure: no framework dependencies.
 */
class PreflightWarnings
{
    private const HEADLINE = '/^existing pods in namespace "[^"]*" violate the new PodSecurity (\w+) level "([^"]+)"/';

    private const POD = '/^([^\s:(]+)(?: \(and (\d+) other pods?\))?: (.+)$/s';

    /**
     * @param  list<string>  $warnings  the text of each Warning header
     * @return array{headline: ?string, pods: list<array{pod: string, others: int, violations: list<string>}>, other: list<string>}
     */
    public static function parse(array $warnings): array
    {
        $result = ['headline' => null, 'pods' => [], 'other' => []];

        foreach ($warnings as $warning) {
            if (preg_match(self::HEADLINE, $warning)) {
                $result['headline'] = $warning;
            } elseif (preg_match(self::POD, $warning, $match)) {
                $result['pods'][] = [
                    'pod' => $match[1],
                    'others' => (int) $match[2],
                    'violations' => PodSecurityWarnings::splitViolations(trim($match[3])),
                ];
            } else {
                $result['other'][] = $warning;
            }
        }

        return $result;
    }

    /**
     * How many pods the warnings cover ("(and N other pods)" counts too).
     *
     * @param  array{pods: list<array{others: int}>}  $parsed
     */
    public static function podCount(array $parsed): int
    {
        return array_sum(array_map(fn ($pod) => 1 + $pod['others'], $parsed['pods']));
    }
}
