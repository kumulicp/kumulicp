<?php

namespace Database\Factories;

use App\Tld;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tld>
 */
class TldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['com', 'net', 'org', 'io', 'co']),
            'default_driver' => 'namecheap',
        ];
    }
}
