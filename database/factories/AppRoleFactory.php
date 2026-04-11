<?php

namespace Database\Factories;

use App\AppRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AppRole>
 */
class AppRoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        $name = fake()->firstName();

        return [
            'name' => $name,
            'slug' => Str::lower($name),
            'description' => $name,
            'category' => 'Default',
            'access_type' => 'minimal',
            'status' => 'enabled',
        ];
    }
}
