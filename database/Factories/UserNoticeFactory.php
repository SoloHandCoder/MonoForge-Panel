<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Pterodactyl\Enum\NoticeLevel;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserNotice;

/**
 * @extends Factory<UserNotice>
 */
class UserNoticeFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'level' => NoticeLevel::Warning,
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(12),
            'action_label' => null,
            'action_url' => null,
            'kind' => null,
            'read_at' => null,
        ];
    }
}
