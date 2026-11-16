<?php

namespace Database\Factories;

use App\AppVersion;
use App\AppVersionImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppVersionImage>
 */
class AppVersionImageFactory extends Factory
{
    protected $model = AppVersionImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'app_version_id' => AppVersion::factory(),
            'key' => fake()->unique()->slug(2),
            'role' => AppVersionImage::ROLE_COMPONENT,
            'source' => AppVersionImage::SOURCE_OVERRIDE,
            'registry' => 'docker.io',
            'repository' => 'library/'.fake()->unique()->word(),
            'tag' => '1.0.0',
            'values_path' => 'image',
            'tag_from_version_name' => false,
            'update_policy' => 'track',
            'position' => 0,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => [
            'key' => AppVersionImage::PRIMARY_KEY,
            'role' => AppVersionImage::ROLE_PRIMARY,
        ]);
    }
}
