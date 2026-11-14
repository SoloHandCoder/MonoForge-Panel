<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\NoticeControllerTest;

use Pterodactyl\Models\Announcement;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserNotice;
use Pterodactyl\Services\Notices\NoticeService;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);

test('the active scope keeps announcements whose schedule includes now', function (): void {
    $open = Announcement::factory()->create();
    $started = Announcement::factory()->create(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    Announcement::factory()->create(['starts_at' => now()->addHour()]);
    Announcement::factory()->create(['ends_at' => now()->subSecond()]);

    expect(Announcement::query()->active()->orderBy('id')->pluck('id')->all())->toBe([$open->id, $started->id]);
});

test("lists active announcements and only the user's unread notices", function (): void {
    $user = User::factory()->create();
    $announcement = Announcement::factory()->create(['level' => 'danger', 'dismissible' => false]);
    Announcement::factory()->create(['ends_at' => now()->subMinute()]);
    $notice = UserNotice::factory()->for($user)->create(['action_label' => 'Top up', 'action_url' => '/account']);
    UserNotice::factory()->for($user)->create(['read_at' => now()]);
    UserNotice::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/client/notices')->assertOk();

    $response->assertJsonPath('object', 'notice_feed')
        ->assertJsonCount(1, 'attributes.announcements')
        ->assertJsonPath('attributes.announcements.0.id', $announcement->id)
        ->assertJsonPath('attributes.announcements.0.level', 'danger')
        ->assertJsonPath('attributes.announcements.0.dismissible', false)
        ->assertJsonCount(1, 'attributes.notices')
        ->assertJsonPath('attributes.notices.0.id', $notice->id)
        ->assertJsonPath('attributes.notices.0.action_url', '/account');
});

test("marks only the user's own notices read", function (): void {
    $user = User::factory()->create();
    $own = UserNotice::factory()->for($user)->create();
    $other = UserNotice::factory()->create();

    $this->actingAs($user)->postJson("/api/client/notices/{$other->id}/read")->assertNotFound();
    $this->actingAs($user)->postJson("/api/client/notices/{$own->id}/read")->assertNoContent();

    expect($own->refresh()->read_at)->not->toBeNull()
        ->and($other->refresh()->read_at)->toBeNull();
});

test('requires a signed in user', function (): void {
    $this->getJson('/api/client/notices')->assertUnauthorized();
});

test('the sign-in pages get the active announcements without signing in', function (): void {
    $announcement = Announcement::factory()->create();
    Announcement::factory()->create(['starts_at' => now()->addDay()]);
    UserNotice::factory()->create();

    $this->getJson('/api/notices/public')->assertOk()
        ->assertJsonCount(1, 'attributes.announcements')
        ->assertJsonPath('attributes.announcements.0.id', $announcement->id)
        ->assertJsonCount(0, 'attributes.notices');
});

test('notify replaces an unread notice of the same kind instead of stacking it', function (): void {
    $user = User::factory()->create();
    $service = $this->app->make(NoticeService::class);

    $first = $service->notify($user, 'warning', 'Low balance', 'Two days left.', 'billing.low_balance');
    $second = $service->notify($user, 'danger', 'Low balance', 'One day left.', 'billing.low_balance', 'Top up', '/account');

    expect($second->id)->toBe($first->id)
        ->and($user->notices()->count())->toBe(1)
        ->and($second->refresh()->body)->toBe('One day left.');

    // Once read, the next notice of that kind is a new one; notices without a kind always stack.
    $second->forceFill(['read_at' => now()])->save();
    $third = $service->notify($user, 'danger', 'Low balance', 'Out of balance.', 'billing.low_balance');
    $service->notify($user, 'info', 'Hello', 'One.');
    $service->notify($user, 'info', 'Hello', 'Two.');

    expect($third->id)->not->toBe($first->id)
        ->and($user->notices()->count())->toBe(4)
        ->and($user->notices()->unread()->count())->toBe(3);
});
