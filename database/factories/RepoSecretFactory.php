<?php

namespace Database\Factories;

use App\RepoSecret;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepoSecret>
 */
class RepoSecretFactory extends Factory
{
    protected $model = RepoSecret::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'type' => RepoSecret::TYPE_IMAGE,
            'name' => fake()->unique()->domainWord(),
            'registry' => fake()->domainName(),
            'username' => null,
            'password' => null,
        ];
    }

    public function helm()
    {
        return $this->state(['type' => RepoSecret::TYPE_HELM]);
    }

    public function both()
    {
        return $this->state(['type' => RepoSecret::TYPE_BOTH]);
    }
}
