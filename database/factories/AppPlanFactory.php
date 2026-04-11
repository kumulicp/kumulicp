<?php

namespace Database\Factories;

use App\AppPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppPlan>
 */
class AppPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'name' => fake()->word(),
            'description' => fake()->sentence(),
            'features' => '[{"name":"Price","description":"Free"}]',
            'settings' => [
                'base' => [
                    'max' => null,
                    'price' => null,
                    'storage' => null,
                    'price_id' => null,
                ],
                'basic' => [
                    'max' => null,
                    'name' => null,
                    'price' => null,
                    'amount' => null,
                    'storage' => null,
                    'price_id' => null,
                ],
                'storage' => [
                    'max' => null,
                    'price' => null,
                    'amount' => 5,
                    'price_id' => null,
                ],
                'features' => [
                ],
                'standard' => [
                    'max' => 1,
                    'price' => null,
                    'storage' => null,
                    'price_id' => null,
                ],
                'configurations' => [
                    'fake-config' => true,
                ],
            ],
        ];
    }
}
