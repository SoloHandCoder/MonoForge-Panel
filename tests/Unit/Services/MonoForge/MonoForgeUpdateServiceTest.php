<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\MonoForge\MonoForgeUpdateServiceTest;

use FilesystemIterator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Pterodactyl\Services\MonoForge\MonoForgeRelease;
use Pterodactyl\Services\MonoForge\MonoForgeUpdateService;
use Pterodactyl\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

uses(TestCase::class);

const REPO = 'SoloHandCoder/MonoForge-Panel';
const LATEST_URL = 'https://api.github.com/repos/SoloHandCoder/MonoForge-Panel/releases/latest';
const LATEST_SHA = '9e8d7c6b5a4f30291817263544a3b2c1d0e9f8a7';
const CURRENT_SHA = '3f9c2ab1d4e5f60718293a4b5c6d7e8f90a1b2c3';
const COMPARE_URL = 'https://api.github.com/repos/SoloHandCoder/MonoForge-Panel/compare/'.CURRENT_SHA.'...'.LATEST_SHA;

/** @return array<string, mixed> */
function releasePayload(string $tag = 'build-9e8d7c6', bool $withChecksum = true): array
{
    $assets = [['name' => 'monoforge-panel.tar.gz'], ['name' => 'version.json']];
    if ($withChecksum) {
        $assets[] = ['name' => 'monoforge-panel.tar.gz.sha256'];
    }

    return [
        'tag_name' => $tag,
        'target_commitish' => LATEST_SHA,
        'draft' => false,
        'prerelease' => false,
        'published_at' => '2026-11-15T12:00:00Z',
        'assets' => $assets,
    ];
}

function temporaryDirectory(): string
{
    $path = sys_get_temp_dir().'/monoforge-update-test-'.Str::random(8);
    mkdir($path, 0o755, true);

    return $path;
}

function deleteDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

function updateService(string $base, string $work): MonoForgeUpdateService
{
    return new MonoForgeUpdateService(new Repository(new ArrayStore), $base, $work);
}

beforeEach(function (): void {
    config(['monoforge.update.mode' => 'notify', 'monoforge.update.repo' => REPO]);
});

test('only offers a build that GitHub says is ahead of the running commit', function (string $comparison, string $expected): void {
    $base = temporaryDirectory();
    file_put_contents($base.'/version.json', json_encode(['version' => '3f9c2ab', 'commit' => CURRENT_SHA]));
    Http::fake([
        LATEST_URL => Http::response(releasePayload()),
        COMPARE_URL => $comparison === 'error' ? Http::response('nope', 500) : Http::response(['status' => $comparison]),
    ]);

    try {
        $service = updateService($base, $base.'/work');

        expect($service->updateCheck())->toBe($expected)
            ->and($service->isUpdateAvailable())->toBe($expected === 'available');
    } finally {
        deleteDirectory($base);
    }
})->with([
    'ahead' => ['ahead', 'available'],
    'an older release (re-run or downgrade)' => ['behind', 'current'],
    'diverged' => ['diverged', 'current'],
    'the comparison failed' => ['error', 'unknown'],
]);

test('treats the same commit as current and an unknown running build as outdated without comparing', function (): void {
    $base = temporaryDirectory();
    Http::fake([LATEST_URL => Http::response(releasePayload())]);

    try {
        $service = updateService($base, $base.'/work');
        expect($service->updateCheck())->toBe('available');

        // A short hash and the full hash of one commit are the same build.
        file_put_contents($base.'/version.json', json_encode(['version' => '9e8d7c6', 'commit' => '9e8d7c6']));
        expect($service->updateCheck())->toBe('current');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/compare/'));
    } finally {
        deleteDirectory($base);
    }
});

test('reads releases only from build tags that carry the tarball and its checksum', function (): void {
    $release = MonoForgeRelease::fromGitHub(REPO, releasePayload());

    expect($release)->toBeInstanceOf(MonoForgeRelease::class)
        ->and($release?->build->version)->toBe('9e8d7c6')
        ->and($release?->build->commit)->toBe(LATEST_SHA)
        ->and($release?->assetUrl(MonoForgeRelease::TARBALL))->toBe('https://github.com/SoloHandCoder/MonoForge-Panel/releases/download/build-9e8d7c6/monoforge-panel.tar.gz')
        ->and(MonoForgeRelease::fromGitHub(REPO, releasePayload('v1.11.0')))->toBeNull()
        ->and(MonoForgeRelease::fromGitHub(REPO, releasePayload(withChecksum: false)))->toBeNull()
        ->and(MonoForgeRelease::fromGitHub(REPO, 'not a release'))->toBeNull();
});

test('reports an update when the running build is older and caches the lookup', function (): void {
    $base = temporaryDirectory();
    file_put_contents($base.'/version.json', json_encode(['version' => '3f9c2ab', 'commit' => CURRENT_SHA, 'built_at' => '2026-11-14T12:00:00Z']));
    Http::fake([LATEST_URL => Http::response(releasePayload()), COMPARE_URL => Http::response(['status' => 'ahead'])]);

    try {
        $service = updateService($base, $base.'/work');

        expect($service->currentVersion())->toBe('3f9c2ab')
            ->and($service->isUpdateAvailable())->toBeTrue()
            ->and($service->latest()?->build->version)->toBe('9e8d7c6');
        // One release lookup and one comparison, both cached.
        Http::assertSentCount(2);

        config(['monoforge.update.mode' => 'off']);
        expect($service->isUpdateAvailable())->toBeFalse();
    } finally {
        deleteDirectory($base);
    }
});

test('treats GitHub failures as an unknown latest build', function (): void {
    Http::fake([LATEST_URL => Http::response('nope', 500)]);
    $base = temporaryDirectory();

    try {
        expect(updateService($base, $base.'/work')->latest())->toBeNull();
    } finally {
        deleteDirectory($base);
    }
});

test('a checksum mismatch aborts the update without touching the panel', function (): void {
    $base = temporaryDirectory();
    $work = $base.'/storage/app/updates';
    file_put_contents($base.'/artisan', 'original');
    Http::fake([
        LATEST_URL => Http::response(releasePayload()),
        'https://github.com/SoloHandCoder/MonoForge-Panel/releases/download/build-9e8d7c6/monoforge-panel.tar.gz.sha256' => Http::response(str_repeat('0', 64).'  monoforge-panel.tar.gz'),
        'https://github.com/SoloHandCoder/MonoForge-Panel/releases/download/build-9e8d7c6/monoforge-panel.tar.gz' => Http::response('not the build that was checksummed'),
    ]);
    Process::fake();

    try {
        $status = updateService($base, $work)->apply();

        expect($status['state'])->toBe('failed')
            ->and($status['error'])->toContain('does not match its sha256 checksum')
            ->and(file_get_contents($base.'/artisan'))->toBe('original')
            ->and(is_dir($work.'/download'))->toBeFalse()
            ->and(is_dir($work.'/staging'))->toBeFalse()
            ->and(glob($work.'/backup-*'))->toBe([]);

        // No maintenance mode, backups, unpacking or migrations were attempted.
        Process::assertNothingRan();
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://github.com/SoloHandCoder/MonoForge-Panel/releases/download/'));
        Http::assertNotSent(fn (Request $request): bool => ! str_starts_with($request->url(), 'https://'));
    } finally {
        deleteDirectory($base);
    }
});

test('the update command reports an available build with --check and installs nothing', function (): void {
    Http::fake([LATEST_URL => Http::response(releasePayload())]);
    Process::fake();
    $base = temporaryDirectory();
    $this->app->instance(MonoForgeUpdateService::class, updateService($base, $base.'/work'));

    try {
        expect(Artisan::call('p:monoforge:update', ['--check' => true]))->toBe(0)
            ->and(Artisan::output())->toContain('Update available: MonoForge build 9e8d7c6')
            ->toContain('https://github.com/SoloHandCoder/MonoForge-Panel/releases/tag/build-9e8d7c6');

        file_put_contents($base.'/version.json', json_encode(['version' => '9e8d7c6', 'commit' => LATEST_SHA, 'built_at' => '2026-11-15T12:00:00Z']));
        expect(Artisan::call('p:monoforge:update', ['--check' => true]))->toBe(0)
            ->and(Artisan::output())->toContain('MonoForge build 9e8d7c6 is up to date.');

        Process::assertNothingRan();
    } finally {
        deleteDirectory($base);
    }
});

test('a stale unfinished run is recovered by bringing the panel back up', function (): void {
    $base = temporaryDirectory();
    $work = $base.'/work';
    mkdir($work);
    file_put_contents($work.'/run.json', json_encode(['backup' => null, 'down' => true, 'touched' => false]));
    file_put_contents($work.'/status.json', json_encode(['state' => 'running', 'step' => 'backing_up']));
    Process::fake();

    try {
        $service = updateService($base, $work);

        expect($service->status()['error'])->toContain('p:monoforge:recover');

        $status = $service->recoverIfStale();

        expect($status['message'] ?? null)->toBe('Recovered from an unfinished update.')
            ->and(is_file($work.'/run.json'))->toBeFalse()
            ->and($service->recoverIfStale())->toBeNull();
        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && in_array('up', $process->command, true));
    } finally {
        deleteDirectory($base);
    }
});
