<?php

namespace Database\Factories;

use App\Secret;
use App\SecretStore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Secret>
 */
class SecretFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'secret_store_id' => SecretStore::factory(),
            'path' => fake()->unique()->slug(),
            'key' => 'password',
            'value' => fake()->password(),
        ];
    }
}
