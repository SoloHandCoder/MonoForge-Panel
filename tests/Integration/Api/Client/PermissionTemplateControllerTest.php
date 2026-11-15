<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\PermissionTemplateControllerTest;

use Pterodactyl\Models\PermissionTemplate;
use Pterodactyl\Models\User;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);

test("lists the built-in, global and own templates, but not other users' ones", function (): void {
    $user = User::factory()->create();
    PermissionTemplate::factory()->create(['name' => 'Staff']);
    PermissionTemplate::factory()->create(['name' => 'Theirs', 'user_id' => User::factory()->create()->id]);
    $own = PermissionTemplate::factory()->create(['name' => 'Mine', 'user_id' => $user->id, 'permissions' => ['control.console', 'not.a-permission']]);

    $response = $this->actingAs($user)->getJson('/api/client/account/permission-templates')->assertOk();

    $response->assertJsonPath('object', 'permission_template_list')
        ->assertJsonPath('attributes.personal_limit', PermissionTemplate::MAX_PER_ACCOUNT);
    $templates = collect($response->json('attributes.templates'));
    expect($templates->pluck('name')->all())->toBe(['Viewer', 'Moderator', 'Builder', 'Co-owner', 'Staff', 'Mine'])
        ->and($templates->pluck('source')->all())->toBe(['builtin', 'builtin', 'builtin', 'builtin', 'global', 'personal'])
        ->and($templates->last())->toBe([
            'key' => "personal:{$own->id}",
            'id' => $own->id,
            'source' => 'personal',
            'name' => 'Mine',
            // A key the panel no longer knows is dropped; console access is always there.
            'permissions' => ['websocket.connect', 'control.console'],
        ]);
});

test('the built-in templates hold what their names promise', function (): void {
    $templates = collect($this->actingAs(User::factory()->create())->getJson('/api/client/account/permission-templates')->json('attributes.templates'))
        ->keyBy('key')
        ->map(fn (array $template): array => $template['permissions']);

    expect($templates['builtin:viewer'])->toBe(['websocket.connect', 'file.read', 'file.read-content'])
        ->and($templates['builtin:moderator'])->toContain('control.console', 'control.start', 'control.restart')
        ->and($templates['builtin:moderator'])->not->toContain('control.stop', 'file.update')
        ->and($templates['builtin:builder'])->toContain('file.update', 'file.create', 'backup.create', 'backup.read')
        ->and($templates['builtin:builder'])->not->toContain('control.console')
        ->and($templates['builtin:co-owner'])->toContain('control.stop', 'settings.reinstall', 'database.view_password')
        ->and($templates['builtin:co-owner'])->not->toContain('user.create', 'user.read', 'user.update', 'user.delete');
});

test('a user creates, updates and deletes their own templates', function (): void {
    $user = User::factory()->create();

    $created = $this->actingAs($user)->postJson('/api/client/account/permission-templates', [
        'name' => ' Event staff ',
        'permissions' => ['control.console', 'control.console', 'control.start'],
    ])->assertCreated();

    $created->assertJsonPath('object', PermissionTemplate::RESOURCE_NAME)
        ->assertJsonPath('attributes.name', 'Event staff')
        ->assertJsonPath('attributes.source', 'personal')
        ->assertJsonPath('attributes.permissions', ['websocket.connect', 'control.console', 'control.start']);
    $id = $created->json('attributes.id');
    $this->assertActivityFor('user:permission-template.create', $user, $user);

    $this->actingAs($user)->putJson("/api/client/account/permission-templates/{$id}", ['name' => 'Staff', 'permissions' => ['file.read']])
        ->assertOk()
        ->assertJsonPath('attributes.permissions', ['websocket.connect', 'file.read']);

    $this->actingAs($user)->deleteJson("/api/client/account/permission-templates/{$id}")->assertNoContent();
    expect(PermissionTemplate::query()->exists())->toBeFalse();
});

test('unknown permissions are refused', function (): void {
    $this->actingAs(User::factory()->create())->postJson('/api/client/account/permission-templates', [
        'name' => 'Bad',
        'permissions' => ['control.console', 'admin.everything'],
    ])->assertUnprocessable();
});

test("a user cannot change global or other users' templates", function (): void {
    $user = User::factory()->create();
    $global = PermissionTemplate::factory()->create();
    $theirs = PermissionTemplate::factory()->create(['user_id' => User::factory()->create()->id]);

    foreach ([$global, $theirs] as $template) {
        $this->actingAs($user)->putJson("/api/client/account/permission-templates/{$template->id}", ['name' => 'x', 'permissions' => []])->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/client/account/permission-templates/{$template->id}")->assertNotFound();
    }

    expect(PermissionTemplate::query()->count())->toBe(2);
});

test('an account keeps a limited number of templates', function (): void {
    $user = User::factory()->create();
    PermissionTemplate::factory()->count(PermissionTemplate::MAX_PER_ACCOUNT)->create(['user_id' => $user->id]);

    $this->actingAs($user)->postJson('/api/client/account/permission-templates', ['name' => 'One more', 'permissions' => []])
        ->assertStatus(400);
});
