<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Base;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\Unauthenticated;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Assistant\AssistantSettings;
use Pterodactyl\Services\Billing\StoreCatalogService;
use Pterodactyl\Support\LockedCache;

/**
 * The plans on sale for the public homepage. Readable without signing in, so it carries no node,
 * location or capacity detail, and it is cached for a minute so visitors cannot load the database.
 */
#[Group('Public', 'Endpoints that need no account, used by the public homepage.')]
#[Subgroup('Store', 'The plans for sale.')]
class PublicStorePlansController extends Controller
{
    public const string CACHE_KEY = 'monoforge:public:store-plans';

    public const int CACHE_SECONDS = 60;

    private const array EXAMPLE = [
        'enabled' => true,
        'assistant_enabled' => true,
        'periods' => [
            ['id' => 1, 'name' => 'Monthly', 'days' => 30],
            ['id' => 2, 'name' => 'Quarterly', 'days' => 90],
        ],
        'categories' => [[
            'name' => 'Minecraft',
            'slug' => 'minecraft',
            'description' => 'Java edition servers.',
            'plans' => [[
                'id' => 3,
                'name' => 'Stone',
                'description' => 'A small survival server.',
                'resources' => ['memory' => 2048, 'disk' => 10240, 'cpu' => 100, 'databases' => 1, 'backups' => 1],
                'games' => ['Paper', 'Vanilla'],
                'available' => true,
                'prices' => [
                    ['period_id' => 1, 'days' => 30, 'tokens' => 400],
                    ['period_id' => 2, 'days' => 90, 'tokens' => 1080],
                ],
            ]],
        ]],
    ];

    public function __construct(private readonly CacheRepository $cache) {}

    #[Unauthenticated]
    #[Endpoint('List the public store plans', 'Returns the plans on sale, grouped by category, with their resources, the games they run and their price in tokens for every enabled billing period on the cheapest place they can run. Only public nodes available in the shop count. Needs no account and is cached for 60 seconds.')]
    #[ResponseField('enabled', 'boolean', 'Whether the store is open. When it is closed the lists are empty.')]
    #[ResponseField('assistant_enabled', 'boolean', 'Whether the AI assistant is offered on the panel.')]
    #[ResponseField('periods', 'object[]', 'The enabled billing periods, in the store order.')]
    #[ResponseField('categories', 'object[]', 'The visible categories that have a plan on sale, each with its plans cheapest first.')]
    #[ResponseField('categories[].plans[].resources', 'object', 'Memory and disk in MiB, CPU in percent of one core, and the database and backup limits.')]
    #[ResponseField('categories[].plans[].games', 'string[]', 'The names of the games and server types the plan can run.')]
    #[ResponseField('categories[].plans[].available', 'boolean', 'False when the store has no capacity left for the plan right now.')]
    #[ResponseField('categories[].plans[].prices', 'object[]', 'The price in tokens per billing period, one entry per enabled period.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Plans returned.')]
    public function __invoke(StoreCatalogService $catalog): JsonResponse
    {
        // Built by one request at a time; the catalog is resolved per request, so it never reuses an older read.
        $payload = LockedCache::remember($this->cache, self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => [
            ...$catalog->publicPlans(),
            'assistant_enabled' => AssistantSettings::enabled(),
        ]);

        return new JsonResponse($payload, headers: ['Cache-Control' => 'public, max-age='.self::CACHE_SECONDS]);
    }

    /** Drops the cached payload, so an admin's change to the shop shows at once. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
