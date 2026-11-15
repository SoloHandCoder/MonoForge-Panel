<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\Server\Subuser\InviteFriendSubuserTest;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\PermissionTemplate;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Subusers\PermissionTemplateCatalog;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);

function makeFriends(User $one, User $two): void
{
    $now = now();
    $one->friends()->attach($two->id, ['created_at' => $now, 'updated_at' => $now]);
    $two->friends()->attach($one->id, ['created_at' => $now, 'updated_at' => $now]);
}

test('a friend can be invited by their UUID', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $friend = User::factory()->create();
    makeFriends($user, $friend);

    $this->actingAs($user)->postJson($this->link($server).'/users', [
        'friend' => $friend->uuid,
        'permissions' => [Permissions::ControlConsole->value],
    ])->assertOk()->assertJsonPath('attributes.uuid', $friend->uuid);

    expect($server->subusers()->where('user_id', $friend->id)->exists())->toBeTrue();
});

test('only a friend can be invited by UUID', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $stranger = User::factory()->create();

    foreach ([$stranger->uuid, '00000000-0000-4000-8000-000000000000'] as $uuid) {
        $this->actingAs($user)->postJson($this->link($server).'/users', [
            'friend' => $uuid,
            'permissions' => [Permissions::ControlConsole->value],
        ])->assertUnprocessable()->assertJsonPath('errors.0.detail', 'Pick one of your friends.');
    }

    expect($server->subusers()->where('user_id', $stranger->id)->exists())->toBeFalse();
});

test('an invite gives either an email or a friend', function (array $body): void {
    [$user, $server] = $this->generateTestAccount();

    $this->actingAs($user)->postJson($this->link($server).'/users', [...$body, 'permissions' => []])->assertUnprocessable();
})->with([
    'neither' => [[]],
    'both' => [['email' => 'a@example.com', 'friend' => '00000000-0000-4000-8000-000000000000']],
]);

test('a template cannot grant a permission the inviter does not hold', function (): void {
    // The inviter is a subuser who may invite and use the console only.
    [$user, $server] = $this->generateTestAccount([Permissions::UserCreate->value, Permissions::ControlConsole->value]);
    $friend = User::factory()->create();
    makeFriends($user, $friend);
    $coOwner = collect(resolve(PermissionTemplateCatalog::class)->builtIn())->firstWhere('key', 'builtin:co-owner');

    $this->actingAs($user)->postJson($this->link($server).'/users', [
        'friend' => $friend->uuid,
        'permissions' => $coOwner['permissions'],
    ])->assertForbidden();

    // Filled from the template but kept to what the inviter holds, as the form does, it works.
    $held = array_values(array_intersect($coOwner['permissions'], [Permissions::UserCreate->value, Permissions::ControlConsole->value]));
    expect($held)->toBe([Permissions::ControlConsole->value]);
    $this->actingAs($user)->postJson($this->link($server).'/users', ['friend' => $friend->uuid, 'permissions' => $held])->assertOk();
});

test('changing a template later does not change subusers invited with it', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $template = PermissionTemplate::factory()->create(['user_id' => $user->id, 'permissions' => ['control.console']]);

    $this->actingAs($user)->postJson($this->link($server).'/users', [
        'email' => 'invitee@example.com',
        'permissions' => $template->permissions,
    ])->assertOk();

    $this->actingAs($user)->putJson("/api/client/account/permission-templates/{$template->id}", [
        'name' => $template->name,
        'permissions' => ['file.read', 'file.update'],
    ])->assertOk();

    $subuser = Subuser::query()->where('server_id', $server->id)->whereHas('user', fn ($query) => $query->where('email', 'invitee@example.com'))->sole();
    expect($subuser->permissions)->toEqualCanonicalizing(['control.console', 'websocket.connect']);
});
