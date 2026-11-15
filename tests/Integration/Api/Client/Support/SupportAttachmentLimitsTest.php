<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\Support\SupportAttachmentLimitsTest;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Pterodactyl\Console\Commands\Support\PruneSupportAttachmentsCommand;
use Pterodactyl\Console\Scheduler;
use Pterodactyl\Enum\SupportTicketStatus;
use Pterodactyl\Models\Support\SupportAttachment;
use Pterodactyl\Models\Support\SupportCategory;
use Pterodactyl\Models\Support\SupportMessage;
use Pterodactyl\Models\Support\SupportTicket;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Support\SupportAttachmentQuota;
use Pterodactyl\Services\Support\SupportAttachmentStore;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
});

/** A real image of the given size, drawn with GD. */
function image(int $width, int $height, string $type = 'png'): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height - 1, (int) imagecolorallocate($image, 200, 30, 30));
    ob_start();
    $type === 'png' ? imagepng($image) : imagejpeg($image, null, 90);

    return (string) ob_get_clean();
}

/** A JPEG whose EXIF block holds an orientation tag and a marker standing in for GPS data. */
function jpegWithExif(int $width, int $height, int $orientation): string
{
    $tiff = "MM\x00\x2A\x00\x00\x00\x08"
        ."\x00\x01"
        ."\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00"
        ."\x00\x00\x00\x00"
        .'GPS-SECRET-48.8584N';
    $payload = "Exif\x00\x00".$tiff;
    $jpeg = image($width, $height, 'jpeg');

    return mb_substr($jpeg, 0, 2, '8bit')."\xFF\xE1".pack('n', mb_strlen($payload, '8bit') + 2).$payload.mb_substr($jpeg, 2, null, '8bit');
}

function upload(string $bytes, string $name = 'shot.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

/** Attachment rows already stored for the user, without files. */
function storedFor(User $user, int $count, int $size = 100, ?CarbonImmutable $at = null): void
{
    $message = SupportMessage::factory()->for(SupportTicket::factory()->for($user), 'ticket')->create(['user_id' => $user->id]);
    for ($i = 0; $i < $count; $i++) {
        $attachment = new SupportAttachment(['path' => "support/x/{$i}.png", 'name' => 'x.png', 'mime' => 'image/png', 'size' => $size, 'width' => 1, 'height' => 1]);
        $attachment->message_id = $message->id;
        $attachment->created_at = $at ?? CarbonImmutable::now();
        $attachment->save();
    }
}

function reply(User $user, SupportTicket $ticket, array $files): TestResponse
{
    return test()->actingAs($user)->post("/api/client/support/tickets/{$ticket->id}/messages", ['body' => 'See the images.', 'attachments' => $files], ['Accept' => 'application/json']);
}

test('allows thirty images a day per user and says so when there are more', function (): void {
    $user = User::factory()->create();
    $ticket = SupportTicket::factory()->for($user)->create();
    storedFor($user, 28);
    // Older than a day: not counted.
    storedFor($user, 5, at: CarbonImmutable::now()->subDay()->subMinute());

    reply($user, $ticket, [upload(image(4, 4)), upload(image(4, 4)), upload(image(4, 4))])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.detail', 'You can attach at most 30 images a day. You have 2 left today.');
    expect(SupportMessage::query()->where('ticket_id', $ticket->id)->count())->toBe(0);

    reply($user, $ticket, [upload(image(4, 4)), upload(image(4, 4))])->assertCreated();

    reply($user, $ticket, [upload(image(4, 4))])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.detail', 'You can attach at most 30 images a day. Try again tomorrow, or send the message without images.');

    // A message without images still goes through.
    test()->actingAs($user)->postJson("/api/client/support/tickets/{$ticket->id}/messages", ['body' => 'No images this time.'])->assertCreated();
});

test('refuses images past 100 MB stored per user, also on a new ticket', function (): void {
    $user = User::factory()->create();
    storedFor($user, 1, SupportAttachmentQuota::MAX_BYTES - 50);

    $this->actingAs($user)->post('/api/client/support/tickets', [
        'subject' => 'Help me',
        'category_id' => SupportCategory::query()->firstOrFail()->id,
        'body' => 'Look at this.',
        'attachments' => [upload(image(64, 64))],
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', 'attachments');

    expect(SupportTicket::query()->where('subject', 'Help me')->exists())->toBeFalse();
    Storage::disk('local')->assertDirectoryEmpty('support');
});

test('counts only what the user posted towards their quota', function (): void {
    $user = User::factory()->create();
    storedFor(User::factory()->create(), 1, SupportAttachmentQuota::MAX_BYTES);
    $ticket = SupportTicket::factory()->for($user)->create();

    reply($user, $ticket, [upload(image(8, 8))])->assertCreated();
});

test('re-encodes a photo without its EXIF data and turns it upright', function (): void {
    $user = User::factory()->create();
    $ticket = SupportTicket::factory()->for($user)->create();
    $original = jpegWithExif(40, 20, 6);
    expect($original)->toContain('GPS-SECRET');

    reply($user, $ticket, [upload($original, 'photo.jpg')])->assertCreated();

    $attachment = SupportAttachment::query()->firstOrFail();
    $stored = (string) Storage::disk('local')->get($attachment->path);
    $size = getimagesizefromstring($stored);

    expect($stored)->not->toContain('GPS-SECRET')
        ->and($stored)->not->toContain("Exif\x00\x00")
        ->and($attachment->mime)->toBe('image/jpeg')
        ->and($size)->toBeArray()
        // Orientation 6 means "rotate 90 degrees clockwise": the stored image is portrait.
        ->and([$size[0] ?? 0, $size[1] ?? 0])->toBe([20, 40])
        ->and([$attachment->width, $attachment->height])->toBe([20, 40])
        ->and($attachment->size)->toBe(mb_strlen($stored, '8bit'));
});

test('scales a large image down to 2560 pixels on its longest side', function (): void {
    $user = User::factory()->create();
    $ticket = SupportTicket::factory()->for($user)->create();

    reply($user, $ticket, [upload(image(3200, 1000))])->assertCreated();

    $attachment = SupportAttachment::query()->firstOrFail();
    $size = getimagesizefromstring((string) Storage::disk('local')->get($attachment->path));
    expect([$attachment->width, $attachment->height])->toBe([2560, 800])
        ->and([$size[0] ?? 0, $size[1] ?? 0])->toBe([2560, 800]);
});

test('refuses an image over 8 megapixels from its header, before decoding it', function (): void {
    $user = User::factory()->create();
    $ticket = SupportTicket::factory()->for($user)->create();

    reply($user, $ticket, [upload(image(4000, 2001))])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.detail', SupportAttachmentStore::TOO_MANY_PIXELS);
    expect(SupportAttachment::query()->count())->toBe(0);
});

test('without GD a JPEG carrying EXIF is refused and a clean one kept as sent', function (): void {
    $this->app->bind(SupportAttachmentStore::class, fn (): SupportAttachmentStore => new class extends SupportAttachmentStore
    {
        protected function canReencode(): bool
        {
            return false;
        }
    });
    $user = User::factory()->create();
    $ticket = SupportTicket::factory()->for($user)->create();

    reply($user, $ticket, [upload(jpegWithExif(10, 10, 1), 'photo.jpg')])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', 'attachments');

    $clean = image(10, 10, 'jpeg');
    reply($user, $ticket, [upload($clean, 'photo.jpg')])->assertCreated();
    expect(Storage::disk('local')->get(SupportAttachment::query()->firstOrFail()->path))->toBe($clean);
});

test('prunes the images of tickets closed more than 180 days ago and keeps the messages', function (): void {
    $user = User::factory()->create();
    $old = SupportTicket::factory()->for($user)->create(['status' => SupportTicketStatus::Closed, 'closed_at' => CarbonImmutable::now()->subDays(181)]);
    $recent = SupportTicket::factory()->for($user)->create(['status' => SupportTicketStatus::Closed, 'closed_at' => CarbonImmutable::now()->subDays(179)]);
    $open = SupportTicket::factory()->for($user)->create(['status' => SupportTicketStatus::Answered, 'closed_at' => null]);

    $paths = [];
    foreach ([$old, $recent, $open] as $ticket) {
        $message = SupportMessage::factory()->for($ticket, 'ticket')->create(['user_id' => $user->id]);
        foreach ([1, 2] as $n) {
            $path = "support/{$ticket->id}/{$n}.png";
            Storage::disk('local')->put($path, 'bytes');
            $message->attachments()->create(['path' => $path, 'name' => 'a.png', 'mime' => 'image/png', 'size' => 5, 'width' => 1, 'height' => 1]);
            $paths[$ticket->id][] = $path;
        }
    }

    $this->artisan(PruneSupportAttachmentsCommand::class)->assertSuccessful();

    foreach ($paths[$old->id] as $path) {
        Storage::disk('local')->assertMissing($path);
    }

    foreach ([...$paths[$recent->id], ...$paths[$open->id]] as $path) {
        Storage::disk('local')->assertExists($path);
    }

    expect(SupportAttachment::query()->count())->toBe(4)
        ->and(SupportMessage::query()->where('ticket_id', $old->id)->value('expired_attachments'))->toBe(2);

    $this->actingAs($user)->getJson("/api/client/support/tickets/{$old->id}")->assertOk()
        ->assertJsonCount(0, 'data.messages.0.attachments')
        ->assertJsonPath('data.messages.0.expired_attachments', 2);
    $this->actingAs($user)->getJson("/api/client/support/tickets/{$recent->id}")->assertOk()
        ->assertJsonCount(2, 'data.messages.0.attachments')
        ->assertJsonPath('data.messages.0.expired_attachments', 0);
});

test('prunes daily on one server without overlapping', function (): void {
    $schedule = new Schedule;
    $this->app->make(Scheduler::class)($schedule);
    $events = array_values(array_filter($schedule->events(), fn (Event $event): bool => str_contains((string) $event->command, 'p:support:prune-attachments')));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('0 0 * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->onOneServer)->toBeTrue();
});
