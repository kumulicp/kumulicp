<?php

namespace Database\Factories;

use App\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    public function definition()
    {
        return [
            'title' => $this->faker->sentence(4),
            'short_description' => $this->faker->sentence(10),
            'description' => '<p>'.$this->faker->paragraph().'</p>',
            'affected_apps' => null,
            'tags' => null,
        ];
    }
}
