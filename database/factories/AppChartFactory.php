<?php

namespace Database\Factories;

use App\AppChart;
use App\Application;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppChart>
 */
class AppChartFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'application_id' => Application::factory(),
            'name' => fake()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'chart_name' => fake()->word(),
            'helm_repo' => 'https://charts.example.test',
            'is_default' => false,
            'enabled' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(['slug' => 'default', 'is_default' => true]);
    }
}
