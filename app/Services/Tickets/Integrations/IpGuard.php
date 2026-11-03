<?php

namespace App\Services\Tickets\Integrations;

/**
 * Decides whether an address is somewhere on the public internet. Outgoing
 * integration requests are refused to anything else so a webhook URL cannot be
 * used to reach the cluster's internal services or cloud metadata endpoints.
 */
final class IpGuard
{
    /** Ranges PHP's private/reserved filters do not cover. */
    private const EXTRA_BLOCKED_V4 = [
        '100.64.0.0/10',   // carrier-grade NAT
        '192.0.0.0/24',    // IETF protocol assignments
        '198.18.0.0/15',   // benchmarking
        '224.0.0.0/4',     // multicast
    ];

    public static function isPublic(string $ip): bool
    {
        $ip = self::unwrap($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::EXTRA_BLOCKED_V4 as $range) {
                if (self::inRange($ip, $range)) {
                    return false;
                }
            }
        } else {
            // IPv6: unique-local, link-local, multicast and documentation ranges
            $packed = inet_pton($ip);
            $first = ord($packed[0]);
            $second = ord($packed[1]);

            if (($first & 0xFE) === 0xFC || $first === 0xFF || ($first === 0xFE && ($second & 0xC0) === 0x80) || ($first === 0x20 && $second === 0x01 && ord($packed[2]) === 0x0D && ord($packed[3]) === 0xB8)) {
                return false;
            }
        }

        return true;
    }

    /**
     * IPv6 forms that carry an IPv4 address (::ffff:10.0.0.1, 64:ff9b::a00:1)
     * are judged by the IPv4 address inside, so they cannot hide a private one.
     */
    private static function unwrap(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        $zeros = str_repeat("\0", 10);
        $mapped = substr($packed, 0, 10) === $zeros && substr($packed, 10, 2) === "\xff\xff";
        $nat64 = substr($packed, 0, 12) === "\x00\x64\xff\x9b".str_repeat("\0", 8);
        $compatible = substr($packed, 0, 12) === str_repeat("\0", 12) && substr($packed, 12) !== str_repeat("\0", 3)."\x01" && substr($packed, 12) !== str_repeat("\0", 4);

        if ($mapped || $nat64 || $compatible) {
            return (string) inet_ntop(substr($packed, 12, 4));
        }

        return $ip;
    }

    private static function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $mask = -1 << (32 - (int) $bits);

        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }
}
