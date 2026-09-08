<?php

namespace App\Support;

class Redactor
{
    protected const MASK = '••••••••';

    protected const SENSITIVE_KEY_PATTERN = '/password|passwd|secret|token|api[_-]?key|private[_-]?key|credential/i';

    // Recursively masks values whose array key looks sensitive (password, secret, token, etc).
    // Also handles Helm's {name, value} env-var pair shape, matching against the "name" field.
    public static function redact(mixed $value, string|int|null $key = null): mixed
    {
        if (is_array($value)) {
            if (self::isEnvVarPair($value)) {
                return [
                    'name' => $value['name'],
                    'value' => self::isSensitiveKey((string) $value['name']) ? self::MASK : $value['value'],
                ];
            }

            return collect($value)
                ->map(fn ($item, $itemKey) => self::redact($item, $itemKey))
                ->all();
        }

        if (is_string($key) && $value !== '' && $value !== null && self::isSensitiveKey($key)) {
            return self::MASK;
        }

        return $value;
    }

    protected static function isEnvVarPair(array $value): bool
    {
        return array_keys($value) === ['name', 'value'];
    }

    protected static function isSensitiveKey(string $key): bool
    {
        return (bool) preg_match(self::SENSITIVE_KEY_PATTERN, $key);
    }
}
