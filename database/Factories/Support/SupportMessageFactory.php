<?php

declare(strict_types=1);

namespace Database\Factories\Support;

use Illuminate\Database\Eloquent\Factories\Factory;
use Pterodactyl\Models\Support\SupportMessage;
use Pterodactyl\Models\Support\SupportTicket;

/**
 * @extends Factory<SupportMessage>
 */
class SupportMessageFactory extends Factory
{
    protected $model = SupportMessage::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'ticket_id' => SupportTicket::factory(),
            'user_id' => null,
            'is_staff' => false,
            'is_internal' => false,
            'body' => fake()->sentence(12),
        ];
    }

    /** A staff-only internal note. */
    public function internal(): static
    {
        return $this->state(fn (): array => ['is_staff' => true, 'is_internal' => true]);
    }
}
