<?php

namespace Database\Factories;

use App\AppChart;
use App\AppVersionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppVersionGroup>
 */
class AppVersionGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'app_chart_id' => AppChart::factory(),
            'name' => fake()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(['name' => 'Default', 'slug' => 'default', 'is_default' => true]);
    }
}
