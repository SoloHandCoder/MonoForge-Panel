<?php

declare(strict_types=1);

/*
 * Interface-faithful stand-ins for the phase 3 classes other tasks own (TokenWallet and its
 * ledger: Task Q; the shop catalog, PricingService, Quote and CapacityService: Task R;
 * SubscriptionService: Task T), written against the plan's "Shared interfaces". Each one is
 * declared only while the real class is missing, so once the tasks are merged the tests run
 * against the real implementations and this file declares nothing.
 *
 * Loaded by BillingStandIns::ensure(); never autoloaded.
 */

namespace Pterodactyl\Exceptions\Billing {
    use RuntimeException;

    if (! class_exists(InsufficientTokensException::class)) {
        class InsufficientTokensException extends RuntimeException {}
    }
}

namespace Pterodactyl\Models\Billing {
    use Illuminate\Database\Eloquent\Model;

    if (! class_exists(TokenTransaction::class)) {
        /**
         * @property int $id
         * @property int $user_id
         * @property int $amount
         * @property int $balance_after
         * @property string $type
         */
        #[\Illuminate\Database\Eloquent\Attributes\Unguarded]
        #[\Illuminate\Database\Eloquent\Attributes\Table(name: 'token_transactions')]
        class TokenTransaction extends Model
        {
            public const null UPDATED_AT = null;

            protected function casts(): array
            {
                return ['meta' => 'array'];
            }
        }
    }

    if (! class_exists(ShopPlan::class)) {
        /**
         * @property int $id
         * @property string $name
         * @property int $price_tokens
         * @property int $memory
         * @property int $swap
         * @property int $disk
         * @property int $cpu
         * @property int $io
         * @property int $databases
         * @property int $backups
         * @property int $allocations
         * @property list<int> $egg_ids
         * @property list<int>|null $node_ids
         */
        #[\Illuminate\Database\Eloquent\Attributes\Unguarded]
        #[\Illuminate\Database\Eloquent\Attributes\Table(name: 'shop_plans')]
        class ShopPlan extends Model
        {
            protected function casts(): array
            {
                return ['egg_ids' => 'array', 'node_ids' => 'array', 'visible' => 'boolean'];
            }
        }
    }

    if (! class_exists(ShopPeriod::class)) {
        /**
         * @property int $id
         * @property string $name
         * @property int $days
         * @property int $price_modifier_percent
         */
        #[\Illuminate\Database\Eloquent\Attributes\Unguarded]
        #[\Illuminate\Database\Eloquent\Attributes\Table(name: 'shop_periods')]
        #[\Illuminate\Database\Eloquent\Attributes\WithoutTimestamps]
        class ShopPeriod extends Model
        {
            protected function casts(): array
            {
                return ['enabled' => 'boolean'];
            }
        }
    }

    if (! class_exists(ShopAddon::class)) {
        /**
         * @property int $id
         * @property string $key
         * @property string $name
         * @property string $resource
         * @property int $amount
         * @property int $price_tokens
         * @property int $max_units
         */
        #[\Illuminate\Database\Eloquent\Attributes\Unguarded]
        #[\Illuminate\Database\Eloquent\Attributes\Table(name: 'shop_addons')]
        #[\Illuminate\Database\Eloquent\Attributes\WithoutTimestamps]
        class ShopAddon extends Model
        {
            protected function casts(): array
            {
                return ['enabled' => 'boolean'];
            }
        }
    }

    if (! class_exists(ShopMultiplier::class)) {
        #[\Illuminate\Database\Eloquent\Attributes\Unguarded]
        #[\Illuminate\Database\Eloquent\Attributes\Table(name: 'shop_multipliers')]
        #[\Illuminate\Database\Eloquent\Attributes\WithoutTimestamps]
        class ShopMultiplier extends Model {}
    }

    if (! class_exists(ServerSubscription::class)) {
        /**
         * @property int $id
         * @property int $server_id
         * @property int $price_tokens
         * @property int $period_days
         */
        #[\Illuminate\Database\Eloquent\Attributes\Unguarded]
        #[\Illuminate\Database\Eloquent\Attributes\Table(name: 'server_subscriptions')]
        class ServerSubscription extends Model
        {
            protected function casts(): array
            {
                return ['addons' => 'array', 'next_renewal_at' => 'datetime', 'auto_renew' => 'boolean'];
            }
        }
    }
}

namespace Pterodactyl\Services\Billing {
    use Illuminate\Support\Facades\DB;
    use InvalidArgumentException;
    use Pterodactyl\Exceptions\Billing\InsufficientTokensException;
    use Pterodactyl\Models\Billing\ServerSubscription;
    use Pterodactyl\Models\Billing\ShopAddon;
    use Pterodactyl\Models\Billing\ShopMultiplier;
    use Pterodactyl\Models\Billing\ShopPeriod;
    use Pterodactyl\Models\Billing\ShopPlan;
    use Pterodactyl\Models\Billing\TokenTransaction;
    use Pterodactyl\Models\Node;
    use Pterodactyl\Models\Server;
    use Pterodactyl\Models\User;

    if (! class_exists(Quote::class)) {
        final readonly class Quote
        {
            /**
             * @param  list<array{label: string, tokens: int}>  $lines
             * @param  array<string, int>  $resources
             */
            public function __construct(
                public int $basePerPeriod,
                public float $multiplier,
                public int $addonsPerPeriod,
                public int $totalPerPeriod,
                public int $periodDays,
                public array $lines,
                public array $resources,
            ) {}
        }
    }

    if (! class_exists(TokenWallet::class)) {
        class TokenWallet
        {
            public function balance(User $user): int
            {
                return (int) DB::table('users')->where('id', $user->id)->value('token_balance');
            }

            /** @param array<string, mixed> $meta */
            public function credit(User $user, int $tokens, string $type, string $description, ?string $refType = null, ?string $refId = null, array $meta = []): TokenTransaction
            {
                throw_if($tokens <= 0, InvalidArgumentException::class, 'Credits must be positive.');

                return $this->apply($user, $tokens, $type, $description, $refType, $refId, $meta, true);
            }

            /** @param array<string, mixed> $meta */
            public function debit(User $user, int $tokens, string $type, string $description, ?string $refType = null, ?string $refId = null, array $meta = [], bool $allowNegative = false): TokenTransaction
            {
                return $this->apply($user, -$tokens, $type, $description, $refType, $refId, $meta, $allowNegative);
            }

            public function hasTransaction(string $refType, string $refId, string $type): bool
            {
                return TokenTransaction::query()->where(['reference_type' => $refType, 'reference_id' => $refId, 'type' => $type])->exists();
            }

            /** @param array<string, mixed> $meta */
            private function apply(User $user, int $amount, string $type, string $description, ?string $refType, ?string $refId, array $meta, bool $allowNegative): TokenTransaction
            {
                return DB::transaction(function () use ($user, $amount, $type, $description, $refType, $refId, $meta, $allowNegative): TokenTransaction {
                    $balance = (int) DB::table('users')->where('id', $user->id)->lockForUpdate()->value('token_balance');
                    throw_if(! $allowNegative && $balance + $amount < 0, InsufficientTokensException::class, 'Not enough tokens.');
                    DB::table('users')->where('id', $user->id)->update(['token_balance' => $balance + $amount]);

                    return TokenTransaction::query()->create([
                        'user_id' => $user->id,
                        'amount' => $amount,
                        'balance_after' => $balance + $amount,
                        'type' => $type,
                        'reference_type' => $refType,
                        'reference_id' => $refId,
                        'description' => $description,
                        'meta' => $meta,
                    ]);
                });
            }
        }
    }

    if (! class_exists(PricingService::class)) {
        class PricingService
        {
            /** @param array<string, int> $addons */
            public function quote(ShopPlan $plan, ShopPeriod $period, Node $node, array $addons): Quote
            {
                $modifier = 1 + $period->price_modifier_percent / 100;
                $base = (int) round($plan->price_tokens * $period->days / 30 * $modifier);
                $multiplier = (float) (ShopMultiplier::query()->where(['scope' => 'node', 'scope_id' => $node->id])->value('multiplier')
                    ?? ShopMultiplier::query()->where(['scope' => 'location', 'scope_id' => $node->location_id])->value('multiplier')
                    ?? 1.0);

                $resources = ['memory' => $plan->memory, 'disk' => $plan->disk, 'cpu' => $plan->cpu, 'backups' => $plan->backups, 'databases' => $plan->databases, 'allocations' => $plan->allocations];
                $lines = [['label' => $plan->name, 'tokens' => (int) ceil($base * $multiplier)]];
                $addonTokens = 0.0;
                foreach (ShopAddon::query()->whereIn('key', array_keys($addons))->get() as $addon) {
                    $units = $addons[$addon->key];
                    $tokens = $units * $addon->price_tokens * $period->days / 30 * $modifier;
                    $addonTokens += $tokens;
                    $resources[$addon->resource] += $units * $addon->amount;
                    $lines[] = ['label' => $units.' × '.$addon->name, 'tokens' => (int) ceil($tokens)];
                }

                return new Quote(
                    basePerPeriod: $base,
                    multiplier: $multiplier,
                    addonsPerPeriod: (int) ceil($addonTokens),
                    totalPerPeriod: max(1, (int) ceil($base * $multiplier + $addonTokens)),
                    periodDays: $period->days,
                    lines: $lines,
                    resources: $resources,
                );
            }
        }
    }

    if (! class_exists(CapacityService::class)) {
        class CapacityService
        {
            /** @param array<string, int> $resources */
            public function canProvision(array $resources): bool
            {
                $remaining = $this->remaining();

                return ($remaining['memory_mb'] === null || ($resources['memory'] ?? 0) <= $remaining['memory_mb'])
                    && ($remaining['disk_mb'] === null || ($resources['disk'] ?? 0) <= $remaining['disk_mb'])
                    && ($remaining['cpu_percent'] === null || ($resources['cpu'] ?? 0) <= $remaining['cpu_percent']);
            }

            /** @return array{memory_mb: int|null, disk_mb: int|null, cpu_percent: int|null} */
            public function remaining(): array
            {
                $used = (array) DB::table('servers')->whereIn('id', ServerSubscription::query()->whereIn('status', ['active', 'suspended'])->select('server_id'))
                    ->selectRaw('COALESCE(SUM(memory), 0) as memory, COALESCE(SUM(disk), 0) as disk, COALESCE(SUM(cpu), 0) as cpu')->first();
                $left = function (string $limit, string $column) use ($used): ?int {
                    $max = (int) config('monoforge.shop.limits.'.$limit, 0);

                    return $max === 0 ? null : max(0, $max - (int) ($used[$column] ?? 0));
                };

                return ['memory_mb' => $left('memory_mb', 'memory'), 'disk_mb' => $left('disk_mb', 'disk'), 'cpu_percent' => $left('cpu_percent', 'cpu')];
            }
        }
    }

    if (! class_exists(SubscriptionService::class)) {
        class SubscriptionService
        {
            /** @param array<string, int> $addons */
            public function start(Server $server, User $user, ?ShopPlan $plan, ShopPeriod $period, Quote $quote, array $addons): ServerSubscription
            {
                return ServerSubscription::query()->create([
                    'server_id' => $server->id,
                    'user_id' => $user->id,
                    'plan_id' => $plan?->id,
                    'period_id' => $period->id,
                    'period_days' => $quote->periodDays,
                    'price_tokens' => $quote->totalPerPeriod,
                    'addons' => collect($addons)->map(fn (int $units, string $key): array => ['key' => $key, 'units' => $units])->values()->all(),
                    'status' => 'active',
                    'next_renewal_at' => now()->addDays($quote->periodDays),
                    'auto_renew' => true,
                ]);
            }
        }
    }
}
