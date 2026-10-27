<?php

namespace App\Services\Application;

use App\AppPlan;
use App\Support\SecretValue;
use Illuminate\Support\Arr;

/**
 * The custom secrets of a plan: environment variable name => encrypted value,
 * set by admins and delivered to every instance on the plan (see
 * Chart::extraEnv()). Values never leave this class except for delivery:
 * the admin UI only ever gets the names.
 */
class PlanSecretsService
{
    public const SETTING = 'secrets';

    // An environment variable name
    public const NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function __construct(private AppPlan $plan) {}

    /** @return array<int, string> */
    public function names(): array
    {
        return array_keys($this->stored());
    }

    /** @return array<string, string> name => plaintext value, for delivery only */
    public function values(): array
    {
        return array_map(SecretValue::decrypt(...), $this->stored());
    }

    /**
     * Replaces the plan's secrets with $items ([['name' => ..., 'value' => ...]]).
     * A blank value keeps the stored one for an existing name; secrets not
     * listed are removed.
     */
    public function update(array $items): void
    {
        $stored = $this->stored();
        $secrets = [];

        foreach ($items as $item) {
            $name = $item['name'];
            $value = $item['value'] ?? null;

            if ($value !== null && $value !== '') {
                $secrets[$name] = SecretValue::encrypt($value);
            } elseif (isset($stored[$name])) {
                $secrets[$name] = $stored[$name];
            }
        }

        $this->plan->updateSetting(self::SETTING, $secrets);
    }

    private function stored(): array
    {
        return Arr::get($this->plan->settings, self::SETTING, []) ?: [];
    }
}
