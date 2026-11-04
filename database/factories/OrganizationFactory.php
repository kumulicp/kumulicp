<?php

namespace Database\Factories;

use App\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'primary_domain_id' => 0,
            'name' => fake()->word(),
            'slug' => Str::lower(Str::random(5)),
            'description' => fake()->sentence(),
            'email' => fake()->email(),
            // Plain values: the model's `hashed` / `encrypted` casts do the rest. Wrapping
            // them in Crypt here encrypts twice and overflows the varchar(255) column.
            'api_token' => Str::random(60),
            'secretpw' => Str::random(20),
            'phone_number' => fake()->phoneNumber(),
            'street' => fake()->address(),
            'zipcode' => fake()->postcode(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'country' => 'US',
            'contact_first_name' => fake()->firstName(),
            'contact_last_name' => fake()->lastName,
            'contact_phone_number' => fake()->phoneNumber(),
            'contact_email' => fake()->email(),
            'settings' => '{}',
            'status' => 'active',
        ];
    }
}
