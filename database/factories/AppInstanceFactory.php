<?php

namespace Database\Factories;

use App\AppInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'api_password' => '',
            'status' => 'active',
        ];
    }
}
