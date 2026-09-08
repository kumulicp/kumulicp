<?php

namespace Database\Factories;

use App\SecretStore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecretStore>
 */
class SecretStoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'driver' => 'database',
            'is_default' => false,
            'address' => null,
            'role_id' => null,
            'secret_id' => null,
            'mount_path' => null,
            'namespace' => null,
            'status' => 'active',
        ];
    }

    public function openbao()
    {
        return $this->state(fn () => [
            'driver' => 'openbao',
            'address' => 'https://openbao.example.com',
            'role_id' => 'test-role-id',
            'secret_id' => 'test-secret-id',
            'mount_path' => 'secret',
        ]);
    }
}
