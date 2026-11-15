<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Admin;

use Carbon\CarbonImmutable;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Billing\TebexPayment;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;
use Pterodactyl\Services\Billing\ShopSettings;

/**
 * Headline numbers of the token shop: plans, subscriptions by status, tokens held by users,
 * and the last 30 days of token movements and Tebex payments.
 */
final class ShopOverviewTool extends AdminTool
{
    public const int DAYS = 30;

    public function name(): string
    {
        return 'shop_overview';
    }

    public function description(): string
    {
        return 'Get an overview of the token shop: whether it is enabled, plans, subscriptions by status, tokens held by users, and token movements and Tebex payments over the last 30 days.';
    }

    public function parameters(): array
    {
        return $this->schema();
    }

    public function describeCall(array $arguments): string
    {
        return 'Read the shop overview';
    }

    protected function permissions(): array
    {
        return [Permissions::AdminShopRead];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $since = CarbonImmutable::now()->subDays(self::DAYS);

        $data = [
            'shop_enabled' => ShopSettings::enabled(),
            'plans' => [
                'total' => ShopPlan::query()->count(),
                'visible' => ShopPlan::query()->where('visible', true)->count(),
            ],
            'subscriptions' => $this->counts(ServerSubscription::query()->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->all()),
            'tokens_held_by_users' => (int) User::query()->sum('token_balance'),
            'last_30_days' => [
                'token_movements_by_type' => $this->counts(TokenTransaction::query()->toBase()->where('created_at', '>=', $since)->selectRaw('type, sum(amount) as aggregate')->groupBy('type')->pluck('aggregate', 'type')->all()),
                'tebex_payments_by_status' => $this->counts(TebexPayment::query()->toBase()->where('created_at', '>=', $since)->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->all()),
            ],
        ];

        return new ToolResult($this->json($data), ['shop_enabled' => $data['shop_enabled']]);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, int>
     */
    private function counts(array $values): array
    {
        $counts = [];
        foreach ($values as $key => $value) {
            $counts[(string) $key] = is_numeric($value) ? (int) $value : 0;
        }

        return $counts;
    }
}
