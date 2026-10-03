<?php

namespace App\Support\Security;

use App\Organization;
use App\Server;
use App\Support\Facades\Settings;

/**
 * Resolves which security tier applies to an organization's namespace on a
 * given server, and what Pod Security labels that means.
 *
 * Everything is opt-in. A server's `security_mode` setting defaults to
 * `off`, and a plan's tier defaults to `none`; with both defaults this
 * returns "unmanaged" and the drivers behave exactly as they did before.
 *
 *   off      KumuliCP never touches the namespace's security labels
 *   managed  KumuliCP applies and reconciles labels for the plan's tier
 *   observe  For admins who enforce policy with another tool: KumuliCP never
 *            writes. Behaves like off today; reporting is still to come
 *            (see docs/k8s-security-plan.md, sections 2.2 and 2.5)
 */
class NamespaceSecurityPolicy
{
    public const MODE_OFF = 'off';

    public const MODE_MANAGED = 'managed';

    public const MODE_OBSERVE = 'observe';

    public const MODES = [self::MODE_OFF, self::MODE_MANAGED, self::MODE_OBSERVE];

    // Server setting (flat key, like the rest of Server::settings)
    public const MODE_SETTING = 'security_mode';

    // System setting holding admin-defined tiers as JSON: {key: {label, enforce, ...}}
    public const TIERS_SETTING = 'security_tiers';

    public static function mode(Server $server): string
    {
        // Not Server::setting(): that assumes the settings are an array, and
        // this runs on every app activation, including for servers whose
        // settings were stored as a plain string
        $settings = $server->settings;
        $mode = is_array($settings) ? ($settings[self::MODE_SETTING] ?? null) : null;

        return is_string($mode) && in_array($mode, self::MODES, true) ? $mode : self::MODE_OFF;
    }

    /**
     * Preset tiers plus any custom ones from system settings. A custom tier
     * can't shadow a preset.
     *
     * @return array<string, SecurityTier>
     */
    public static function tiers(): array
    {
        $tiers = SecurityTier::presets();

        $stored = json_decode((string) Settings::get(self::TIERS_SETTING, ''), true);

        if (is_array($stored)) {
            foreach ($stored as $key => $data) {
                if (is_string($key) && preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $key) && is_array($data) && ! isset($tiers[$key])) {
                    $tiers[$key] = SecurityTier::fromArray($key, $data);
                }
            }
        }

        return $tiers;
    }

    public static function tier(?string $key): SecurityTier
    {
        $tiers = self::tiers();

        return $tiers[$key ?: SecurityTier::NONE] ?? $tiers[SecurityTier::NONE];
    }

    /**
     * Admin-defined (non-preset) tiers, for the settings editor.
     *
     * @return array<string, SecurityTier>
     */
    public static function customTiers(): array
    {
        return array_filter(self::tiers(), fn (SecurityTier $tier) => ! $tier->preset);
    }

    public static function tierFor(Organization $organization): SecurityTier
    {
        return self::tier($organization->plan?->setting('security.tier'));
    }

    /**
     * What the namespace should look like, or null when KumuliCP shouldn't
     * touch it at all.
     *
     * @return array{tier: string, labels: array<string, string>}|null
     */
    public static function desired(Organization $organization, Server $server): ?array
    {
        if (self::mode($server) !== self::MODE_MANAGED) {
            return null;
        }

        $tier = self::tierFor($organization);

        return ['tier' => $tier->key, 'labels' => $tier->podSecurityLabels()];
    }
}
