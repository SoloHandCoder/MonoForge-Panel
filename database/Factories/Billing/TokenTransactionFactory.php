<?php

declare(strict_types=1);

namespace Database\Factories\Billing;

use Illuminate\Database\Eloquent\Factories\Factory;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;

/**
 * A ledger row on its own. Tests that need a matching balance credit through TokenWallet instead.
 *
 * @extends Factory<TokenTransaction>
 */
class TokenTransactionFactory extends Factory
{
    protected $model = TokenTransaction::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'amount' => 500,
            'balance_after' => 500,
            'type' => TokenTransaction::TYPE_PURCHASE,
            'reference_type' => 'tebex',
            'reference_id' => 'tbx-'.fake()->unique()->numerify('########'),
            'description' => 'Bought 500 tokens',
            'meta' => null,
        ];
    }
}
