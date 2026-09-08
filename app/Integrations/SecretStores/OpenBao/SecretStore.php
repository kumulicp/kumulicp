<?php

namespace App\Integrations\SecretStores\OpenBao;

use App\Contracts\SecretStore\SecretStoreContract;
use App\Exceptions\ConnectionFailedException;
use App\SecretStore as SecretStoreModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Reads/writes secrets on an OpenBao (or Vault-compatible) server's KV v2
 * secrets engine, authenticating via AppRole. AppRole tokens are short-lived,
 * so the client token is cached for the duration of its lease rather than
 * re-authenticated on every call.
 */
class SecretStore implements SecretStoreContract
{
    public function __construct(
        protected SecretStoreModel $store,
    ) {}

    public function get(string $path, string $key): ?string
    {
        return $this->readAll($path)[$key] ?? null;
    }

    public function put(string $path, string $key, string $value): void
    {
        $data = $this->readAll($path);
        $data[$key] = $value;

        $this->writeAll($path, $data);
    }

    public function has(string $path, string $key): bool
    {
        return array_key_exists($key, $this->readAll($path));
    }

    public function delete(string $path, string $key): void
    {
        $data = $this->readAll($path);
        unset($data[$key]);

        if (empty($data)) {
            $this->client()->delete($this->dataUrl($path));

            return;
        }

        $this->writeAll($path, $data);
    }

    public function getOrCreate(string $path, string $key, \Closure $generator): string
    {
        $data = $this->readAll($path);

        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        $data[$key] = $generator();
        $this->writeAll($path, $data);

        return $data[$key];
    }

    public function testConnection(): bool
    {
        try {
            $this->token();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function mount(): string
    {
        return trim($this->store->mount_path ?: 'secret', '/');
    }

    protected function dataUrl(string $path): string
    {
        return rtrim((string) $this->store->address, '/')."/v1/{$this->mount()}/data/".ltrim($path, '/');
    }

    protected function readAll(string $path): array
    {
        $response = $this->client()->get($this->dataUrl($path));

        if ($response->status() === 404) {
            return [];
        }

        if ($response->failed()) {
            throw new ConnectionFailedException("OpenBao read failed for secret store [{$this->store->name}], path [{$path}]: {$response->body()}");
        }

        return $response->json('data.data') ?? [];
    }

    protected function writeAll(string $path, array $data): void
    {
        $response = $this->client()->post($this->dataUrl($path), ['data' => $data]);

        if ($response->failed()) {
            throw new ConnectionFailedException("OpenBao write failed for secret store [{$this->store->name}], path [{$path}]: {$response->body()}");
        }
    }

    protected function client()
    {
        return Http::withOptions(['verify' => ! app()->environment('local', 'testing')])
            ->withHeaders(['X-Vault-Token' => $this->token()]);
    }

    protected function token(): string
    {
        return Cache::remember("secret_store.{$this->store->id}.token", now()->addMinutes(30), function () {
            $response = Http::withOptions(['verify' => ! app()->environment('local', 'testing')])
                ->post(rtrim((string) $this->store->address, '/').'/v1/auth/approle/login', [
                    'role_id' => $this->store->role_id,
                    'secret_id' => $this->store->secret_id,
                ]);

            if ($response->failed()) {
                throw new ConnectionFailedException("OpenBao AppRole login failed for secret store [{$this->store->name}]: {$response->body()}");
            }

            $token = $response->json('auth.client_token');

            if (! $token) {
                throw new ConnectionFailedException("OpenBao AppRole login for secret store [{$this->store->name}] returned no client token");
            }

            return $token;
        });
    }
}
