<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Pterodactyl\Models\PermissionTemplate;

/**
 * @extends Factory<PermissionTemplate>
 */
class PermissionTemplateFactory extends Factory
{
    /**
     * Define the model's default state: a global template.
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'name' => fake()->unique()->words(2, true),
            'permissions' => ['control.console', 'file.read'],
        ];
    }
}
