<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Assistant;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Assistant\GetAssistantUsageRequest;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiUsage;
use Pterodactyl\Models\User;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('AI Assistant', 'The admin AI assistant, its providers, settings and usage. Root administrators only, from a panel session.')]
class AssistantUsageController extends AdminApiController
{
    private const array EXAMPLE = [
        'period' => '2026-11',
        'data' => [[
            'user' => ['id' => 2, 'username' => 'alex', 'email' => 'alex@example.com'],
            'ai_tokens' => 64000,
            'free_tokens' => 50000,
            'panel_tokens_charged' => 14,
            'admin_ai_tokens' => 0,
            'calls' => 31,
        ]],
        'totals' => ['ai_tokens' => 64000, 'free_tokens' => 50000, 'panel_tokens_charged' => 14, 'admin_ai_tokens' => 0, 'calls' => 31],
    ];

    #[Endpoint('Get AI assistant usage', "Returns the AI tokens each user used in a month (YYYY-MM, this month by default): in total, from their free allowance, the panel tokens charged for the rest, and the admin assistant's tokens, which are never charged. Sorted by AI tokens, highest first.")]
    #[QueryParam('period', 'string', 'The month, as YYYY-MM.', required: false, example: '2026-11')]
    #[ScribeResponse(self::EXAMPLE, description: 'Usage returned.')]
    public function __invoke(GetAssistantUsageRequest $request): JsonResponse
    {
        $period = $request->period();

        $rows = AiUsage::query()
            ->where('period', $period)
            ->groupBy('user_id')
            ->select('user_id')
            ->selectRaw('SUM(ai_tokens) AS ai_tokens')
            ->selectRaw('SUM(free_tokens) AS free_tokens')
            ->selectRaw('SUM(panel_tokens_charged) AS panel_tokens_charged')
            ->selectRaw('SUM(CASE WHEN scope = ? THEN ai_tokens ELSE 0 END) AS admin_ai_tokens', [AiConversation::SCOPE_ADMIN])
            ->selectRaw('COUNT(*) AS calls')
            ->orderByDesc(DB::raw('SUM(ai_tokens)'))
            ->limit(500)
            ->toBase()
            ->get();

        $users = User::query()->whereIn('id', $rows->pluck('user_id')->all())->get()->keyBy('id');
        $totals = ['ai_tokens' => 0, 'free_tokens' => 0, 'panel_tokens_charged' => 0, 'admin_ai_tokens' => 0, 'calls' => 0];
        $data = [];

        foreach ($rows as $row) {
            $user = is_numeric($row->user_id) ? $users->get((int) $row->user_id) : null;
            $entry = [
                'user' => $user instanceof User ? ['id' => $user->id, 'username' => $user->username, 'email' => $user->email] : null,
            ];

            foreach (array_keys($totals) as $key) {
                $value = is_numeric($row->{$key}) ? (int) $row->{$key} : 0;
                $entry[$key] = $value;
                $totals[$key] += $value;
            }

            $data[] = $entry;
        }

        return new JsonResponse(['period' => $period, 'data' => $data, 'totals' => $totals]);
    }
}
