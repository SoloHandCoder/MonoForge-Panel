<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Migration\M12LabsOAuthLinkImporterTest;

use Pterodactyl\Models\User;
use Pterodactyl\Models\UserOAuthAccount;
use Pterodactyl\Services\Migration\M12LabsOAuthLinkImporter;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

uses(HttpTestCase::class);

test('imports links for existing users and skips the rest', function (): void {
    $user = User::factory()->create();
    $taken = User::factory()->create();
    UserOAuthAccount::query()->create(['user_id' => $taken->id, 'provider' => 'discord', 'provider_user_id' => '999']);

    $rows = [
        ['user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => '111', 'provider_username' => 'nelly'],
        ['user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => '111'],
        (object) ['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => '999'],
        ['user_id' => $user->id + 1000, 'provider' => 'google', 'provider_user_id' => '222'],
        ['user_id' => $taken->id, 'provider' => 'discord', 'provider_user_id' => '333'],
        ['user_id' => $user->id, 'provider' => 'twitter', 'provider_user_id' => 'x'],
    ];

    expect((new M12LabsOAuthLinkImporter)->import($rows, false))->toBe([
        'imported' => 2,
        'already linked' => 1,
        'missing user' => 1,
        'conflict' => 1,
        'invalid' => 1,
    ]);
    expect(UserOAuthAccount::query()->where('user_id', $user->id)->orderBy('provider')->pluck('provider_user_id')->all())->toBe(['111', '999'])
        ->and(UserOAuthAccount::query()->where('provider_user_id', '111')->value('provider_username'))->toBe('nelly');
});

test('a dry run writes nothing', function (): void {
    $user = User::factory()->create();

    $counts = (new M12LabsOAuthLinkImporter)->import([['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => '1']], true);

    expect($counts['imported'])->toBe(1)->and(UserOAuthAccount::query()->count())->toBe(0);
});
