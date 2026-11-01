<?php

namespace Database\Factories;

use App\AppInstance;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AppInstance>
 */
class AppInstanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'name' => fake()->unique()->slug(2),
            'api_password' => '',
            'db_password' => Str::random(32),
            'status' => 'active',
        ];
    }
}
