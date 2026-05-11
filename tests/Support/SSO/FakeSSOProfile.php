<?php

namespace Tests\Support\SSO;

class FakeSSOProfile
{
    private array $interfaces = [
        'sso' => FakeSSO::class,
    ];

    public function interface(string $interface): ?string
    {
        return $this->interfaces[$interface] ?? null;
    }
}
