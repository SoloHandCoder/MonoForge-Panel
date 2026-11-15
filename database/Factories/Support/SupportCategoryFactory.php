<?php

declare(strict_types=1);

namespace Database\Factories\Support;

use Illuminate\Database\Eloquent\Factories\Factory;
use Pterodactyl\Models\Support\SupportCategory;

/**
 * @extends Factory<SupportCategory>
 */
class SupportCategoryFactory extends Factory
{
    protected $model = SupportCategory::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'sort' => 10,
        ];
    }
}
