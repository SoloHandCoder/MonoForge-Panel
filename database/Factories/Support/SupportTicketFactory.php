<?php

declare(strict_types=1);

namespace Database\Factories\Support;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Pterodactyl\Enum\SupportTicketStatus;
use Pterodactyl\Models\Support\SupportTicket;
use Pterodactyl\Models\User;

/**
 * @extends Factory<SupportTicket>
 */
class SupportTicketFactory extends Factory
{
    protected $model = SupportTicket::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'server_id' => null,
            'category_id' => null,
            'assigned_to' => null,
            'subject' => fake()->sentence(5),
            'status' => SupportTicketStatus::Open,
            'last_message_at' => now(),
            'last_message_by_staff' => false,
            'closed_at' => null,
        ];
    }

    public function closed(?DateTimeInterface $at = null): static
    {
        return $this->state(fn (): array => ['status' => SupportTicketStatus::Closed, 'closed_at' => $at ?? now()]);
    }
}
