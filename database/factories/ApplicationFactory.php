<?php

namespace Database\Factories;

use App\Application;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'name' => fake()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'short_description' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'enabled' => 1,
        ];
    }
}
