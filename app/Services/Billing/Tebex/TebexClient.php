<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing\Tebex;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Exceptions\Billing\TebexCheckoutException;

/**
 * The Tebex Headless API calls the token shop needs: create a basket and add one package to it.
 *
 * @see https://docs.tebex.io/developers/headless-api/endpoints
 */
readonly class TebexClient
{
    public const string BASE_URL = 'https://headless.tebex.io/api';

    private const string FAILED = "The store couldn't start the checkout. Please try again in a moment.";

    public function __construct(private TebexSettings $settings) {}

    /** Only send players to an https page on Tebex's own domain. */
    public static function isCheckoutUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? mb_strtolower((string) ($parts['host'] ?? '')) : '';

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ! isset($parts['user'])
            && ($host === 'tebex.io' || str_ends_with($host, '.tebex.io'));
    }

    /**
     * Create a basket for one package and return its ident and the Tebex checkout URL to send
     * the player to.
     *
     * @param  array<string, int|string>  $custom  echoed back by Tebex in the payment webhooks
     * @return array{ident: string, url: string}
     *
     * @throws TebexCheckoutException
     */
    public function checkout(string $packageId, array $custom, string $completeUrl, string $cancelUrl, ?string $ipAddress): array
    {
        $token = $this->settings->publicToken();
        throw_if($token === '', TebexCheckoutException::class, 'The token store is not set up yet.');

        $basket = $this->send('post', self::BASE_URL.'/accounts/'.rawurlencode($token).'/baskets', array_filter([
            'complete_url' => $completeUrl,
            'cancel_url' => $cancelUrl,
            'custom' => $custom,
            'complete_auto_redirect' => true,
            'ip_address' => $ipAddress,
        ], fn (mixed $value): bool => $value !== null), 'create basket');

        $ident = $basket['ident'] ?? null;
        throw_unless(is_string($ident) && $ident !== '', TebexCheckoutException::class, self::FAILED);

        $basket = $this->addPackage($token, $ident, $packageId, $custom);

        $links = $basket['links'] ?? null;
        $url = is_array($links) ? ($links['checkout'] ?? null) : null;
        throw_unless(is_string($url) && self::isCheckoutUrl($url), TebexCheckoutException::class, self::FAILED);

        return ['ident' => $ident, 'url' => $url];
    }

    /**
     * The endpoint reference documents the add-package path as `{base}/{basketIdent}/packages`
     * under `/accounts/{token}`, unlike every other basket call; Tebex's own SDK uses
     * `/baskets/{basketIdent}/packages`. A 404 or 405 means nothing was added, so the next
     * form is tried.
     *
     * @param  array<string, int|string>  $custom
     * @return array<string, mixed>
     *
     * @throws TebexCheckoutException
     */
    private function addPackage(string $token, string $ident, string $packageId, array $custom): array
    {
        $account = self::BASE_URL.'/accounts/'.rawurlencode($token);
        $candidates = [
            $account.'/'.rawurlencode($ident).'/packages',
            self::BASE_URL.'/baskets/'.rawurlencode($ident).'/packages',
            $account.'/baskets/'.rawurlencode($ident).'/packages',
        ];
        $body = ['package_id' => $packageId, 'quantity' => 1, 'custom' => $custom];

        foreach ($candidates as $index => $url) {
            $last = $index === count($candidates) - 1;
            $result = $this->send('post', $url, $body, 'add package', retryOnMissing: ! $last);
            if ($result !== null) {
                return $result;
            }
        }

        throw new TebexCheckoutException(self::FAILED);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return ($retryOnMissing is true ? array<string, mixed>|null : array<string, mixed>)
     *
     * @throws TebexCheckoutException
     */
    private function send(string $method, string $url, array $body, string $action, bool $retryOnMissing = false): ?array
    {
        try {
            $response = $this->request()->send(mb_strtoupper($method), $url, ['json' => $body]);
        } catch (ConnectionException) {
            Log::warning('Tebex '.$action.' failed: the API could not be reached.');

            throw new TebexCheckoutException(self::FAILED);
        }

        if ($retryOnMissing && in_array($response->status(), [404, 405], true)) {
            return null;
        }

        if (! $response->successful()) {
            $this->logFailure($action, $response);

            throw new TebexCheckoutException(self::FAILED);
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            Log::warning('Tebex '.$action.' returned no basket.');

            throw new TebexCheckoutException(self::FAILED);
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function request(): PendingRequest
    {
        $request = Http::acceptJson()->asJson()->timeout(10)->connectTimeout(5);

        // Basic auth is the public token and the private key; most Headless calls work without it.
        $privateKey = $this->settings->privateKey();

        return $privateKey === '' ? $request : $request->withBasicAuth($this->settings->publicToken(), $privateKey);
    }

    private function logFailure(string $action, Response $response): void
    {
        // Only the status and Tebex's own error text: never the URL (it holds the token) or credentials.
        $detail = $response->json('detail') ?? $response->json('error_message') ?? $response->json('title');

        Log::warning('Tebex '.$action.' failed.', [
            'status' => $response->status(),
            'detail' => is_string($detail) ? mb_substr($detail, 0, 300) : null,
        ]);
    }
}
