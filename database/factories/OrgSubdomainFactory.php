<?php

namespace Database\Factories;

use App\OrgSubdomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrgSubdomain>
 */
class OrgSubdomainFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'host' => '@',
            'name' => fake()->domainName(),
            'type' => 'app',
            'status' => 'active',
        ];
    }
}
