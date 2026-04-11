<?php

namespace Database\Factories;

use App\OrgDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrgDomain>
 */
class OrgDomainFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'name' => fake()->domainName(),
            'organization_name' => fake()->name(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'address_1' => fake()->address(),
            'address_2' => '',
            'city' => fake()->city(),
            'state_province' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
            'country' => 'US',
            'phone' => '+1.1234567',
            'email_address' => fake()->email(),
            'type' => 'connection',
            'source' => 'namecheap',
            'status' => 'registering',
        ];
    }
}
