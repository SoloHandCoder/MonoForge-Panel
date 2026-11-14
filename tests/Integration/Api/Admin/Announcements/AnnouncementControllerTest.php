<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Announcements\AnnouncementControllerTest;

use Illuminate\Http\Response;
use Pterodactyl\Enum\NoticeLevel;
use Pterodactyl\Models\Announcement;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

dataset('announcementEndpoints', fn (): array => [
    ['getJson', 'api.admin.announcements'],
    ['getJson', 'api.admin.announcements.view'],
    ['postJson', 'api.admin.announcements.store'],
    ['putJson', 'api.admin.announcements.update'],
    ['deleteJson', 'api.admin.announcements.delete'],
]);

test('lists announcements with their schedule status', function (): void {
    Announcement::factory()->create(['title' => 'Live']);
    Announcement::factory()->create(['title' => 'Later', 'starts_at' => now()->addDay()]);
    Announcement::factory()->create(['title' => 'Over', 'ends_at' => now()->subMinute()]);

    $response = $this->getJson(route('api.admin.announcements'))->assertOk();

    $response->assertJsonPath('object', 'list')->assertJsonPath('data.0.object', Announcement::RESOURCE_NAME);
    expect(collect($response->json('data'))->pluck('attributes.status', 'attributes.title')->all())
        ->toBe(['Over' => 'ended', 'Later' => 'scheduled', 'Live' => 'active']);
});

test('creates an announcement', function (): void {
    $response = $this->postJson(route('api.admin.announcements.store'), [
        'title' => 'Maintenance tonight',
        'body' => 'The panel is down from 18:00 to 18:30 UTC.',
        'level' => 'warning',
        'starts_at' => now()->addHour()->toAtomString(),
        'ends_at' => now()->addHours(2)->toAtomString(),
        'dismissible' => false,
    ]);

    $response->assertStatus(Response::HTTP_CREATED)
        ->assertJsonPath('attributes.title', 'Maintenance tonight')
        ->assertJsonPath('attributes.level', 'warning')
        ->assertJsonPath('attributes.dismissible', false)
        ->assertJsonPath('attributes.status', 'scheduled');
    $this->assertDatabaseHas('announcements', ['title' => 'Maintenance tonight', 'level' => 'warning', 'dismissible' => false]);
    $this->assertActivityLogged('admin:announcement.create');
});

test('rejects an announcement that ends before it starts or has an unknown level', function (): void {
    $response = $this->postJson(route('api.admin.announcements.store'), [
        'title' => 'Backwards',
        'body' => 'Ends first.',
        'level' => 'loud',
        'starts_at' => now()->addDay()->toAtomString(),
        'ends_at' => now()->toAtomString(),
    ])->assertUnprocessable();

    expect(collect($response->json('errors'))->pluck('meta.source_field')->sort()->values()->all())->toBe(['ends_at', 'level']);
});

test('updates an announcement', function (): void {
    $announcement = Announcement::factory()->create(['updated_at' => now()->subDay()]);
    $before = $announcement->updated_at;

    $this->putJson(route('api.admin.announcements.update', ['announcement' => $announcement->id]), [
        'title' => 'Back online',
        'body' => 'Everything works again.',
        'level' => 'success',
    ])->assertOk()->assertJsonPath('attributes.level', 'success')->assertJsonPath('attributes.dismissible', true);

    $announcement->refresh();
    expect($announcement->title)->toBe('Back online')
        ->and($announcement->level)->toBe(NoticeLevel::Success)
        ->and($announcement->updated_at?->greaterThan($before))->toBeTrue();
});

test('deletes an announcement', function (): void {
    $announcement = Announcement::factory()->create();

    $this->deleteJson(route('api.admin.announcements.delete', ['announcement' => $announcement->id]))
        ->assertNoContent();

    $this->assertDatabaseMissing('announcements', ['id' => $announcement->id]);
});

test('non admins are forbidden', function (string $method, string $routeName): void {
    $announcement = Announcement::factory()->create();
    $this->actingAsNonAdmin();

    $response = $this->{$method}(route($routeName, ['announcement' => $announcement->id]), [
        'title' => 'Nope',
        'body' => 'Nope',
        'level' => 'info',
    ]);

    $this->assertAccessDeniedJson($response);
    $this->assertDatabaseHas('announcements', ['id' => $announcement->id, 'title' => $announcement->title]);
})->with('announcementEndpoints');
