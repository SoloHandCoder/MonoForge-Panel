<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Console\PruneAntiAbuseHashesTest;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Console\Commands\Billing\PruneAntiAbuseHashesCommand;
use Pterodactyl\Console\Scheduler;
use Pterodactyl\Models\User;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

uses(IntegrationTestCase::class, DatabaseTransactions::class);

test('network and linked-account hashes are cleared after 12 months, the rows stay', function (): void {
    $old = User::factory()->create(['created_at' => now()->subMonths(13)]);
    $new = User::factory()->create();
    DB::table('users')->whereIn('id', [$old->id, $new->id])->update(['signup_subnet_hash' => str_repeat('a', 64)]);

    $trial = fn (User $user, string $at): int => DB::table('shop_trials')->insertGetId([
        'user_id' => $user->id, 'hours' => 48, 'ends_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        'subnet_hash' => str_repeat('b', 64), 'signup_subnet_hash' => str_repeat('c', 64), 'discord_hash' => str_repeat('d', 64), 'google_hash' => null,
    ]);
    $oldTrial = $trial($old, now()->subMonths(13)->toDateTimeString());
    $newTrial = $trial($new, now()->subMonth()->toDateTimeString());

    $referral = DB::table('referrals')->insertGetId([
        'referrer_id' => $new->id, 'referred_id' => $old->id, 'status' => 'paid', 'network_hash' => str_repeat('e', 64),
        'discord_hash' => str_repeat('f', 64), 'created_at' => now()->subMonths(13), 'updated_at' => now(),
    ]);

    $this->artisan('p:billing:prune-abuse-hashes')->assertSuccessful();

    expect(DB::table('users')->where('id', $old->id)->value('signup_subnet_hash'))->toBeNull()
        ->and(DB::table('users')->where('id', $new->id)->value('signup_subnet_hash'))->toBe(str_repeat('a', 64))
        ->and((array) DB::table('shop_trials')->where('id', $oldTrial)->first(['subnet_hash', 'signup_subnet_hash', 'discord_hash']))->toBe(['subnet_hash' => null, 'signup_subnet_hash' => null, 'discord_hash' => null])
        ->and(DB::table('shop_trials')->where('id', $newTrial)->value('discord_hash'))->toBe(str_repeat('d', 64))
        ->and((array) DB::table('referrals')->where('id', $referral)->first(['status', 'network_hash', 'discord_hash']))->toBe(['status' => 'paid', 'network_hash' => null, 'discord_hash' => null]);
});

test('the prune runs daily on one server', function (): void {
    $schedule = new Schedule;
    (new Scheduler)($schedule);

    $event = collect($schedule->events())->first(fn (Event $event): bool => str_contains((string) $event->command, 'p:billing:prune-abuse-hashes'));
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and(PruneAntiAbuseHashesCommand::RETENTION_MONTHS)->toBe(12);
});
