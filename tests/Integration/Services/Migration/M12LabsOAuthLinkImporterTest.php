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
        ['user_id' => $user->id, 'user_email' => $user->email, 'provider' => 'discord', 'provider_user_id' => '111', 'provider_username' => 'nelly'],
        ['user_id' => $user->id, 'user_email' => $user->email, 'provider' => 'discord', 'provider_user_id' => '111'],
        (object) ['user_id' => $user->id, 'user_email' => mb_strtoupper($user->email), 'provider' => 'google', 'provider_user_id' => '999'],
        ['user_id' => $user->id + 1000, 'user_email' => 'gone@example.com', 'provider' => 'google', 'provider_user_id' => '222'],
        ['user_id' => $taken->id, 'user_email' => $taken->email, 'provider' => 'discord', 'provider_user_id' => '333'],
        ['user_id' => $user->id, 'user_email' => $user->email, 'provider' => 'twitter', 'provider_user_id' => 'x'],
    ];

    expect((new M12LabsOAuthLinkImporter)->import($rows, false))->toBe([
        'imported' => 2,
        'already linked' => 1,
        'missing user' => 1,
        'user mismatch' => 0,
        'conflict' => 1,
        'invalid' => 1,
    ]);
    expect(UserOAuthAccount::query()->where('user_id', $user->id)->orderBy('provider')->pluck('provider_user_id')->all())->toBe(['111', '999'])
        ->and(UserOAuthAccount::query()->where('provider_user_id', '111')->value('provider_username'))->toBe('nelly');
});

test('a link is never written to a different person who now has the same user id', function (): void {
    // The ID of an m12labs user now belongs to someone who signed up on MonoForge.
    $newcomer = User::factory()->create(['email' => 'newcomer@example.com']);

    $counts = (new M12LabsOAuthLinkImporter)->import([
        ['user_id' => $newcomer->id, 'user_email' => 'original-owner@example.com', 'provider' => 'discord', 'provider_user_id' => '111'],
        ['user_id' => $newcomer->id, 'provider' => 'google', 'provider_user_id' => '222'],
    ], false);

    expect($counts['user mismatch'])->toBe(2)
        ->and($counts['imported'])->toBe(0)
        ->and(UserOAuthAccount::query()->count())->toBe(0);
});

test('a dry run writes nothing', function (): void {
    $user = User::factory()->create();

    $counts = (new M12LabsOAuthLinkImporter)->import([['user_id' => $user->id, 'user_email' => $user->email, 'provider' => 'google', 'provider_user_id' => '1']], true);

    expect($counts['imported'])->toBe(1)->and(UserOAuthAccount::query()->count())->toBe(0);
});
