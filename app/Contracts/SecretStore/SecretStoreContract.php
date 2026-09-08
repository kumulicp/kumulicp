<?php

namespace App\Contracts\SecretStore;

interface SecretStoreContract
{
    public function get(string $path, string $key): ?string;

    public function put(string $path, string $key, string $value): void;

    public function has(string $path, string $key): bool;

    public function delete(string $path, string $key): void;

    /**
     * Return the value at $path/$key, generating and persisting one via
     * $generator the first time it's requested. This is the primary entry
     * point apps/jobs should use for operational secrets (LDAP bind
     * password, initial admin password, database password, etc.) so a
     * given secret is generated once and reused thereafter, regardless of
     * which driver is backing the store.
     */
    public function getOrCreate(string $path, string $key, \Closure $generator): string;

    public function testConnection(): bool;
}
