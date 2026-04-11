<?php

namespace Database\Factories;

use App\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;
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
            'name' => fake()->word(),
            'slug' => Str::lower(Str::random(5)),
            'description' => fake()->sentence(),
            'email' => fake()->email(),
            'api_token' => Crypt::encrypt(Str::random(20)),
            'secretpw' => Crypt::encrypt(Str::random(20)),
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
            'status' => 'active',
        ];
    }
}
