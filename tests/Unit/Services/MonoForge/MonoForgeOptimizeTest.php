<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\MonoForge\MonoForgeOptimizeTest;

use FilesystemIterator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
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
const DOWNLOAD_URL = 'https://github.com/SoloHandCoder/MonoForge-Panel/releases/download/build-9e8d7c6/';
const TARBALL = 'the new build';

function temporaryDirectory(): string
{
    $path = sys_get_temp_dir().'/monoforge-optimize-test-'.Str::random(8);
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

/**
 * @param  list<string>  $command
 */
function artisanCommand(array $command): ?string
{
    $index = array_search('artisan', array_map(basename(...), $command), true);

    return $index === false ? null : ($command[$index + 1] ?? null);
}

/**
 * Fakes every process the updater starts. tar really "unpacks" a minimal build and writes
 * the code backup; artisan commands are recorded, and those in $failing fail.
 *
 * @param  list<string>  $failing
 * @param  list<string>  $artisan  the artisan commands that ran, in order
 */
function fakeProcesses(array $failing, array &$artisan): void
{
    Process::fake(function (PendingProcess $process) use ($failing, &$artisan): ProcessResult {
        /** @var list<string> $command */
        $command = is_array($process->command) ? array_values($process->command) : [];
        $name = artisanCommand($command);
        if ($name !== null) {
            $artisan[] = $name;

            return in_array($name, $failing, true)
                ? Process::result(errorOutput: "{$name} broke", exitCode: 1)
                : Process::result();
        }

        if (($command[0] ?? null) === 'tar') {
            if ($command[1] === '-tzf') {
                return Process::result(implode(PHP_EOL, ['artisan', 'version.json']));
            }

            if ($command[1] === '-tvzf') {
                return Process::result(implode(PHP_EOL, ['-rw-r--r-- 0/0 1 2026-11-15 12:00 artisan', '-rw-r--r-- 0/0 1 2026-11-15 12:00 version.json']));
            }

            if ($command[1] === '-xzf') {
                $target = $command[4];
                @mkdir($target.'/vendor', 0o755, true);
                @mkdir($target.'/public', 0o755, true);
                file_put_contents($target.'/artisan', 'new');
                file_put_contents($target.'/vendor/autoload.php', '<?php');
                file_put_contents($target.'/public/index.php', '<?php');
                file_put_contents($target.'/version.json', json_encode(['version' => '9e8d7c6', 'commit' => LATEST_SHA]));
            }

            if ($command[1] === '-czf') {
                file_put_contents($command[2], 'backup');
            }
        }

        return Process::result();
    });
}

beforeEach(function (): void {
    config(['monoforge.update.mode' => 'notify', 'monoforge.update.repo' => REPO, 'database.default' => 'sqlite']);
    $this->base = temporaryDirectory();
    file_put_contents($this->base.'/artisan', 'old');
    file_put_contents($this->base.'/version.json', json_encode(['version' => '3f9c2ab', 'commit' => CURRENT_SHA]));
    Http::fake([
        LATEST_URL => Http::response([
            'tag_name' => 'build-9e8d7c6',
            'target_commitish' => LATEST_SHA,
            'published_at' => '2026-11-15T12:00:00Z',
            'assets' => [['name' => 'monoforge-panel.tar.gz'], ['name' => 'monoforge-panel.tar.gz.sha256'], ['name' => 'version.json']],
        ]),
        'https://api.github.com/repos/SoloHandCoder/MonoForge-Panel/compare/*' => Http::response(['status' => 'ahead']),
        DOWNLOAD_URL.'monoforge-panel.tar.gz.sha256' => Http::response(hash('sha256', TARBALL).'  monoforge-panel.tar.gz'),
        DOWNLOAD_URL.'monoforge-panel.tar.gz' => Http::response(TARBALL),
    ]);
});

afterEach(function (): void {
    deleteDirectory($this->base);
});

test('a successful update caches config, routes, events and views after migrating', function (): void {
    $artisan = [];
    fakeProcesses([], $artisan);

    $status = (new MonoForgeUpdateService(new Repository(new ArrayStore), $this->base, $this->base.'/work'))->apply();

    expect($status['state'])->toBe('success')
        ->and($status['message'])->toBe('Updated MonoForge from build 3f9c2ab to 9e8d7c6.')
        ->and(file_get_contents($this->base.'/artisan'))->toBe('new')
        ->and($artisan)->toBe(['down', 'migrate', 'optimize:clear', 'optimize', 'queue:restart', 'up']);
});

test('an update whose caching fails clears the caches and still finishes', function (): void {
    $artisan = [];
    fakeProcesses(['optimize'], $artisan);

    $status = (new MonoForgeUpdateService(new Repository(new ArrayStore), $this->base, $this->base.'/work'))->apply();

    expect($status['state'])->toBe('success')
        ->and($status['message'])->toContain('The panel caches could not be built (php artisan optimize failed: optimize broke), so the panel runs without them.')
        ->and(file_get_contents($this->base.'/artisan'))->toBe('new')
        ->and($artisan)->toBe(['down', 'migrate', 'optimize:clear', 'optimize', 'optimize:clear', 'queue:restart', 'up']);
});

test('optimize never throws, even when clearing the caches fails too', function (): void {
    $artisan = [];
    fakeProcesses(['optimize', 'optimize:clear'], $artisan);

    $warning = (new MonoForgeUpdateService(new Repository(new ArrayStore), $this->base, $this->base.'/work'))->optimize();

    expect($warning)->toContain('could not be built or cleared')->toContain('php artisan optimize:clear')
        ->and($artisan)->toBe(['optimize', 'optimize:clear']);
});

test('optimize runs artisan optimize in a fresh process of the panel', function (): void {
    $artisan = [];
    fakeProcesses([], $artisan);

    expect((new MonoForgeUpdateService(new Repository(new ArrayStore), $this->base, $this->base.'/work'))->optimize())->toBeNull();

    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && in_array($this->base.'/artisan', $process->command, true)
        && in_array('optimize', $process->command, true)
        && in_array('--no-interaction', $process->command, true));
});

test('the optimize command reports success and failure', function (): void {
    $this->app->instance(MonoForgeUpdateService::class, new MonoForgeUpdateService(new Repository(new ArrayStore), $this->base, $this->base.'/work'));

    $artisan = [];
    fakeProcesses([], $artisan);
    expect(Artisan::call('p:monoforge:optimize'))->toBe(0)
        ->and(Artisan::output())->toContain('Cached the panel config, routes, events and views.');

    $artisan = [];
    fakeProcesses(['optimize'], $artisan);
    expect(Artisan::call('p:monoforge:optimize'))->toBe(1)
        ->and(Artisan::output())->toContain('could not be built');
});
