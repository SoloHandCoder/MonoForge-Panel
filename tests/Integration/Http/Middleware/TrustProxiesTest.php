<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Http\Middleware\TrustProxiesTest;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

uses(HttpTestCase::class);

beforeEach(function (): void {
    Route::get('/_test/client-ip', fn (Request $request): ResponseFactory|Response => response((string) $request->ip()));
});

/**
 * @param  array<string, string>  $headers
 */
function clientIp(HttpTestCase $test, string $peer, array $headers = []): string
{
    return (string) $test->withServerVariables(['REMOTE_ADDR' => $peer])
        ->withHeaders($headers)
        ->get('/_test/client-ip')
        ->assertOk()
        ->getContent();
}

test('a loopback peer passes on the Cloudflare client address', function (string $peer): void {
    expect(clientIp($this, $peer, ['CF-Connecting-IP' => '203.0.113.7', 'X-Forwarded-For' => '198.51.100.1']))->toBe('203.0.113.7');
})->with(['127.0.0.1', '::1', '127.0.0.53']);

test('a loopback peer passes on an IPv6 Cloudflare client address', function (): void {
    expect(clientIp($this, '::1', ['CF-Connecting-IP' => '2001:db8::42']))->toBe('2001:db8::42');
});

test('a loopback peer falls back to X-Forwarded-For', function (): void {
    expect(clientIp($this, '127.0.0.1', ['X-Forwarded-For' => '198.51.100.1, 127.0.0.1']))->toBe('198.51.100.1');
});

test('an invalid Cloudflare header is ignored', function (): void {
    expect(clientIp($this, '127.0.0.1', ['CF-Connecting-IP' => 'not-an-ip']))->toBe('127.0.0.1');
});

test('an external peer cannot spoof its address', function (): void {
    expect(clientIp($this, '198.51.100.9', ['CF-Connecting-IP' => '203.0.113.7', 'X-Forwarded-For' => '203.0.113.8']))->toBe('198.51.100.9');
});

test('without forwarding headers the peer address is used', function (string $peer): void {
    expect(clientIp($this, $peer))->toBe($peer);
})->with(['127.0.0.1', '198.51.100.9', '2001:db8::9']);

test('a proxy listed in TRUSTED_PROXIES is trusted', function (): void {
    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);

    expect(clientIp($this, '10.1.2.3', ['CF-Connecting-IP' => '203.0.113.7']))->toBe('203.0.113.7')
        ->and(clientIp($this, '198.51.100.9', ['CF-Connecting-IP' => '203.0.113.7']))->toBe('198.51.100.9');
});
