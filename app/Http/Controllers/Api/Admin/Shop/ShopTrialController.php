<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Shop;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Shop\GetShopRequest;
use Pterodactyl\Http\Requests\Api\Admin\Shop\UpdateShopTrialsRequest;
use Pterodactyl\Models\Billing\ShopTrial;
use Pterodactyl\Services\Billing\TrialService;
use Pterodactyl\Services\Billing\TrialSettings;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Shop', 'The token shop catalog: categories, plans, billing periods, price multipliers, add-ons and limits.')]
class ShopTrialController extends AdminApiController
{
    /** The most recent trials the list shows. */
    public const int LIST_LIMIT = 200;

    private const array SETTINGS_EXAMPLE = [
        'enabled' => true,
        'plan_ids' => [3],
        'hours' => 48,
        'max_concurrent' => 10,
        'require_verified' => true,
    ];

    private const array INDEX_EXAMPLE = [
        'settings' => self::SETTINGS_EXAMPLE,
        'stats' => ['started' => 40, 'running' => 3, 'converted' => 11, 'lapsed' => 26, 'conversion_rate' => 29.7],
        'trials' => [[
            'id' => 12,
            'state' => 'running',
            'user' => ['id' => 7, 'username' => 'player'],
            'server' => ['id' => 21, 'identifier' => '1a7ce997', 'name' => 'Survival'],
            'plan' => ['id' => 3, 'name' => 'Stone'],
            'hours' => 48,
            'started_at' => '2026-11-16T12:00:00+00:00',
            'ends_at' => '2026-11-18T12:00:00+00:00',
            'converted_at' => null,
            'expired_at' => null,
        ]],
    ];

    public function __construct(private readonly TrialService $trials) {}

    #[Endpoint('Get free trials', 'Returns the free trial settings, the trial numbers with the conversion rate (trials kept / trials that are over), and the most recent 200 trials.')]
    #[ResponseField('stats.conversion_rate', 'number', 'Percent of the trials that are over (kept, ended or deleted) that were kept.')]
    #[ResponseField('trials', 'object[]', 'The most recent trials; state is running, expired (ended, suspended until it is deleted), converted or deleted. A user or plan that was deleted since is null.')]
    #[ScribeResponse(self::INDEX_EXAMPLE, description: 'Trials returned.')]
    public function index(GetShopRequest $request): JsonResponse
    {
        $trials = ShopTrial::query()
            ->with(['user', 'server', 'plan'])
            ->latest('id')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (ShopTrial $trial): array => [
                'id' => $trial->id,
                'state' => $trial->state(),
                'user' => $trial->user === null ? null : ['id' => $trial->user->id, 'username' => $trial->user->username],
                'server' => $trial->server === null ? null : ['id' => $trial->server->id, 'identifier' => $trial->server->uuidShort, 'name' => $trial->server->name],
                'plan' => $trial->plan === null ? null : ['id' => $trial->plan->id, 'name' => $trial->plan->name],
                'hours' => $trial->hours,
                'started_at' => $trial->created_at?->toAtomString(),
                'ends_at' => $trial->ends_at->toAtomString(),
                'converted_at' => $trial->converted_at?->toAtomString(),
                'expired_at' => $trial->expired_at?->toAtomString(),
            ]);

        return new JsonResponse([
            'settings' => TrialSettings::all(),
            'stats' => $this->trials->stats(),
            'trials' => $trials->all(),
        ]);
    }

    #[Endpoint('Update free trial settings', 'Turns free trial servers on or off and sets the plans that can be tried, the trial length (24, 48 or 72 hours), the most trials running at once panel-wide, and whether an account needs a linked Discord or Google account.')]
    #[ScribeResponse(self::SETTINGS_EXAMPLE, description: 'Trial settings updated.')]
    public function update(UpdateShopTrialsRequest $request): JsonResponse
    {
        $settings = $request->settings();
        TrialSettings::store($settings);

        Activity::event('admin:shop.trials.update')
            ->property('enabled', $settings['enabled'] ? 'on' : 'off')
            ->property('hours', $settings['hours'])
            ->property('max_concurrent', $settings['max_concurrent'])
            ->log();

        return new JsonResponse(TrialSettings::all());
    }
}
