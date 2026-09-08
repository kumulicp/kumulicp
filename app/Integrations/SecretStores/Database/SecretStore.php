<?php

namespace App\Integrations\SecretStores\Database;

use App\Contracts\SecretStore\SecretStoreContract;
use App\Secret;
use App\SecretStore as SecretStoreModel;

class SecretStore implements SecretStoreContract
{
    public function __construct(
        protected SecretStoreModel $store,
    ) {}

    public function get(string $path, string $key): ?string
    {
        return $this->find($path, $key)?->value;
    }

    public function put(string $path, string $key, string $value): void
    {
        Secret::updateOrCreate(
            ['secret_store_id' => $this->store->id, 'path' => $path, 'key' => $key],
            ['value' => $value]
        );
    }

    public function has(string $path, string $key): bool
    {
        return $this->find($path, $key) !== null;
    }

    public function delete(string $path, string $key): void
    {
        $this->find($path, $key)?->delete();
    }

    public function getOrCreate(string $path, string $key, \Closure $generator): string
    {
        $secret = $this->find($path, $key);

        if (! $secret) {
            $secret = Secret::create([
                'secret_store_id' => $this->store->id,
                'path' => $path,
                'key' => $key,
                'value' => $generator(),
            ]);
        }

        return $secret->value;
    }

    public function testConnection(): bool
    {
        return true;
    }

    protected function find(string $path, string $key): ?Secret
    {
        return Secret::where('secret_store_id', $this->store->id)
            ->where('path', $path)
            ->where('key', $key)
            ->first();
    }
}
