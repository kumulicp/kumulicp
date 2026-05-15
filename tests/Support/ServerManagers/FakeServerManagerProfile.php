<?php

namespace Tests\Support\ServerManagers;

class FakeServerManagerProfile
{
    private array $interfaces = [
        'web' => FakeServerManager::class,
    ];

    public function interface(string $interface): ?string
    {
        return $this->interfaces[$interface] ?? null;
    }
}
