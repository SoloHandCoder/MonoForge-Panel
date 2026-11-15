<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\FriendControllerTest;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Pterodactyl\Console\Scheduler;
use Pterodactyl\Models\FriendRequest;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Friends\FriendService;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);

function befriend(User $one, User $two): void
{
    $now = now();
    $one->friends()->attach($two->id, ['created_at' => $now, 'updated_at' => $now]);
    $two->friends()->attach($one->id, ['created_at' => $now, 'updated_at' => $now]);
}

test('a request answers the same whether or not the user exists', function (string $kind): void {
    $sender = User::factory()->create();
    $target = User::factory()->create(['username' => 'existing', 'email' => 'existing@example.com']);
    $identifier = match ($kind) {
        'username' => 'Existing',
        'email' => 'EXISTING@example.com',
        'unknown username' => 'nobody',
        'unknown email' => 'nobody@example.com',
    };

    $response = $this->actingAs($sender)->postJson('/api/client/account/friends/requests', ['user' => $identifier]);

    $response->assertAccepted()->assertExactJson(['message' => FriendService::SENT_MESSAGE]);
    // Whatever was typed is listed back, and only that, so the list shows nothing either way.
    $this->actingAs($sender)->getJson('/api/client/account/friends')
        ->assertOk()
        ->assertJsonCount(1, 'attributes.outgoing')
        ->assertJsonPath('attributes.outgoing.0.identifier', mb_strtolower($identifier))
        ->assertJsonMissingPath('attributes.outgoing.0.username')
        ->assertJsonMissingPath('attributes.outgoing.0.uuid');

    $exists = str_starts_with($kind, 'unknown') === false;
    expect($target->notices()->unread()->exists())->toBe($exists)
        ->and(FriendRequest::query()->sole()->recipient_id)->toBe($exists ? $target->id : null);
})->with(['username', 'email', 'unknown username', 'unknown email']);

test('the recipient sees the request and a notice, and accepting makes both friends', function (): void {
    $sender = User::factory()->create(['username' => 'sender']);
    $recipient = User::factory()->create(['username' => 'recipient']);
    $this->actingAs($sender)->postJson('/api/client/account/friends/requests', ['user' => 'recipient'])->assertAccepted();

    $notice = $recipient->notices()->unread()->sole();
    expect($notice->action_url)->toBe('/account/friends')
        ->and($notice->body)->toContain('sender');

    $list = $this->actingAs($recipient)->getJson('/api/client/account/friends')->assertOk();
    $list->assertJsonCount(1, 'attributes.incoming')->assertJsonPath('attributes.incoming.0.username', 'sender');
    $id = $list->json('attributes.incoming.0.id');

    $this->actingAs($recipient)->postJson("/api/client/account/friends/requests/{$id}/accept")->assertNoContent();

    expect($recipient->friends()->pluck('users.id')->all())->toBe([$sender->id])
        ->and($sender->friends()->pluck('users.id')->all())->toBe([$recipient->id])
        ->and(FriendRequest::query()->count())->toBe(0)
        ->and($notice->refresh()->read_at)->not->toBeNull()
        ->and($sender->notices()->unread()->sole()->title)->toBe('Friend request accepted');

    $this->actingAs($sender)->getJson('/api/client/account/friends')
        ->assertJsonPath('attributes.friends.0.username', 'recipient')
        ->assertJsonPath('attributes.friends.0.since', fn (mixed $since): bool => is_string($since) && $since !== '')
        ->assertJsonCount(0, 'attributes.outgoing');
    $this->assertActivityFor('user:friend.accept', $recipient, $recipient);
});

test('a declined request stays pending for the sender and is not asked again under another name', function (): void {
    $sender = User::factory()->create();
    $recipient = User::factory()->create(['username' => 'quiet', 'email' => 'quiet@example.com']);
    $this->actingAs($sender)->postJson('/api/client/account/friends/requests', ['user' => 'quiet'])->assertAccepted();
    $request = FriendRequest::query()->sole();

    $this->actingAs($recipient)->postJson("/api/client/account/friends/requests/{$request->id}/decline")->assertNoContent();

    $this->actingAs($recipient)->getJson('/api/client/account/friends')->assertJsonCount(0, 'attributes.incoming');
    $this->actingAs($sender)->getJson('/api/client/account/friends')->assertJsonCount(1, 'attributes.outgoing');
    expect($recipient->notices()->unread()->exists())->toBeFalse();

    $this->actingAs($sender)->postJson('/api/client/account/friends/requests', ['user' => 'quiet@example.com'])->assertAccepted();

    $this->actingAs($sender)->getJson('/api/client/account/friends')->assertJsonCount(2, 'attributes.outgoing');
    $this->actingAs($recipient)->getJson('/api/client/account/friends')->assertJsonCount(0, 'attributes.incoming');
    expect($recipient->notices()->unread()->exists())->toBeFalse();
});

test('asking someone who already asked you makes you friends', function (): void {
    $one = User::factory()->create(['username' => 'one']);
    $two = User::factory()->create(['username' => 'two']);
    $this->actingAs($one)->postJson('/api/client/account/friends/requests', ['user' => 'two'])->assertAccepted();
    $this->actingAs($two)->postJson('/api/client/account/friends/requests', ['user' => 'one'])->assertAccepted();

    expect($one->friends()->pluck('users.id')->all())->toBe([$two->id])
        ->and(FriendRequest::query()->count())->toBe(0);
});

test('requests to yourself, friends or the same name twice store nothing new', function (): void {
    $sender = User::factory()->create(['username' => 'me']);
    $friend = User::factory()->create(['username' => 'pal']);
    befriend($sender, $friend);

    foreach (['me', 'pal', 'ghost', 'ghost'] as $identifier) {
        $this->actingAs($sender)->postJson('/api/client/account/friends/requests', ['user' => $identifier])->assertAccepted();
    }

    expect(FriendRequest::query()->pluck('identifier')->all())->toBe(['ghost']);
});

test('only the recipient can answer a request and only the sender can cancel it', function (): void {
    $sender = User::factory()->create();
    $recipient = User::factory()->create(['username' => 'target']);
    $stranger = User::factory()->create();
    $this->actingAs($sender)->postJson('/api/client/account/friends/requests', ['user' => 'target'])->assertAccepted();
    $id = FriendRequest::query()->sole()->id;

    $this->actingAs($stranger)->postJson("/api/client/account/friends/requests/{$id}/accept")->assertNotFound();
    $this->actingAs($sender)->postJson("/api/client/account/friends/requests/{$id}/accept")->assertNotFound();
    $this->actingAs($recipient)->deleteJson("/api/client/account/friends/requests/{$id}")->assertNotFound();
    $this->actingAs($stranger)->deleteJson("/api/client/account/friends/requests/{$id}")->assertNotFound();

    $this->actingAs($sender)->deleteJson("/api/client/account/friends/requests/{$id}")->assertNoContent();
    expect(FriendRequest::query()->exists())->toBeFalse()
        ->and($recipient->notices()->unread()->exists())->toBeFalse();
});

test('removing a friend ends the friendship for both', function (): void {
    $user = User::factory()->create();
    $friend = User::factory()->create();
    $other = User::factory()->create();
    befriend($user, $friend);

    $this->actingAs($user)->deleteJson("/api/client/account/friends/{$other->uuid}")->assertNotFound();
    $this->actingAs($user)->deleteJson("/api/client/account/friends/{$friend->uuid}")->assertNoContent();

    expect($user->friends()->exists())->toBeFalse()->and($friend->friends()->exists())->toBeFalse();
    $this->assertActivityFor('user:friend.remove', $user, $user);
});

test('a sender can only have so many requests waiting', function (): void {
    $sender = User::factory()->create();
    foreach (range(1, FriendRequest::MAX_PENDING) as $index) {
        $sender->sentFriendRequests()->create(['identifier' => "name{$index}"]);
    }

    $this->actingAs($sender)->postJson('/api/client/account/friends/requests', ['user' => 'one-more'])
        ->assertStatus(400)
        ->assertJsonPath('errors.0.detail', 'You have too many friend requests waiting. Cancel some before sending more.');
});

test('friend requests are rate limited', function (): void {
    $sender = User::factory()->create();

    foreach (range(1, 5) as $index) {
        $this->actingAs($sender)->postJson('/api/client/account/friends/requests', ['user' => "name{$index}"])->assertAccepted();
    }

    $this->actingAs($sender)->postJson('/api/client/account/friends/requests', ['user' => 'name6'])->assertTooManyRequests();
});

test('a request needs a username or email', function (): void {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/client/account/friends/requests', ['user' => ''])
        ->assertUnprocessable();
});

test('requests nobody answered for 30 days are pruned daily; declined ones stay', function (): void {
    $sender = User::factory()->create();
    $old = FriendRequest::query()->create(['sender_id' => $sender->id, 'identifier' => 'old']);
    $old->forceFill(['created_at' => CarbonImmutable::now()->subDays(31)])->save();
    $recent = FriendRequest::query()->create(['sender_id' => $sender->id, 'identifier' => 'recent']);
    $recent->forceFill(['created_at' => CarbonImmutable::now()->subDays(29)])->save();
    $declined = FriendRequest::query()->create(['sender_id' => $sender->id, 'identifier' => 'declined', 'declined_at' => CarbonImmutable::now()->subDays(40)]);
    $declined->forceFill(['created_at' => CarbonImmutable::now()->subDays(45)])->save();

    $this->artisan('model:prune', ['--model' => [FriendRequest::class]])->assertSuccessful();

    expect(FriendRequest::query()->orderBy('identifier')->pluck('identifier')->all())->toBe(['declined', 'recent']);

    $schedule = resolve(Schedule::class);
    (new Scheduler)($schedule);
    $event = collect($schedule->events())->first(fn (Event $event): bool => str_contains((string) $event->command, 'model:prune') && str_contains((string) $event->command, 'FriendRequest'));
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});
