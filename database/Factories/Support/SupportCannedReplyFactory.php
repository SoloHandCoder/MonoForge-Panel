<?php

declare(strict_types=1);

namespace Database\Factories\Support;

use Illuminate\Database\Eloquent\Factories\Factory;
use Pterodactyl\Models\Support\SupportCannedReply;

/**
 * @extends Factory<SupportCannedReply>
 */
class SupportCannedReplyFactory extends Factory
{
    protected $model = SupportCannedReply::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'title' => fake()->words(3, true),
            'body' => fake()->sentence(15),
        ];
    }
}
