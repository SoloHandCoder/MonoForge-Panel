<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Updates\UpdateControllerTest;

use FilesystemIterator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\Setting;
use Pterodactyl\Models\User;
use Pterodactyl\Services\MonoForge\MonoForgeUpdateService;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

uses(AdminApiIntegrationTestCase::class);

const LATEST_URL = 'https://api.github.com/repos/SoloHandCoder/MonoForge-Panel/releases/latest';

beforeEach(function (): void {
    config(['monoforge.update.mode' => 'notify', 'pterodactyl.load_environment_only' => false]);
    $this->updateBase = sys_get_temp_dir().'/monoforge-api-test-'.Str::random(8);
    mkdir($this->updateBase, 0o755, true);
    $this->app->instance(MonoForgeUpdateService::class, new MonoForgeUpdateService(new Repository(new ArrayStore), $this->updateBase, $this->updateBase.'/work'));
    Http::fake([LATEST_URL => Http::response([
        'tag_name' => 'build-9e8d7c6',
        'target_commitish' => '9e8d7c6b5a4f30291817263544a3b2c1d0e9f8a7',
        'published_at' => '2026-11-15T12:00:00Z',
        'assets' => [['name' => 'monoforge-panel.tar.gz'], ['name' => 'monoforge-panel.tar.gz.sha256'], ['name' => 'version.json']],
    ])]);
});

afterEach(function (): void {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->updateBase, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($this->updateBase);
});

test('reports the running and latest MonoForge builds', function (): void {
    $this->getJson(route('api.admin.updates.status'))
        ->assertOk()
        ->assertJsonPath('mode', 'notify')
        ->assertJsonPath('current', null)
        ->assertJsonPath('current_version', config('app.version'))
        ->assertJsonPath('latest.version', '9e8d7c6')
        ->assertJsonPath('latest.url', 'https://github.com/SoloHandCoder/MonoForge-Panel/releases/tag/build-9e8d7c6')
        ->assertJsonPath('check', 'available')
        ->assertJsonPath('update_available', true)
        ->assertJsonPath('status.state', 'idle');

    $this->getJson(route('api.admin.version'))
        ->assertOk()
        ->assertJsonPath('latest', '9e8d7c6')
        ->assertJsonPath('is_latest', false);
});

test('queues an update for the scheduler and refuses a second one', function (): void {
    $this->postJson(route('api.admin.updates.apply'))
        ->assertStatus(Response::HTTP_ACCEPTED)
        ->assertJsonPath('status.state', 'queued')
        ->assertJsonPath('status.to', '9e8d7c6');

    expect($this->app->make(MonoForgeUpdateService::class)->hasPendingRequest())->toBeTrue();

    $this->postJson(route('api.admin.updates.apply'))->assertStatus(Response::HTTP_BAD_REQUEST);
});

test('refuses to apply when there is nothing newer', function (): void {
    file_put_contents($this->updateBase.'/version.json', json_encode(['version' => '9e8d7c6', 'commit' => '9e8d7c6b5a4f30291817263544a3b2c1d0e9f8a7']));

    $this->postJson(route('api.admin.updates.apply'))
        ->assertStatus(Response::HTTP_BAD_REQUEST)
        ->assertJsonPath('errors.0.detail', 'There is no newer MonoForge build to install.');
});

test('saves the update mode, and turning updates off cancels a queued update', function (): void {
    $this->putJson(route('api.admin.updates.settings'), ['mode' => 'auto'])->assertNoContent();
    expect(Setting::fetch('settings::monoforge:update:mode'))->toBe('auto');

    $this->postJson(route('api.admin.updates.apply'))->assertStatus(Response::HTTP_ACCEPTED);
    $this->putJson(route('api.admin.updates.settings'), ['mode' => 'off'])->assertNoContent();
    expect($this->app->make(MonoForgeUpdateService::class)->hasPendingRequest())->toBeFalse();

    $this->putJson(route('api.admin.updates.settings'), ['mode' => 'sometimes'])->assertUnprocessable();
});

test('refuses to save the mode when settings come from the environment only', function (): void {
    config(['pterodactyl.load_environment_only' => true]);

    $this->putJson(route('api.admin.updates.settings'), ['mode' => 'auto'])->assertStatus(Response::HTTP_BAD_REQUEST);
    expect(Setting::fetch('settings::monoforge:update:mode'))->toBeNull();
});

test('an administrator who may only read versions cannot apply updates or change the mode', function (): void {
    $user = $this->actingAsNonAdmin();
    Gate::define(Permissions::AdminVersionRead->value, fn (User $actor): bool => $actor->is($user));

    $this->assertAccessDeniedJson($this->postJson(route('api.admin.updates.apply')));
    $this->assertAccessDeniedJson($this->putJson(route('api.admin.updates.settings'), ['mode' => 'auto']));
    expect($this->app->make(MonoForgeUpdateService::class)->hasPendingRequest())->toBeFalse();
});

test('a root administrator API key cannot apply updates', function (): void {
    $key = ApiKey::factory()->create(['user_id' => $this->getAdminUser()->id, 'key_type' => ApiKey::TYPE_ACCOUNT]);
    $this->app['auth']->forgetGuards();

    $this->withHeader('Accept', 'application/vnd.pterodactyl.v1+json')
        ->withHeader('Authorization', 'Bearer '.$key->identifier.decrypt($key->token))
        ->postJson(route('api.admin.updates.apply'))
        ->assertForbidden()
        ->assertJsonPath('errors.0.detail', 'This action can only be performed from an authenticated panel session, not with an API key.');
    expect($this->app->make(MonoForgeUpdateService::class)->hasPendingRequest())->toBeFalse();
});

test('non admins cannot see or apply updates', function (): void {
    $this->actingAsNonAdmin();

    $this->assertAccessDeniedJson($this->getJson(route('api.admin.updates.status')));
    $this->assertAccessDeniedJson($this->postJson(route('api.admin.updates.apply')));
    $this->assertAccessDeniedJson($this->putJson(route('api.admin.updates.settings'), ['mode' => 'auto']));
    expect($this->app->make(MonoForgeUpdateService::class)->hasPendingRequest())->toBeFalse();
});

test('check now bypasses the cached release and returns the status payload', function (): void {
    // The status call caches the first release.
    $this->getJson(route('api.admin.updates.status'))->assertOk()->assertJsonPath('latest.version', '9e8d7c6');

    Http::swap(new HttpFactory);
    Http::fake([LATEST_URL => Http::response([
        'tag_name' => 'build-1a2b3c4',
        'target_commitish' => '1a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d',
        'published_at' => '2026-11-15T13:00:00Z',
        'assets' => [['name' => 'monoforge-panel.tar.gz'], ['name' => 'monoforge-panel.tar.gz.sha256'], ['name' => 'version.json']],
    ])]);

    // The status endpoint keeps answering from the cache...
    $this->getJson(route('api.admin.updates.status'))->assertOk()->assertJsonPath('latest.version', '9e8d7c6');

    // ...while checking now asks GitHub again and refreshes the cache.
    $this->postJson(route('api.admin.updates.check'))
        ->assertOk()
        ->assertJsonPath('mode', 'notify')
        ->assertJsonPath('latest.version', '1a2b3c4')
        ->assertJsonPath('check', 'available')
        ->assertJsonPath('update_available', true)
        ->assertJsonPath('status.state', 'idle');

    $this->getJson(route('api.admin.updates.status'))->assertOk()->assertJsonPath('latest.version', '1a2b3c4');
});

test('check now does not call GitHub while update checks are off', function (): void {
    config(['monoforge.update.mode' => 'off']);
    Http::fake();

    $this->postJson(route('api.admin.updates.check'))->assertOk()->assertJsonPath('check', 'off')->assertJsonPath('latest', null);
    Http::assertNothingSent();
});

test('check now is limited to six requests a minute', function (): void {
    foreach (range(1, 6) as $attempt) {
        $this->postJson(route('api.admin.updates.check'))->assertOk();
    }

    $this->postJson(route('api.admin.updates.check'))->assertTooManyRequests();
});

test('non admins cannot check now, like the status they would read', function (): void {
    $this->actingAsNonAdmin();
    Http::fake();

    $this->assertAccessDeniedJson($this->postJson(route('api.admin.updates.check')));
    Http::assertNothingSent();
});

test('a root administrator API key cannot check now', function (): void {
    $key = ApiKey::factory()->create(['user_id' => $this->getAdminUser()->id, 'key_type' => ApiKey::TYPE_ACCOUNT]);
    $this->app['auth']->forgetGuards();

    $this->withHeader('Accept', 'application/vnd.pterodactyl.v1+json')
        ->withHeader('Authorization', 'Bearer '.$key->identifier.decrypt($key->token))
        ->postJson(route('api.admin.updates.check'))
        ->assertForbidden();
});
