<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Plugins;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Pterodactyl\Enum\MarketplaceSource;
use Pterodactyl\Exceptions\Plugins\MarketplaceException;

/**
 * The HTTP side of the catalogs: every call carries the panel's User-Agent (Modrinth asks for
 * one that identifies the app), is rate limited panel-wide per catalog, and lookups are cached
 * for ten minutes so a busy panel asks each catalog once per query.
 */
final class MarketplaceHttp
{
    public const string USER_AGENT = 'MonoForge-Panel/1.0 (+https://panel.monoforge.net)';

    public const int CACHE_SECONDS = 600;

    /** Calls a minute the whole panel makes to each catalog (Modrinth allows 300 an IP). */
    private const array PER_MINUTE = ['modrinth' => 200, 'hangar' => 100, 'curseforge' => 100];

    /** Each user's share of that budget, so one user cannot use it up for everyone. */
    private const array PER_USER_MINUTE = ['modrinth' => 40, 'hangar' => 20, 'curseforge' => 20];

    /** @param  array<string, string>  $headers */
    public function client(MarketplaceSource $source, array $headers = []): PendingRequest
    {
        return Http::withUserAgent(self::USER_AGENT)
            ->acceptJson()
            ->withHeaders($headers)
            ->timeout(15)
            ->connectTimeout(5);
    }

    /**
     * Sends a request and returns the decoded JSON, or null for a 404 when $nullOn404.
     *
     * @param  array<string, mixed>  $query
     * @param  array<mixed>|null  $json
     * @param  array<string, string>  $headers
     * @return array<mixed>|null
     *
     * @throws MarketplaceException
     */
    public function json(MarketplaceSource $source, string $method, string $url, array $query = [], ?array $json = null, array $headers = [], bool $nullOn404 = false): ?array
    {
        $this->throttle($source);

        try {
            $request = $this->client($source, $headers);
            /** @var Response $response */
            $response = $json === null
                ? $request->send($method, $url, ['query' => $query])
                : $request->send($method, $url, ['query' => $query, 'json' => $json]);
        } catch (ConnectionException) {
            throw MarketplaceException::unavailable($source);
        }

        if ($nullOn404 && $response->status() === 404) {
            return null;
        }

        if ($response->status() === 429) {
            throw MarketplaceException::busy($source);
        }

        if (! $response->successful()) {
            throw MarketplaceException::unavailable($source);
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw MarketplaceException::unavailable($source);
        }

        return $data;
    }

    /**
     * @template T
     *
     * @param  array<mixed>  $key
     * @param  Closure(): T  $callback
     * @return T
     */
    public function remember(MarketplaceSource $source, string $what, array $key, Closure $callback): mixed
    {
        $cacheKey = 'marketplace:'.$source->value.':'.$what.':'.hash('sha256', json_encode($key, JSON_THROW_ON_ERROR));

        return Cache::remember($cacheKey, self::CACHE_SECONDS, $callback);
    }

    private function throttle(MarketplaceSource $source): void
    {
        $user = Auth::id();
        if ($user !== null) {
            $allowed = RateLimiter::attempt('marketplace:'.$source->value.':user:'.$user, self::PER_USER_MINUTE[$source->value], fn (): bool => true, 60);
            if ($allowed === false) {
                throw MarketplaceException::busy($source);
            }
        }

        $allowed = RateLimiter::attempt('marketplace:'.$source->value, self::PER_MINUTE[$source->value], fn (): bool => true, 60);

        if ($allowed === false) {
            throw MarketplaceException::busy($source);
        }
    }
}
