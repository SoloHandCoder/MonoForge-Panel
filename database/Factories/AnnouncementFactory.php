<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Pterodactyl\Enum\NoticeLevel;
use Pterodactyl\Models\Announcement;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(12),
            'level' => NoticeLevel::Info,
            'starts_at' => null,
            'ends_at' => null,
            'dismissible' => true,
        ];
    }
}
