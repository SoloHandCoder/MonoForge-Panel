<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Helpers;

use Carbon\CarbonImmutable;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Pterodactyl\Exceptions\Service\Helper\CdnVersionFetchingException;
use Pterodactyl\Services\MonoForge\MonoForgeUpdateService;

class SoftwareVersionService
{
    public const string VERSION_CACHE_KEY = 'pterodactyl:versioning_data';

    /** @var SoftwareVersionData|null */
    private ?array $result = null;

    /**
     * SoftwareVersionService constructor.
     */
    public function __construct(
        protected CacheRepository $cache,
        protected Client $client,
    ) {}

    /**
     * The MonoForge build that is running: its short commit, or the app version when unknown.
     */
    public function getCurrentPanel(): string
    {
        return $this->updates()->currentVersion();
    }

    /**
     * The latest MonoForge build on GitHub, or the running build when it cannot be determined.
     */
    public function getPanel(): string
    {
        $updates = $this->updates();
        $latest = $updates->mode() === 'off' ? null : $updates->latest();

        return $latest?->build->version ?? $updates->currentVersion();
    }

    /**
     * Get the latest version of the daemon from the CDN servers, or an empty string when it is unknown.
     */
    public function getDaemon(): string
    {
        return $this->versionData()['wings'] ?? '';
    }

    /**
     * Get the URL to the discord server.
     */
    public function getDiscord(): string
    {
        return $this->versionData()['discord'] ?? '';
    }

    /**
     * Get the URL for donations.
     */
    public function getDonations(): string
    {
        return $this->versionData()['donations'] ?? '';
    }

    /**
     * Determine if the running MonoForge build is the latest one.
     */
    public function isLatestPanel(): bool
    {
        return ! $this->updates()->isUpdateAvailable();
    }

    protected function updates(): MonoForgeUpdateService
    {
        return resolve(MonoForgeUpdateService::class);
    }

    /**
     * Keeps the versioning cache up-to-date with the latest results from the CDN.
     *
     * @return SoftwareVersionData
     */
    protected function versionData(): array
    {
        $this->result ??= $this->cacheVersionData();

        return $this->result;
    }

    /**
     * @return SoftwareVersionData
     */
    protected function cacheVersionData(): array
    {
        $url = config()->string('pterodactyl.cdn.url', '');
        if ($url === '') {
            return [];
        }

        $cached = $this->cache->remember(self::VERSION_CACHE_KEY, CarbonImmutable::now()->addMinutes(config()->integer('pterodactyl.cdn.cache_time', 60)), function () use ($url): array {
            try {
                $response = $this->client->request('GET', $url, ['timeout' => 5, 'connect_timeout' => 2]);

                if ($response->getStatusCode() === 200) {
                    $data = json_decode($response->getBody()->__toString(), true, flags: JSON_THROW_ON_ERROR);

                    return is_array($data) ? $data : [];
                }

                throw new CdnVersionFetchingException;
            } catch (Exception) {
                return [];
            }
        });

        return $this->normalizeVersionData($cached);
    }

    /**
     * @return SoftwareVersionData
     */
    private function normalizeVersionData(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach (['panel', 'wings', 'discord', 'donations'] as $key) {
            $item = $value[$key] ?? null;
            if (is_string($item)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }
}
