<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\Helpers\SoftwareVersionServiceTest;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Pterodactyl\Services\Helpers\SoftwareVersionService;
use Pterodactyl\Tests\TestCase;
use RuntimeException;

uses(TestCase::class);
test('normalizes version service response fields', function (): void {
    $service = service(['panel' => '2.1.0', 'wings' => '1.12.0', 'discord' => 'https://example.com/discord', 'donations' => 'https://example.com/donate']);
    // The panel version comes from MonoForge's own builds, never the Pterodactyl CDN.
    expect($service->getPanel())->toBe(config('app.version'));
    expect($service->getDaemon())->toBe('1.12.0');
    expect($service->getDiscord())->toBe('https://example.com/discord');
    expect($service->getDonations())->toBe('https://example.com/donate');
});
test('ignores version fields with invalid types', function (): void {
    $service = service(['panel' => ['invalid'], 'wings' => '1.12.0']);
    expect($service->getPanel())->toBe(config('app.version'));
    expect($service->getDaemon())->toBe('1.12.0');
});
test('makes no request and reports the panel as current when no CDN URL is set', function (): void {
    config(['pterodactyl.cdn.url' => '']);
    $requests = 0;
    $client = new Client(['handler' => function () use (&$requests): never {
        $requests++;

        throw new RuntimeException('No request should be made.');
    }]);
    $service = new SoftwareVersionService(new Repository(new ArrayStore), $client);

    expect($service->isLatestPanel())->toBeTrue()
        ->and($service->getPanel())->toBe(config('app.version'))
        ->and($service->getDaemon())->toBe('')
        ->and($service->getDiscord())->toBe('')
        ->and($service->getDonations())->toBe('')
        ->and($requests)->toBe(0);
});
/** @param array<string, mixed> $payload */
function service(array $payload): SoftwareVersionService
{
    config(['pterodactyl.cdn.url' => 'https://cdn.example.com/latest.json']);
    $cache = new Repository(new ArrayStore);
    $client = new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, body: json_encode($payload, JSON_THROW_ON_ERROR)),
    ]))]);

    return new SoftwareVersionService($cache, $client);
}
