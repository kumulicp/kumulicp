<?php

namespace Database\Factories;

use App\PullSecret;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PullSecret>
 */
class PullSecretFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'name' => fake()->unique()->domainWord(),
            'registry' => fake()->domainName(),
            'username' => null,
            'password' => null,
        ];
    }
}
