<?php

namespace Database\Factories;

use App\AppVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppVersion>
 */
class AppVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'name' => '1.0',
            'roles' => [],
            'status' => 'active',
            'settings' => [
                'repo_name' => fake()->name(),
                'chart_version' => '1.1.1',
            ],
        ];
    }
}
