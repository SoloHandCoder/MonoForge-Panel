<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Support\SupportTicketControllerTest;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Pterodactyl\Enum\SupportTicketStatus;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\Support\SupportCannedReply;
use Pterodactyl\Models\Support\SupportCategory;
use Pterodactyl\Models\Support\SupportMessage;
use Pterodactyl\Models\Support\SupportTicket;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserNotice;
use Pterodactyl\Notifications\SupportReplyNotification;
use Pterodactyl\Services\Support\SupportAttachmentQuota;
use Pterodactyl\Services\Support\SupportTicketService;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
});

/** A ticket opened by a player through the service, as the client API does. */
function openTicket(User $player, string $subject = 'Server will not start'): SupportTicket
{
    return resolve(SupportTicketService::class)->open($player, $subject, SupportCategory::query()->first(), null, 'It crashes on boot.', []);
}

dataset('supportEndpoints', fn (): array => [
    ['getJson', '/api/admin/support/summary'],
    ['getJson', '/api/admin/support/options'],
    ['getJson', '/api/admin/support/tickets'],
    ['getJson', '/api/admin/support/tickets/1'],
    ['patchJson', '/api/admin/support/tickets/1'],
    ['postJson', '/api/admin/support/tickets/1/messages'],
    ['getJson', '/api/admin/support/canned-replies'],
    ['postJson', '/api/admin/support/canned-replies'],
    ['getJson', '/api/admin/support/categories'],
    ['postJson', '/api/admin/support/categories'],
]);

test('refuses users who are not administrators', function (string $method, string $uri): void {
    $this->actingAsNonAdmin();

    $this->{$method}($uri)->assertForbidden();
})->with('supportEndpoints');

test('lists tickets with filters and the unread badge', function (): void {
    $player = User::factory()->create(['username' => 'steve']);
    $first = openTicket($player, 'World corrupted');
    $second = openTicket($player, 'Billing question');
    $second->forceFill(['assigned_to' => $this->getAdminUser()->id])->save();
    SupportTicket::factory()->closed()->create(['subject' => 'Old one']);

    $this->getJson('/api/admin/support/summary')->assertOk()->assertJsonPath('data.unread', 2);

    $all = $this->getJson('/api/admin/support/tickets?status=active')->assertOk();
    expect($all->json('data.*.id'))->toEqualCanonicalizing([$first->id, $second->id])
        ->and($all->json('data.0.unread'))->toBeTrue()
        ->and($all->json('data.0.user.username'))->toBe('steve');

    expect($this->getJson('/api/admin/support/tickets?assigned=me')->json('data.*.id'))->toBe([$second->id])
        ->and($this->getJson('/api/admin/support/tickets?assigned=none&status=open')->json('data.*.id'))->toBe([$first->id])
        ->and($this->getJson('/api/admin/support/tickets?search=corrupt')->json('data.*.id'))->toBe([$first->id])
        ->and($this->getJson('/api/admin/support/tickets?search=%23'.$second->id)->json('data.*.id'))->toBe([$second->id])
        ->and($this->getJson('/api/admin/support/tickets?search=%25')->json('data'))->toBe([]);

    // Opening a ticket marks it read for this staff member only.
    $this->getJson("/api/admin/support/tickets/{$first->id}")->assertOk();
    $this->getJson('/api/admin/support/summary')->assertOk()->assertJsonPath('data.unread', 1);

    // Tickets assigned to someone else do not count.
    $other = User::factory()->create(['root_admin' => true]);
    $second->forceFill(['assigned_to' => $other->id])->save();
    $this->getJson('/api/admin/support/summary')->assertOk()->assertJsonPath('data.unread', 0);
});

test('a staff reply answers the ticket and notifies the player in the panel and by email', function (): void {
    $player = User::factory()->create();
    $ticket = openTicket($player);

    $response = $this->post("/api/admin/support/tickets/{$ticket->id}/messages", [
        'body' => 'We restored your world.',
        'internal' => 'false',
        'attachments' => [UploadedFile::fake()->createWithContent('fix.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true))],
    ], ['Accept' => 'application/json'])->assertCreated();

    $response->assertJsonPath('data.status', 'answered')
        ->assertJsonPath('data.messages.1.is_staff', true)
        ->assertJsonPath('data.messages.1.is_internal', false)
        ->assertJsonCount(1, 'data.messages.1.attachments');

    expect(UserNotice::query()->where('user_id', $player->id)->where('kind', "support.reply.{$ticket->id}")->exists())->toBeTrue();
    Notification::assertSentTo($player, SupportReplyNotification::class, fn (SupportReplyNotification $mail): bool => $mail->ticketId === $ticket->id);
    $this->assertActivityOn('admin:support.reply');

    // The player sees the reply as unread.
    $this->actingAs($player)->getJson('/api/client/support/tickets')->assertJsonPath('data.0.unread', true);
});

test('opening or answering a ticket reads its panel notices', function (): void {
    $admin = $this->getAdminUser();
    $player = User::factory()->create();
    $ticket = openTicket($player);
    $staffNotice = fn () => UserNotice::query()->where('user_id', $admin->id)->where('kind', "support.staff.{$ticket->id}");

    expect($staffNotice()->whereNull('read_at')->exists())->toBeTrue();

    // Opening the ticket reads the administrator's notice; other notices stay unread.
    $other = $admin->notices()->create(['level' => 'info', 'title' => 'Other', 'body' => 'Unrelated.', 'kind' => 'support.staff.999']);
    $this->getJson("/api/admin/support/tickets/{$ticket->id}")->assertOk();

    expect($staffNotice()->whereNull('read_at')->exists())->toBeFalse()
        ->and($other->refresh()->read_at)->toBeNull();

    // The player's reply brings it back; answering reads it again.
    resolve(SupportTicketService::class)->replyAsUser($ticket->refresh(), $player, 'Still broken.', []);
    expect($staffNotice()->whereNull('read_at')->exists())->toBeTrue();

    $this->actingAs($admin)->postJson("/api/admin/support/tickets/{$ticket->id}/messages", ['body' => 'Looking now.'])->assertCreated();
    expect($staffNotice()->whereNull('read_at')->exists())->toBeFalse();

    // The player's notice about that reply is read once they open the ticket.
    $playerNotice = fn () => UserNotice::query()->where('user_id', $player->id)->where('kind', "support.reply.{$ticket->id}")->whereNull('read_at');
    expect($playerNotice()->exists())->toBeTrue();

    $this->actingAs($player)->getJson("/api/client/support/tickets/{$ticket->id}")->assertOk();
    expect($playerNotice()->exists())->toBeFalse();
});

test('an internal note changes nothing the player sees', function (): void {
    $player = User::factory()->create();
    $ticket = openTicket($player);

    $this->postJson("/api/admin/support/tickets/{$ticket->id}/messages", ['body' => 'Probably a mod conflict', 'internal' => true])
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.messages.1.is_internal', true);

    expect($ticket->refresh()->last_message_by_staff)->toBeFalse()
        ->and(UserNotice::query()->where('user_id', $player->id)->exists())->toBeFalse();
    Notification::assertNothingSentTo($player);
    $this->assertActivityOn('admin:support.note');

    $this->actingAs($player)->getJson("/api/client/support/tickets/{$ticket->id}")
        ->assertJsonCount(1, 'data.messages')
        ->assertJsonPath('data.unread', false);
});

test('assigns, recategorises and closes a ticket', function (): void {
    $ticket = openTicket(User::factory()->create());
    $staff = User::factory()->create(['root_admin' => true]);
    $category = SupportCategory::factory()->create(['name' => 'Modpacks']);

    $this->patchJson("/api/admin/support/tickets/{$ticket->id}", ['assigned_to' => $staff->id, 'category_id' => $category->id, 'status' => 'closed'])
        ->assertOk()
        ->assertJsonPath('data.assignee.username', $staff->username)
        ->assertJsonPath('data.category.name', 'Modpacks')
        ->assertJsonPath('data.status', 'closed');

    expect($ticket->refresh()->closed_at)->not->toBeNull();

    // Only administrators can be assigned.
    $player = User::factory()->create();
    $this->patchJson("/api/admin/support/tickets/{$ticket->id}", ['assigned_to' => $player->id])->assertUnprocessable();

    $this->patchJson("/api/admin/support/tickets/{$ticket->id}", ['assigned_to' => null, 'status' => 'open'])
        ->assertOk()->assertJsonPath('data.assignee', null)->assertJsonPath('data.closed_at', null);
    expect(ActivityLog::query()->where('event', 'admin:support.update')->count())->toBe(2);
});

test('a user reply notifies only the assigned staff member', function (): void {
    $player = User::factory()->create();
    $ticket = openTicket($player);
    $assignee = User::factory()->create(['root_admin' => true]);
    $ticket->forceFill(['assigned_to' => $assignee->id])->save();
    UserNotice::query()->delete();

    $this->actingAs($player)->postJson("/api/client/support/tickets/{$ticket->id}/messages", ['body' => 'Any news?'])->assertCreated();

    expect(UserNotice::query()->where('kind', "support.staff.{$ticket->id}")->pluck('user_id')->all())->toBe([$assignee->id]);
});

test('staff skip the daily image count but not the stored size limit', function (): void {
    $staff = $this->getAdminUser();
    $ticket = openTicket(User::factory()->create());
    $png = fn (): UploadedFile => UploadedFile::fake()->createWithContent('fix.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
    $earlier = SupportMessage::factory()->for($ticket, 'ticket')->create(['user_id' => $staff->id, 'is_staff' => true]);
    $add = function (int $size) use ($earlier): void {
        $earlier->attachments()->create(['path' => 'support/s.png', 'name' => 's.png', 'mime' => 'image/png', 'size' => $size, 'width' => 1, 'height' => 1]);
    };
    for ($i = 0; $i < SupportAttachmentQuota::DAILY_FILES; $i++) {
        $add(10);
    }

    $this->post("/api/admin/support/tickets/{$ticket->id}/messages", ['body' => 'Here is the fix.', 'attachments' => [$png()]], ['Accept' => 'application/json'])
        ->assertCreated();

    $add(SupportAttachmentQuota::MAX_BYTES);
    $this->post("/api/admin/support/tickets/{$ticket->id}/messages", ['body' => 'One more.', 'attachments' => [$png()]], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', 'attachments');
});

test('shows staff the attachments of internal notes', function (): void {
    $ticket = openTicket(User::factory()->create());
    $note = SupportMessage::factory()->for($ticket, 'ticket')->internal()->create();
    Storage::disk('local')->put('support/n.png', 'bytes');
    $attachment = $note->attachments()->create(['path' => 'support/n.png', 'name' => 'n.png', 'mime' => 'image/png', 'size' => 5, 'width' => 1, 'height' => 1]);

    $this->get("/api/admin/support/tickets/{$ticket->id}/attachments/{$attachment->id}")->assertOk();
    // Not through another ticket's URL.
    $other = openTicket(User::factory()->create());
    $this->get("/api/admin/support/tickets/{$other->id}/attachments/{$attachment->id}")->assertNotFound();
});

test('manages canned replies', function (): void {
    $created = $this->postJson('/api/admin/support/canned-replies', ['title' => 'Restart', 'body' => 'Please restart the server.'])
        ->assertCreated()->json('data');

    $this->putJson("/api/admin/support/canned-replies/{$created['id']}", ['title' => 'Restart first', 'body' => 'Restart, then tell us.'])
        ->assertOk()->assertJsonPath('data.title', 'Restart first');
    $this->getJson('/api/admin/support/canned-replies')->assertOk()->assertJsonPath('data.0.body', 'Restart, then tell us.');
    $this->deleteJson("/api/admin/support/canned-replies/{$created['id']}")->assertNoContent();

    expect(SupportCannedReply::query()->count())->toBe(0);
    $this->assertActivityOn('admin:support.canned-reply.delete');
});

test('manages categories and keeps tickets when one is deleted', function (): void {
    $created = $this->postJson('/api/admin/support/categories', ['name' => 'Modpacks', 'sort' => 5])->assertCreated()->json('data');
    $ticket = SupportTicket::factory()->create(['category_id' => $created['id']]);

    $this->putJson("/api/admin/support/categories/{$created['id']}", ['name' => 'Mods', 'sort' => 6])
        ->assertOk()->assertJsonPath('data.name', 'Mods')->assertJsonPath('data.tickets', 1);
    $this->deleteJson("/api/admin/support/categories/{$created['id']}")->assertNoContent();

    expect($ticket->refresh()->category_id)->toBeNull()
        ->and($ticket->status)->toBe(SupportTicketStatus::Open);
    $this->getJson('/api/admin/support/options')->assertOk()->assertJsonCount(4, 'data.categories');
});
