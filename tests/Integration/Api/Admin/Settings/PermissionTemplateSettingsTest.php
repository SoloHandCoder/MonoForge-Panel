<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Settings\PermissionTemplateSettingsTest;

use Pterodactyl\Models\PermissionTemplate;
use Pterodactyl\Models\User;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

test('lists only the global templates', function (): void {
    PermissionTemplate::factory()->create(['name' => 'Staff']);
    PermissionTemplate::factory()->create(['name' => 'Private', 'user_id' => User::factory()->create()->id]);

    $this->getJson(route('api.admin.settings.permission-templates'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.object', PermissionTemplate::RESOURCE_NAME)
        ->assertJsonPath('data.0.attributes.name', 'Staff');
});

test('creates, updates and deletes a global template', function (): void {
    $created = $this->postJson(route('api.admin.settings.permission-templates.store'), [
        'name' => 'Staff',
        'permissions' => ['control.console', 'control.start'],
    ])->assertCreated()->assertJsonPath('attributes.permissions', ['websocket.connect', 'control.console', 'control.start']);
    $id = $created->json('attributes.id');
    expect(PermissionTemplate::query()->findOrFail($id)->user_id)->toBeNull();
    $this->assertActivityLogged('admin:permission-template.create');

    $this->putJson(route('api.admin.settings.permission-templates.update', ['permissionTemplate' => $id]), [
        'name' => 'Helpers',
        'permissions' => ['file.read'],
    ])->assertOk()->assertJsonPath('attributes.name', 'Helpers');

    $this->deleteJson(route('api.admin.settings.permission-templates.delete', ['permissionTemplate' => $id]))->assertNoContent();
    expect(PermissionTemplate::query()->exists())->toBeFalse();
});

test("a user's own template cannot be changed here", function (): void {
    $template = PermissionTemplate::factory()->create(['user_id' => User::factory()->create()->id]);

    $this->putJson(route('api.admin.settings.permission-templates.update', ['permissionTemplate' => $template->id]), ['name' => 'x', 'permissions' => []])->assertNotFound();
    $this->deleteJson(route('api.admin.settings.permission-templates.delete', ['permissionTemplate' => $template->id]))->assertNotFound();
});

test('unknown permissions are refused', function (): void {
    $this->postJson(route('api.admin.settings.permission-templates.store'), ['name' => 'Bad', 'permissions' => ['nope.nope']])->assertUnprocessable();
});

test('non admins are forbidden', function (): void {
    $template = PermissionTemplate::factory()->create();
    $this->actingAsNonAdmin();

    $this->assertAccessDeniedJson($this->getJson(route('api.admin.settings.permission-templates')));
    $this->assertAccessDeniedJson($this->postJson(route('api.admin.settings.permission-templates.store'), ['name' => 'x', 'permissions' => []]));
    $this->assertAccessDeniedJson($this->deleteJson(route('api.admin.settings.permission-templates.delete', ['permissionTemplate' => $template->id])));
    expect(PermissionTemplate::query()->count())->toBe(1);
});
