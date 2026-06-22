<?php

namespace App\Services;

class ServerTypeRegistry
{
    private array $types = [];

    public function register(string $key, string $label): void
    {
        $this->types[$key] = $label;
    }

    public function all(): array
    {
        $types = [];

        foreach ($this->types as $key => $label) {
            $types[] = ['value' => $key, 'text' => $label];
        }

        return $types;
    }

    public function keys(): array
    {
        return array_keys($this->types);
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->types);
    }
}
