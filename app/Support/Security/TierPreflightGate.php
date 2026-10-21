<?php

namespace App\Support\Security;

use App\Organization;
use App\Plan;
use Illuminate\Support\Collection;

/**
 * Stops a stricter `enforce` level from reaching namespaces that already hold
 * workloads it would block. Used when a plan moves to a stricter tier or a
 * custom tier's `enforce` level is raised; the admin can override.
 *
 * Only namespaces KumuliCP would actually relabel are checked: web servers
 * whose security_mode is `managed`.
 */
class TierPreflightGate
{
    // A synchronous admin request can't preflight an unbounded number of
    // namespaces; past this, ask for the batch command instead
    public const MAX_NAMESPACES = 25;

    /**
     * Organizations on a base plan that selects the given tier.
     *
     * @return Collection<int, Organization>
     */
    public static function organizationsOnTier(string $tier_key): Collection
    {
        return Plan::with('subscribers.servers.server')->get()
            ->filter(fn (Plan $plan) => $plan->setting('security.tier') === $tier_key)
            ->flatMap(fn (Plan $plan) => $plan->subscribers)
            ->unique('id')
            ->values();
    }

    /**
     * Preflights each affected namespace at the tier's enforce level. Every
     * check is recorded as a SecurityScan. A blocker is anything that would
     * break (violations) or couldn't be verified (error, too many to check).
     *
     * @param  iterable<Organization>  $organizations
     * @return list<array{organization: string, server: string, status: string, pods: int, detail: string}>
     */
    public static function blockers(iterable $organizations, SecurityTier $tier): array
    {
        if ($tier->enforce === null) {
            return [];
        }

        $blockers = [];
        $checked = 0;

        foreach ($organizations as $organization) {
            foreach ($organization->servers as $org_server) {
                $server = $org_server->server;

                if (! $server || $server->type !== 'web' || NamespaceSecurityPolicy::mode($server) !== NamespaceSecurityPolicy::MODE_MANAGED) {
                    continue;
                }

                if (++$checked > self::MAX_NAMESPACES) {
                    return [...$blockers, [
                        'organization' => '',
                        'server' => '',
                        'status' => 'too_many',
                        'pods' => 0,
                        'detail' => 'more than '.self::MAX_NAMESPACES.' namespaces; run `php artisan servers:preflight-pod-security '.$tier->enforce.'` and apply anyway',
                    ]];
                }

                $result = PodSecurityPreflight::check($organization, $org_server, $tier->enforce, $tier->enforceVersion);
                PodSecurityPreflight::record($org_server, $result, 'preflight');

                if (in_array($result['status'], ['violations', 'error'], true)) {
                    $blockers[] = [
                        'organization' => $organization->slug,
                        'server' => $server->name,
                        'status' => $result['status'],
                        'pods' => $result['pod_count'],
                        'detail' => $result['error'],
                    ];
                }
            }
        }

        return $blockers;
    }

    /**
     * "acme (3 pods), demo (couldn't check: forbidden)", capped at five entries.
     */
    public static function summary(array $blockers): string
    {
        $parts = array_map(fn ($blocker) => match ($blocker['status']) {
            'violations' => "{$blocker['organization']} ({$blocker['pods']} ".($blocker['pods'] === 1 ? 'pod' : 'pods').')',
            'error' => "{$blocker['organization']} (couldn't check: {$blocker['detail']})",
            default => $blocker['detail'],
        }, array_slice($blockers, 0, 5));

        $more = count($blockers) - count($parts);

        return implode(', ', $parts).($more > 0 ? " and {$more} more" : '');
    }
}
