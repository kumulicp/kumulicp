<?php

namespace App\Support\Encryption;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;

/**
 * The current APP_KEY plus the retired keys from APP_PREVIOUS_KEYS, each with
 * its own encrypter, so a stored payload can be classified by which key reads it.
 *
 * Payloads are always handled as raw strings (no unserialize), so values written
 * with Crypt::encrypt() and Crypt::encryptString() are re-encrypted byte for byte.
 */
class KeyRing
{
    public const CURRENT = 'current';

    public const PREVIOUS = 'previous';

    public const UNDECRYPTABLE = 'undecryptable';

    public const PLAINTEXT = 'plaintext';

    private Encrypter $current;

    /** @var array<int, Encrypter> */
    private array $previous;

    /** @param array<int, string> $previous_keys */
    public function __construct(string $current_key, array $previous_keys, private string $cipher)
    {
        $this->current = new Encrypter(self::parseKey($current_key), $cipher);
        $this->previous = array_map(
            fn (string $key) => new Encrypter(self::parseKey($key), $cipher),
            $previous_keys
        );
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) config('app.key'),
            (array) config('app.previous_keys', []),
            config('app.cipher')
        );
    }

    public static function parseKey(string $key): string
    {
        return Str::startsWith($key, 'base64:') ? base64_decode(Str::after($key, 'base64:')) : $key;
    }

    /** Whether $value has the shape of a Laravel encrypted payload (base64 JSON with iv, value and mac). */
    public static function isPayload(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) < 60 || ! preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $value)) {
            return false;
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload)
            && isset($payload['iv'], $payload['value'], $payload['mac'])
            && is_string($payload['iv'])
            && is_string($payload['value'])
            && is_string($payload['mac']);
    }

    /**
     * @return array{status: string, plain: string|null}
     */
    public function inspect(string $value): array
    {
        if (! self::isPayload($value)) {
            return ['status' => self::PLAINTEXT, 'plain' => null];
        }

        try {
            return ['status' => self::CURRENT, 'plain' => $this->current->decrypt($value, false)];
        } catch (DecryptException) {
            //
        }

        foreach ($this->previous as $encrypter) {
            try {
                return ['status' => self::PREVIOUS, 'plain' => $encrypter->decrypt($value, false)];
            } catch (DecryptException) {
                //
            }
        }

        return ['status' => self::UNDECRYPTABLE, 'plain' => null];
    }

    /**
     * Re-encrypts a payload that only a previous key can read. Returns null when
     * it is already current, not a payload, or unreadable, and refuses to return
     * a replacement that doesn't read back as the same plaintext.
     */
    public function reencrypt(string $value): ?string
    {
        $inspection = $this->inspect($value);

        if ($inspection['status'] !== self::PREVIOUS) {
            return null;
        }

        $replacement = $this->current->encrypt($inspection['plain'], false);

        if ($this->current->decrypt($replacement, false) !== $inspection['plain']) {
            throw new DecryptException('Re-encrypted value did not read back with the current key.');
        }

        return $replacement;
    }
}
