<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Updates\UpdateControllerTest;

use FilesystemIterator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Pterodactyl\Models\Setting;
use Pterodactyl\Services\MonoForge\MonoForgeUpdateService;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

uses(AdminApiIntegrationTestCase::class);

const LATEST_URL = 'https://api.github.com/repos/SoloHandCoder/MonoForge-Panel/releases/latest';

beforeEach(function (): void {
    config(['monoforge.update.mode' => 'notify']);
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

test('saves the update mode', function (): void {
    $this->putJson(route('api.admin.updates.settings'), ['mode' => 'auto'])->assertNoContent();
    expect(Setting::fetch('settings::monoforge:update:mode'))->toBe('auto');

    $this->putJson(route('api.admin.updates.settings'), ['mode' => 'sometimes'])->assertUnprocessable();
});

test('non admins cannot see or apply updates', function (): void {
    $this->actingAsNonAdmin();

    $this->assertAccessDeniedJson($this->getJson(route('api.admin.updates.status')));
    $this->assertAccessDeniedJson($this->postJson(route('api.admin.updates.apply')));
    $this->assertAccessDeniedJson($this->putJson(route('api.admin.updates.settings'), ['mode' => 'auto']));
    expect($this->app->make(MonoForgeUpdateService::class)->hasPendingRequest())->toBeFalse();
});
