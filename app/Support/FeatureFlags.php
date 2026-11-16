<?php

namespace App\Support;

use App\Support\Facades\Tenancy;
use Illuminate\Support\Arr;
use OffloadProject\Toggle\Facades\Toggle;

/**
 * The feature toggles an admin can switch on or off under Settings → Features.
 *
 * The key is the name used by `Toggle::active()` and the `toggle:` route
 * middleware. The state itself lives in the `toggles` table. Values from the
 * `TOGGLE_*` env vars are the default for a flag with no stored value
 * (config/toggle.php), and the migration that creates the table copies the
 * enabled ones across.
 */
class FeatureFlags
{
    /**
     * Flags that only make sense with several organizations. A single-organization
     * install neither offers them nor reports them as on.
     */
    public const MULTI_TENANT_ONLY = ['sub-organizations', 'shared-apps'];

    /**
     * The flags that exist, keyed by name. The label and description of each
     * are in admin.settings.features.flags.
     *
     * @return array<string, array{beta: bool}>
     */
    public static function definitions(): array
    {
        $definitions = [
            'sub-organizations' => ['beta' => true],
            'app-backups' => ['beta' => true],
            'shared-apps' => ['beta' => false],
            'emails' => ['beta' => false],
            'domain-registrar' => ['beta' => false],
        ];

        return Tenancy::isSingle() ? Arr::except($definitions, self::MULTI_TENANT_ONLY) : $definitions;
    }

    /**
     * Every flag with its current state and its translated label and description.
     *
     * @return list<array{key: string, label: string, description: string, beta: bool, enabled: bool}>
     */
    public static function all(): array
    {
        $states = Toggle::all();
        $translationKey = fn (string $key) => 'admin.settings.features.flags.'.str_replace('-', '_', $key);

        $flags = [];
        foreach (self::definitions() as $key => $definition) {
            $flags[] = [
                'key' => $key,
                'label' => __($translationKey($key).'.label'),
                'description' => __($translationKey($key).'.description'),
                'beta' => $definition['beta'],
                'enabled' => (bool) ($states[$key] ?? false),
            ];
        }

        return $flags;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }

    public static function set(string $key, bool $enabled): void
    {
        $enabled ? Toggle::enable($key) : Toggle::disable($key);
    }
}
