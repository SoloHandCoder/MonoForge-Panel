<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\Assistant\PublicUrlTest;

use Pterodactyl\Services\Assistant\Search\PublicUrl;

test('only public, globally routable IPs are public', function (string $ip, bool $public): void {
    expect(PublicUrl::isPublicIp($ip))->toBe($public);
})->with([
    ['93.184.216.34', true],
    ['2606:4700:4700::1111', true],
    ['127.0.0.1', false],
    ['10.0.0.1', false],
    ['172.16.5.4', false],
    ['192.168.1.1', false],
    ['169.254.169.254', false],
    ['100.64.0.1', false],
    ['0.0.0.0', false],
    ['::1', false],
    ['fe80::1', false],
    ['fd00::1', false],
    ['::ffff:127.0.0.1', false],
    ['not an ip', false],
]);

test('a URL is allowed only as public https on port 443', function (string $url, bool $allowed): void {
    $urls = new PublicUrl(fn (string $host): array => match ($host) {
        'public.example.com' => ['93.184.216.34'],
        'mixed.example.com' => ['93.184.216.34', '10.0.0.1'],
        default => [],
    });

    expect($urls->isAllowed($url))->toBe($allowed);
})->with([
    ['https://public.example.com/search', true],
    ['https://public.example.com:443/search', true],
    ['https://93.184.216.34/search', true],
    ['http://public.example.com/search', false],
    ['https://public.example.com:8443/search', false],
    ['https://user@public.example.com/search', false],
    ['https://mixed.example.com/search', false],
    ['https://unknown.example.com/search', false],
    ['https://localhost/search', false],
    ['https://api.internal/search', false],
    ['https://printer.local/search', false],
    ['https://[::1]/search', false],
    ['https://0x7f000001/search', false],
    ['https://017700000001/search', false],
    ['ftp://public.example.com/file', false],
    ['not a url', false],
]);

test('source links must be public http or https links', function (string $url, bool $shown): void {
    expect(PublicUrl::isPublicLink($url))->toBe($shown);
})->with([
    ['https://docs.papermc.io/paper', true],
    ['http://example.org/page', true],
    ['javascript:alert(1)', false],
    ['data:text/html,hi', false],
    ['http://10.0.0.5/admin', false],
    ['http://localhost:8080/', false],
    ['https://user:pass@example.org/', false],
    ['http://intranet/', false],
]);
