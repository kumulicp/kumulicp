<?php

namespace Database\Factories;

use App\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
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
            'description' => Str::random(50),
            'app_plans' => [
                'nextcloud' => ['max' => '1', 'plans' => 'enabled'],
                'wordpress' => ['max' => '1', 'plans' => 'enabled'],
            ],
            'settings' => [
                'base' => ['price' => null, 'storage' => null, 'price_id' => null],
                'basic' => [
                    'max' => null,
                    'name' => null,
                    'price' => null,
                    'amount' => null,
                    'storage' => null,
                    'price_id' => null,
                ],
                'email' => [
                    'max' => null,
                    'price' => null,
                    'storage' => null,
                    'price_id' => null,
                ],
                'storage' => [
                    'max' => null,
                    'price' => null,
                    'amount' => null,
                    'price_id' => null,
                ],
                'standard' => [
                    'max' => 2,
                    'price' => null,
                    'storage' => null,
                    'price_id' => null,
                ],
                'application' => ['max' => null, 'price' => null, 'price_id' => null],
            ],
        ];
    }
}
