<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Extensions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Events\Extensions\ExtensionLoadFailed;
use Pterodactyl\Exceptions\Extensions\InvalidExtensionException;
use Pterodactyl\Models\Extension;
use Pterodactyl\Services\Themes\ThemeDefinition;
use Pterodactyl\Services\Themes\ThemeSetting;
use ReflectionClass;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

class ExtensionRepository
{
    /** Cache keys of validated manifests, one per extension directory. */
    private const string MANIFEST_CACHE_PREFIX = 'extensions:manifest:';

    /** Set once the extensions table is known to exist. */
    private const string TABLE_CACHE_KEY = 'extensions:records-table';

    /** @var Collection<string, ExtensionManifest>|null */
    private ?Collection $discovered = null;

    /** @var Collection<string, ExtensionManifest>|null */
    private ?Collection $enabled = null;

    /** @var array<string, string> in-memory discovery errors keyed by directory name */
    private array $discoveryErrors = [];

    /** @var array<string, ExtensionSettings> */
    private array $settings = [];

    /** @var Collection<string, Extension>|null the records last read, which clearErrors() checks before querying */
    private ?Collection $lastRecords = null;

    /** @var array<string, true> Provider failures in this application instance, retained across discovery refreshes. */
    private array $failedProviders = [];

    public function __construct(
        private readonly ExtensionManifestValidator $validator,
        private readonly ExtensionAssetPublisher $assets,
        private readonly Dispatcher $events,
        private readonly ExtensionSettingsRegistry $settingsRegistry,
        private readonly ExtensionCompatibility $compatibility,
    ) {}

    public function directory(): string
    {
        return mb_rtrim(config()->string('extensions.directory'), '/\\');
    }

    /**
     * @return Collection<string, ExtensionManifest>
     */
    public function discovered(): Collection
    {
        if ($this->discovered instanceof Collection) {
            return $this->discovered;
        }

        $manifests = [];
        foreach (glob($this->directory().'/*/'.ExtensionManifest::FILENAME) ?: [] as $file) {
            $directory = dirname($file);

            try {
                $manifest = $this->manifest($directory);
                if ($manifest->id !== basename($directory)) {
                    throw new InvalidExtensionException(sprintf('Extension directory "%s" must match its manifest id "%s".', basename($directory), $manifest->id));
                }

                $manifests[$manifest->id] = $manifest;
            } catch (InvalidExtensionException $exception) {
                $this->discoveryErrors[basename($directory)] = $exception->getMessage();
                Log::warning('[extensions] Skipping extension', ['directory' => $directory, 'exception' => $exception]);
            }
        }

        return $this->discovered = collect($manifests);
    }

    /**
     * @return array<string, string>
     */
    public function discoveryErrors(): array
    {
        return $this->discoveryErrors;
    }

    public function flushDiscovery(): void
    {
        $this->discovered = null;
        $this->enabled = null;
        $this->discoveryErrors = [];
    }

    /**
     * @return Collection<string, Extension>
     */
    public function records(): Collection
    {
        return $this->lastRecords = rescue(function (): Collection {
            if (! $this->hasRecordsTable()) {
                return $this->recordCollection([]);
            }

            $records = [];
            foreach (Extension::query()->get() as $extension) {
                $records[$extension->identifier] = $extension;
            }

            return $this->recordCollection($records);
        }, fn (): Collection => $this->recordCollection([]));
    }

    /**
     * @return Collection<string, ExtensionManifest>
     */
    public function enabled(): Collection
    {
        if ($this->enabled instanceof Collection) {
            return $this->enabled;
        }

        $configured = $this->configuredEnabled()->reject(fn (ExtensionManifest $manifest): bool => isset($this->failedProviders[$manifest->id]));
        $result = $this->compatibility->resolve($configured);
        foreach ($result['errors'] as $identifier => $reason) {
            $this->recordFailure($identifier, $reason, phase: 'compatibility');
        }

        return $this->enabled = $result['manifests'];
    }

    /** Whether an extension's runtime registrations may run right now. */
    public function isAvailable(string $identifier): bool
    {
        return config('extensions.enabled') && $this->enabled()->has($identifier);
    }

    /** @return Collection<string, ExtensionManifest> */
    public function configuredEnabled(): Collection
    {
        $discovered = $this->discovered();
        if ($discovered->isEmpty()) {
            return $discovered;
        }

        $records = $this->records();

        return $discovered->filter(function (ExtensionManifest $manifest) use ($records): bool {
            $record = $records->get($manifest->id);

            return $record !== null && $record->enabled;
        });
    }

    /**
     * The enabled frontend extensions for the SPA to load. Signed-in users receive
     * each extension's frontend settings; everyone else only those marked ->public().
     *
     * Guests only need what renders on the sign-in pages: the bundle, its styles and
     * translations, and public settings. Versions, screens and view overrides are
     * left out so the login page does not describe the panel's extensions, and the
     * SPA skips screen and view registrations for an entry that has none. Of the
     * themes, only those of the chain the page renders with come along, marked
     * `theme`, so their setup runs while they are active.
     *
     * @param  list<string>  $themes  the ids of the theme chain the page renders with
     * @return array<int, array{id: string, version?: string, entry: string, prefix: string|null, translations: string|null, config: object, screens?: list<ExtensionScreenDefinition>, views?: list<string>, theme?: bool, development: array{url: string, version: string, scripts: string}|null}>
     */
    public function frontendPayload(bool $authenticated, array $themes = []): array
    {
        $enabled = $this->enabled()->filter(fn (ExtensionManifest $manifest): bool => $manifest->hasUi() && (! $manifest->isTheme() || in_array($manifest->id, $themes, true)));
        $configured = $authenticated ? $enabled : $enabled->filter(fn (ExtensionManifest $manifest): bool => ($this->settingsRegistry->get($manifest->id)?->publicConfigKeys() ?? []) !== []);
        if ($configured->isNotEmpty()) {
            rescue(fn () => ExtensionSettings::preload(array_values($configured->map(fn (ExtensionManifest $manifest): ExtensionSettings => $this->settings($manifest->id))->all())), report: false);
        }

        return $enabled->map(function (ExtensionManifest $manifest) use ($authenticated): array {
            $entry = [
                'id' => $manifest->id,
                'entry' => $this->assets->entryUrl($manifest),
                'prefix' => $manifest->uiPrefix,
                'translations' => $this->translationsRevision($manifest),
                'config' => (object) $this->frontendConfig($manifest->id, ! $authenticated),
                'development' => $this->assets->developmentPayload($manifest->id),
            ];

            if ($authenticated) {
                $entry['version'] = $manifest->version;
                $entry['screens'] = $manifest->screens;
                $entry['views'] = $manifest->views;
            }

            if ($manifest->isTheme()) {
                $entry['theme'] = true;
            }

            return $entry;
        })
            ->values()
            ->all();
    }

    public function recordFailure(string $identifier, string $reason, ?Throwable $exception = null, string $phase = 'runtime'): void
    {
        // The records read earlier no longer show this failure.
        $this->lastRecords = null;

        if (in_array($phase, ['register', 'boot'], true)) {
            $this->failedProviders[$identifier] = true;
            $this->enabled = null;
        }

        // A failure that repeats on every request (reading field values, for one) is written once.
        rescue(fn (): int => Extension::query()
            ->where('identifier', $identifier)
            ->where(fn (Builder $query): Builder => $query->whereNull('error')->orWhere('error', '<>', $reason))
            ->update(['error' => $reason, 'error_phase' => $phase, 'errored_at' => now()]), report: false);

        $context = array_filter(['exception' => $exception]);
        $exception instanceof Throwable
            ? Log::error("[extensions] Failed to load \"{$identifier}\": {$reason}", $context)
            : Log::warning("[extensions] Skipping \"{$identifier}\": {$reason}", $context);

        rescue(fn () => $this->events->dispatch(new ExtensionLoadFailed($identifier, $phase, $reason, $exception)));
    }

    /**
     * Clears the load failures of extensions that have just loaded cleanly. Failures
     * at runtime are kept, so they are still there for an administrator to read.
     *
     * @param  list<string>  $identifiers
     */
    public function clearErrors(array $identifiers): void
    {
        if ($identifiers === []) {
            return;
        }

        foreach ($identifiers as $identifier) {
            if (! isset($this->failedProviders[$identifier])) {
                continue;
            }

            unset($this->failedProviders[$identifier]);
            $this->enabled = null;
        }

        // The records this request read while booting already show whether there is a load
        // failure to clear; without them, ask the database.
        if ($this->lastRecords instanceof Collection && $this->lastRecords->doesntContain(fn (Extension $record): bool => in_array($record->identifier, $identifiers, true)
            && $record->error !== null
            && ($record->error_phase === null || in_array($record->error_phase, Extension::LOAD_PHASES, true)))) {
            return;
        }

        // Runs on every boot, so it only writes when there is a load failure to clear.
        rescue(function () use ($identifiers): void {
            $loadFailures = Extension::query()
                ->whereIn('identifier', $identifiers)
                ->whereNotNull('error')
                ->where(fn (Builder $query): Builder => $query->whereNull('error_phase')->orWhereIn('error_phase', Extension::LOAD_PHASES));

            if ($loadFailures->exists()) {
                $loadFailures->update(Extension::NO_ERROR);
            }
        }, report: false);
    }

    public function settings(string $identifier): ExtensionSettings
    {
        // One instance per extension per request so the per-instance row cache
        // in ExtensionSettings actually coalesces reads.
        return $this->settings[$identifier] ??= new ExtensionSettings($identifier);
    }

    /**
     * The manifest of an extension directory. Validating a manifest runs the full validator,
     * which costs milliseconds on every request, so a valid one is kept in the cache store
     * under a fingerprint of everything it was built from: its manifest and composer.json
     * contents and the panel code that parses them. Changing any of these validates it again.
     *
     * @throws InvalidExtensionException
     */
    private function manifest(string $directory): ExtensionManifest
    {
        $fingerprint = $this->manifestFingerprint($directory);
        $key = self::MANIFEST_CACHE_PREFIX.hash('xxh128', $directory);

        if ($fingerprint !== null) {
            $cached = rescue(fn (): mixed => Cache::get($key), report: false);
            if (is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint && ($cached['manifest'] ?? null) instanceof ExtensionManifest) {
                return $cached['manifest'];
            }
        }

        $manifest = $this->validator->fromDirectory($directory);

        // Root path prefixes are also checked against the files in public/, which the fingerprint does not cover.
        if ($fingerprint !== null && $manifest->rootPrefixes === []) {
            rescue(fn (): bool => Cache::put($key, ['fingerprint' => $fingerprint, 'manifest' => $manifest], now()->addDay()), report: false);
        }

        return $manifest;
    }

    /**
     * Null when the manifest cannot be read, which leaves reporting that to the validator.
     */
    private function manifestFingerprint(string $directory): ?string
    {
        $manifest = @file_get_contents($directory.DIRECTORY_SEPARATOR.ExtensionManifest::FILENAME);
        if ($manifest === false) {
            return null;
        }

        $composer = $directory.DIRECTORY_SEPARATOR.'composer.json';
        $code = array_map(function (string $class): string {
            $file = (new ReflectionClass($class))->getFileName();

            return $file === false ? $class : $file.':'.@filemtime($file).':'.@filesize($file);
        }, [ExtensionManifest::class, ExtensionManifestValidator::class, ThemeDefinition::class, ThemeSetting::class]);

        return hash('xxh128', implode("\0", [
            config()->string('app.version', ''),
            ...$code,
            $manifest,
            is_file($composer) ? (string) @file_get_contents($composer) : '',
        ]));
    }

    /**
     * Every request reads the extension records while it boots, so once the table exists
     * that is remembered in the cache store rather than asked of the database each time.
     */
    private function hasRecordsTable(): bool
    {
        if (rescue(fn (): mixed => Cache::get(self::TABLE_CACHE_KEY), report: false) === true) {
            return true;
        }

        $exists = Schema::hasTable('extensions');
        if ($exists) {
            rescue(fn (): bool => Cache::put(self::TABLE_CACHE_KEY, true, now()->addDay()), report: false);
        }

        return $exists;
    }

    /**
     * Changes whenever the extension's translation files do. The frontend puts it in the
     * URL it loads those translations from, which lets the browser cache them for as long
     * as core translations and still pick up an installed or upgraded extension at once.
     */
    private function translationsRevision(ExtensionManifest $manifest): ?string
    {
        $directory = $manifest->path('resources', 'lang');
        if (! is_dir($directory)) {
            return null;
        }

        $files = array_map(
            fn (SplFileInfo $file): string => implode("\0", [$file->getRelativePathname(), $file->getSize(), $file->getMTime()]),
            File::allFiles($directory),
        );
        sort($files);

        return hash('xxh128', $manifest->version."\0".implode("\0", $files));
    }

    /**
     * @param  array<string, Extension>  $records
     * @return Collection<string, Extension>
     */
    private function recordCollection(array $records): Collection
    {
        return collect($records);
    }

    /**
     * The ->frontend()-marked settings for one extension, exposed to its bundle
     * as ctx.config; with $publicOnly just the ->public() ones, for guests.
     * Never allowed to break page rendering.
     *
     * @return array<string, mixed>
     */
    private function frontendConfig(string $identifier, bool $publicOnly): array
    {
        return rescue(fn (): array => $this->settingsRegistry->get($identifier)?->frontendConfig($publicOnly) ?? [], []);
    }
}
