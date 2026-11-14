<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\Notices\ActionUrlTest;

use Pterodactyl\Services\Notices\ActionUrl;

dataset('safeUrls', fn (): array => [
    ['/account'],
    ['/account?tab=billing#top'],
    ['https://monoforge.net/billing'],
    ['http://monoforge.net'],
]);

dataset('unsafeUrls', fn (): array => [
    [null],
    [''],
    ['//evil.example'],
    ['/\\evil.example'],
    ["/\t/evil.example"],
    ["/\n/evil.example"],
    ['/ /evil.example'],
    ['javascript:alert(1)'],
    ['data:text/html,hi'],
    ['account'],
    ['https://'],
    ['/'.str_repeat('a', 2048)],
]);

test('keeps panel paths and http links', function (string $url): void {
    expect(ActionUrl::sanitize($url))->toBe($url);
})->with('safeUrls');

test('drops links a browser would send off-site or run', function (?string $url): void {
    expect(ActionUrl::sanitize($url))->toBeNull();
})->with('unsafeUrls');
