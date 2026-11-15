<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\Support\SupportTicketControllerTest;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Pterodactyl\Enum\SupportTicketStatus;
use Pterodactyl\Models\Support\SupportAttachment;
use Pterodactyl\Models\Support\SupportCategory;
use Pterodactyl\Models\Support\SupportMessage;
use Pterodactyl\Models\Support\SupportTicket;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserNotice;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);

/** A real 1x1 PNG. */
function pngBytes(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
}

function png(string $name = 'shot.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, pngBytes());
}

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    RateLimiter::clear('support-ticket:');
});

function category(): SupportCategory
{
    return SupportCategory::query()->firstOrFail();
}

test('lists the seeded categories and only servers the user can access', function (): void {
    [$user, $server] = $this->generateTestAccount();
    [, $subuserServer] = $this->generateTestAccount();
    $subuserServer->subusers()->create(['user_id' => $user->id, 'permissions' => ['control.console']]);
    $this->createServerModel();

    $response = $this->actingAs($user)->getJson('/api/client/support/options')->assertOk();

    expect(collect($response->json('data.categories'))->pluck('name')->all())->toBe(['Billing', 'Server problem', 'Account', 'Other'])
        ->and(collect($response->json('data.servers'))->pluck('identifier')->sort()->values()->all())
        ->toBe(collect([$server->uuidShort, $subuserServer->uuidShort])->sort()->values()->all());
});

test('opens a ticket with an image, logs it and tells the staff', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $admin = User::factory()->create(['root_admin' => true]);

    $response = $this->actingAs($user)->post('/api/client/support/tickets', [
        'subject' => 'Server will not start',
        'category_id' => category()->id,
        'server' => $server->uuidShort,
        'body' => 'It stops after **Loading world**.',
        'attachments' => [png('../../evil<script>.png')],
    ], ['Accept' => 'application/json'])->assertCreated();

    $ticket = SupportTicket::query()->findOrFail($response->json('data.id'));
    expect($ticket->user_id)->toBe($user->id)
        ->and($ticket->server_id)->toBe($server->id)
        ->and($ticket->status)->toBe(SupportTicketStatus::Open);

    $response->assertJsonPath('data.server.identifier', $server->uuidShort)
        ->assertJsonPath('data.messages.0.body', 'It stops after **Loading world**.')
        ->assertJsonPath('data.messages.0.attachments.0.name', 'evilscript.png')
        ->assertJsonPath('data.unread', false);

    $attachment = SupportAttachment::query()->firstOrFail();
    expect($attachment->mime)->toBe('image/png')
        ->and($attachment->path)->toStartWith("support/{$ticket->id}/")
        ->and($attachment->path)->not->toContain('evil');
    Storage::disk('local')->assertExists($attachment->path);

    expect(UserNotice::query()->where('user_id', $admin->id)->where('kind', "support.staff.{$ticket->id}")->exists())->toBeTrue();
    $this->assertActivityOn('user:support.create', $user);
});

test('refuses a server the user cannot access', function (): void {
    $user = User::factory()->create();
    $other = $this->createServerModel();

    $this->actingAs($user)->postJson('/api/client/support/tickets', [
        'subject' => 'Help me',
        'category_id' => category()->id,
        'server' => $other->uuidShort,
        'body' => 'Please look at this server.',
    ])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'server');

    expect(SupportTicket::query()->count())->toBe(0);
});

test('refuses attachments that are not really images, too large or too many', function (): void {
    $user = User::factory()->create();
    $base = ['subject' => 'Help me', 'category_id' => category()->id, 'body' => 'Look at this.'];

    $fakePng = UploadedFile::fake()->createWithContent('shot.png', '<html><script>alert(1)</script></html>');
    $this->actingAs($user)->post('/api/client/support/tickets', [...$base, 'attachments' => [$fakePng]], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    $gif = UploadedFile::fake()->createWithContent('anim.png', (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true));
    $this->actingAs($user)->post('/api/client/support/tickets', [...$base, 'attachments' => [$gif]], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    $huge = UploadedFile::fake()->createWithContent('big.png', pngBytes().str_repeat("\0", 5 * 1024 * 1024));
    $this->actingAs($user)->post('/api/client/support/tickets', [...$base, 'attachments' => [$huge]], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    $this->actingAs($user)->post('/api/client/support/tickets', [...$base, 'attachments' => [png(), png(), png(), png()]], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    expect(SupportTicket::query()->count())->toBe(0);
});

test('limits a user to three new tickets an hour', function (): void {
    $user = User::factory()->create();
    $payload = ['subject' => 'Help me', 'category_id' => category()->id, 'body' => 'Look at this.'];

    // A rejected form does not use up an attempt.
    $this->actingAs($user)->postJson('/api/client/support/tickets', ['subject' => 'x'])->assertUnprocessable();

    foreach (range(1, 3) as $attempt) {
        $this->actingAs($user)->postJson('/api/client/support/tickets', $payload)->assertCreated();
    }

    $this->actingAs($user)->postJson('/api/client/support/tickets', $payload)->assertTooManyRequests();
    expect(SupportTicket::query()->count())->toBe(3);
});

test("never shows another user's ticket or attachment", function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $ticket = SupportTicket::factory()->for($owner)->create();
    $message = SupportMessage::factory()->for($ticket, 'ticket')->create(['user_id' => $owner->id]);
    Storage::disk('local')->put('support/x.png', pngBytes());
    $attachment = $message->attachments()->create(['path' => 'support/x.png', 'name' => 'x.png', 'mime' => 'image/png', 'size' => 10, 'width' => 1, 'height' => 1]);

    $this->actingAs($intruder)->getJson("/api/client/support/tickets/{$ticket->id}")->assertNotFound();
    $this->actingAs($intruder)->postJson("/api/client/support/tickets/{$ticket->id}/messages", ['body' => 'Hijack'])->assertNotFound();
    $this->actingAs($intruder)->postJson("/api/client/support/tickets/{$ticket->id}/close")->assertNotFound();
    $this->actingAs($intruder)->get("/api/client/support/tickets/{$ticket->id}/attachments/{$attachment->id}")->assertNotFound();

    // Nor an attachment of another ticket through one's own ticket.
    $own = SupportTicket::factory()->for($intruder)->create();
    $this->actingAs($intruder)->get("/api/client/support/tickets/{$own->id}/attachments/{$attachment->id}")->assertNotFound();

    expect($this->actingAs($intruder)->getJson('/api/client/support/tickets')->assertOk()->json('data.*.id'))->toBe([$own->id]);
});

test('hides internal notes and their attachments from the player', function (): void {
    $user = User::factory()->create();
    $ticket = SupportTicket::factory()->for($user)->create();
    SupportMessage::factory()->for($ticket, 'ticket')->create(['user_id' => $user->id, 'body' => 'Public']);
    $note = SupportMessage::factory()->for($ticket, 'ticket')->internal()->create(['body' => 'Staff only: user is a reseller']);
    Storage::disk('local')->put('support/note.png', pngBytes());
    $attachment = $note->attachments()->create(['path' => 'support/note.png', 'name' => 'note.png', 'mime' => 'image/png', 'size' => 10, 'width' => 1, 'height' => 1]);

    $response = $this->actingAs($user)->getJson("/api/client/support/tickets/{$ticket->id}")->assertOk();

    expect($response->json('data.messages.*.body'))->toBe(['Public'])
        ->and($response->getContent())->not->toContain('reseller')
        ->and($response->json('data'))->not->toHaveKeys(['assignee', 'user']);
    $this->actingAs($user)->get("/api/client/support/tickets/{$ticket->id}/attachments/{$attachment->id}")->assertNotFound();
});

test('serves an attachment as a sandboxed image', function (): void {
    $user = User::factory()->create();
    $ticket = SupportTicket::factory()->for($user)->create();
    $message = SupportMessage::factory()->for($ticket, 'ticket')->create(['user_id' => $user->id]);
    Storage::disk('local')->put('support/a.png', pngBytes());
    $attachment = $message->attachments()->create(['path' => 'support/a.png', 'name' => 'a.png', 'mime' => 'image/png', 'size' => 10, 'width' => 1, 'height' => 1]);

    $response = $this->actingAs($user)->get("/api/client/support/tickets/{$ticket->id}/attachments/{$attachment->id}")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and($response->getContent())->toBe(pngBytes());
});

test('moves through the statuses and reopens a recently closed ticket', function (): void {
    $user = User::factory()->create();
    $ticket = SupportTicket::factory()->for($user)->create(['status' => SupportTicketStatus::Answered]);

    $this->actingAs($user)->postJson("/api/client/support/tickets/{$ticket->id}/messages", ['body' => 'Still broken'])
        ->assertCreated()->assertJsonPath('data.status', 'waiting_staff');

    $this->actingAs($user)->postJson("/api/client/support/tickets/{$ticket->id}/close")
        ->assertOk()->assertJsonPath('data.status', 'closed');
    $this->assertActivityOn('user:support.close', $user);

    $this->actingAs($user)->postJson("/api/client/support/tickets/{$ticket->id}/messages", ['body' => 'Back again'])
        ->assertCreated()->assertJsonPath('data.status', 'waiting_staff')->assertJsonPath('data.closed_at', null);
});

test('a ticket staff have not answered stays open on a reply', function (): void {
    $user = User::factory()->create();
    $ticket = SupportTicket::factory()->for($user)->create();

    $this->actingAs($user)->postJson("/api/client/support/tickets/{$ticket->id}/messages", ['body' => 'More details'])
        ->assertCreated()->assertJsonPath('data.status', 'open');
});

test('refuses a reply to a ticket closed more than seven days ago', function (): void {
    $user = User::factory()->create();
    $ticket = SupportTicket::factory()->for($user)->closed(now()->subDays(8))->create();

    $this->actingAs($user)->getJson("/api/client/support/tickets/{$ticket->id}")->assertOk()->assertJsonPath('data.can_reply', false);
    $this->actingAs($user)->postJson("/api/client/support/tickets/{$ticket->id}/messages", ['body' => 'Hello?'])->assertConflict();

    expect($ticket->refresh()->status)->toBe(SupportTicketStatus::Closed);
});

test('marks a staff reply unread until the player opens the ticket', function (): void {
    $user = User::factory()->create();
    $ticket = SupportTicket::factory()->for($user)->create();
    $reply = SupportMessage::factory()->for($ticket, 'ticket')->create(['is_staff' => true]);
    $ticket->forceFill(['last_message_id' => $reply->id, 'last_message_by_staff' => true, 'status' => SupportTicketStatus::Answered])->save();

    $this->actingAs($user)->getJson('/api/client/support/tickets')->assertOk()->assertJsonPath('data.0.unread', true);
    $this->actingAs($user)->getJson("/api/client/support/tickets/{$ticket->id}")->assertOk();
    $this->actingAs($user)->getJson('/api/client/support/tickets')->assertOk()->assertJsonPath('data.0.unread', false);
});

test('requires a signed in user', function (): void {
    $this->getJson('/api/client/support/tickets')->assertUnauthorized();
});
