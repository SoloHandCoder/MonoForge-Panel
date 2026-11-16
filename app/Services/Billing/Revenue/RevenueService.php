<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing\Revenue;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Assistant\AiUsage;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Billing\TebexPayment;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\TrialService;

/**
 * The admin revenue dashboard: what came in, what went out again and what the subscriptions are
 * worth, read from the ledger, the Tebex payments, the subscriptions and the AI usage. Every
 * range query walks an index (see the 2026_11_23_000710 migration), and a report is cached for
 * five minutes so reloading the page costs nothing on the panel's small CPU.
 *
 * Tokens are whole numbers throughout; money is summed in cents per currency and never mixed
 * across currencies.
 *
 * Promotions are reported apart and never added to the sales: tokens sold are Tebex purchases
 * only, so free tokens from coupons and referral rewards (their own ledger types) are not sold
 * tokens, and a coupon purchase or a kept free trial is spent once, at what it actually charged.
 * The coupon discount is what those purchases did not charge.
 *
 * @phpstan-type MoneyRow array{currency: string, gross: string, refunded: string, charged_back: string, net: string, payments: int, refunds: int, chargebacks: int}
 * @phpstan-type DailyRow array{date: string, tokens_sold: int, tokens_spent: int, new_users: int}
 * @phpstan-type PlanRow array{id: int|null, name: string, active: int, mrr_tokens: int}
 * @phpstan-type Report array{
 *     range: array{from: string, to: string, days: int},
 *     generated_at: string,
 *     tokens: array{sold: int, purchases: int, refunded: int, charged_back: int, net: int, spent_on_servers: int},
 *     money: list<MoneyRow>,
 *     disputes_open: int,
 *     subscriptions: array{active: int, suspended: int, mrr_tokens: int},
 *     renewals_due: array{days: int, count: int, tokens: int, short_of_balance: int},
 *     churn: array{cancelled: int, unpaid: int, deleted: int, total: int, mrr_tokens: int},
 *     top_plans: list<PlanRow>,
 *     sleep_saved_tokens: int,
 *     promotions: array{coupon_discount_tokens: int, coupon_purchases: int, coupon_credit_tokens: int, coupon_credits: int, referral_reward_tokens: int, referral_rewards: int, referral_clawback_tokens: int},
 *     trials: array{started: int, running: int, converted: int, lapsed: int, conversion_rate: float},
 *     ai: array{charged_tokens: int, ai_tokens: int},
 *     users: array{new: int, paying: int},
 *     daily: list<DailyRow>,
 * }
 */
class RevenueService
{
    public const int CACHE_SECONDS = 300;

    /** Renewals in this many days are reported as due. */
    public const int RENEWAL_WINDOW_DAYS = 7;

    public const int TOP_PLANS = 5;

    /** Subscriptions are compared per 30 days, whatever their period. */
    private const int MRR_DAYS = 30;

    /** @var list<string> */
    private const array SPEND_TYPES = [TokenTransaction::TYPE_SERVER_CHARGE, TokenTransaction::TYPE_ADDON_CHARGE];

    public function __construct(private readonly TrialService $trials) {}

    /**
     * A decimal amount ("12.34", from a DECIMAL column or a SUM of one) as whole cents, without
     * passing through a float.
     */
    public static function cents(mixed $amount): int
    {
        $amount = mb_trim(self::string($amount));
        if (preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $amount, $match) !== 1) {
            return 0;
        }

        $cents = ((int) ($match[2] === '' ? '0' : $match[2])) * 100 + (int) mb_str_pad(mb_substr($match[3] ?? '', 0, 2), 2, '0');

        return $match[1] === '-' ? -$cents : $cents;
    }

    public static function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }

    /**
     * @return Report
     */
    public function report(RevenueRange $range): array
    {
        /** @var Report */
        return Cache::remember($range->cacheKey(), self::CACHE_SECONDS, fn (): array => $this->build($range));
    }

    /**
     * @return Report
     */
    public function build(RevenueRange $range): array
    {
        $purchases = $this->ledger($range, [TokenTransaction::TYPE_PURCHASE])
            ->selectRaw('COALESCE(SUM(amount), 0) AS tokens, COUNT(*) AS purchases, COUNT(DISTINCT user_id) AS users')
            ->first();
        $spent = $this->ledger($range, self::SPEND_TYPES)->sum('amount');
        $reversals = $this->reversals($range);

        $sold = $this->int($purchases?->tokens);
        $refunded = $reversals['tokens'][TebexPayment::STATUS_REFUNDED];
        $chargedBack = $reversals['tokens'][TebexPayment::STATUS_CHARGEBACK];
        $charges = $this->serverCharges($range);

        return [
            'range' => ['from' => $range->from->toDateString(), 'to' => $range->to->toDateString(), 'days' => $range->days()],
            'generated_at' => CarbonImmutable::now()->toAtomString(),
            'tokens' => [
                'sold' => $sold,
                'purchases' => $this->int($purchases?->purchases),
                'refunded' => $refunded,
                'charged_back' => $chargedBack,
                'net' => $sold - $refunded - $chargedBack,
                'spent_on_servers' => -$this->int($spent),
            ],
            'money' => $this->money($range, $reversals['money']),
            'disputes_open' => TebexPayment::query()->where('status', TebexPayment::STATUS_DISPUTED)->count(),
            'subscriptions' => $this->subscriptions(),
            'renewals_due' => $this->renewalsDue(),
            'churn' => $this->churn($range),
            'top_plans' => $this->topPlans(),
            'sleep_saved_tokens' => $charges['sleep_saved'],
            'promotions' => $this->promotions($range, $charges),
            'trials' => $this->trials->stats(),
            'ai' => [
                'charged_tokens' => -$this->int($this->ledger($range, [TokenTransaction::TYPE_AI_USAGE])->sum('amount')),
                'ai_tokens' => $this->int(AiUsage::query()->whereBetween('created_at', [$range->from, $range->to])->sum('ai_tokens')),
            ],
            'users' => [
                'new' => User::query()->whereBetween('created_at', [$range->from, $range->to])->count(),
                'paying' => $this->int($purchases?->users),
            ],
            'daily' => $this->daily($range),
        ];
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Ledger rows of the given types inside the range, through the (type, created_at) index.
     *
     * @param  list<string>  $types
     */
    private function ledger(RevenueRange $range, array $types): Builder
    {
        return DB::table('token_transactions')
            ->whereIn('type', $types)
            ->whereBetween('created_at', [$range->from, $range->to]);
    }

    /**
     * Payments refunded or charged back in the range. A payment counts once, on the day its
     * tokens were first taken back (a dispute's hold included), and only when it ended refunded
     * or charged back: a dispute still open or won is not a loss.
     *
     * @return array{tokens: array<string, int>, money: array<string, array{refunded: int, charged_back: int, refunds: int, chargebacks: int}>}
     */
    private function reversals(RevenueRange $range): array
    {
        $references = $this->ledger($range, [TokenTransaction::TYPE_REFUND, TokenTransaction::TYPE_CHARGEBACK])
            ->where('reference_type', TebexPayment::LEDGER_REFERENCE)
            ->pluck('reference_id')
            ->map(fn (mixed $reference): string => $this->transactionId($reference))
            ->filter(fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();

        $tokens = [TebexPayment::STATUS_REFUNDED => 0, TebexPayment::STATUS_CHARGEBACK => 0];
        $money = [];

        foreach (array_chunk($references, 500) as $chunk) {
            // A payment reversed before this range was counted in an earlier one.
            $earlier = DB::table('token_transactions')
                ->whereIn('type', [TokenTransaction::TYPE_REFUND, TokenTransaction::TYPE_CHARGEBACK])
                ->where('reference_type', TebexPayment::LEDGER_REFERENCE)
                ->whereIn('reference_id', array_merge($chunk, array_map(fn (string $id): string => $id.'#dispute', $chunk)))
                ->where('created_at', '<', $range->from)
                ->pluck('reference_id')
                ->map(fn (mixed $reference): string => $this->transactionId($reference))
                ->flip();

            $payments = TebexPayment::query()
                ->whereIn('transaction_id', $chunk)
                ->whereIn('status', [TebexPayment::STATUS_REFUNDED, TebexPayment::STATUS_CHARGEBACK])
                ->toBase()
                ->get(['transaction_id', 'status', 'tokens_reversed', 'amount', 'currency']);

            foreach ($payments as $payment) {
                if ($earlier->has(self::string($payment->transaction_id))) {
                    continue;
                }

                $status = self::string($payment->status);
                $refund = $status === TebexPayment::STATUS_REFUNDED;
                $tokens[$status] += $this->int($payment->tokens_reversed);

                $currency = $this->currency($payment->currency);
                $money[$currency] ??= ['refunded' => 0, 'charged_back' => 0, 'refunds' => 0, 'chargebacks' => 0];
                $money[$currency][$refund ? 'refunded' : 'charged_back'] += self::cents($payment->amount);
                $money[$currency][$refund ? 'refunds' : 'chargebacks']++;
            }
        }

        return ['tokens' => $tokens, 'money' => $money];
    }

    /**
     * Money in per currency: payments credited in the range, less what was refunded or charged
     * back in it.
     *
     * @param  array<string, array{refunded: int, charged_back: int, refunds: int, chargebacks: int}>  $reversed
     * @return list<MoneyRow>
     */
    private function money(RevenueRange $range, array $reversed): array
    {
        $rows = DB::table('tebex_payments')
            ->whereBetween('processed_at', [$range->from, $range->to])
            ->groupBy('currency')
            ->select('currency')
            ->selectRaw('SUM(amount) AS gross, COUNT(*) AS payments')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals[$this->currency($row->currency)] = ['gross' => self::cents($row->gross), 'payments' => $this->int($row->payments)];
        }

        $currencies = array_unique(array_merge(array_keys($totals), array_keys($reversed)));
        sort($currencies);

        $money = [];
        foreach ($currencies as $currency) {
            $gross = $totals[$currency]['gross'] ?? 0;
            $back = $reversed[$currency] ?? ['refunded' => 0, 'charged_back' => 0, 'refunds' => 0, 'chargebacks' => 0];
            $money[] = [
                'currency' => $currency,
                'gross' => self::decimal($gross),
                'refunded' => self::decimal($back['refunded']),
                'charged_back' => self::decimal($back['charged_back']),
                'net' => self::decimal($gross - $back['refunded'] - $back['charged_back']),
                'payments' => $totals[$currency]['payments'] ?? 0,
                'refunds' => $back['refunds'],
                'chargebacks' => $back['chargebacks'],
            ];
        }

        return $money;
    }

    /**
     * @return array{active: int, suspended: int, mrr_tokens: int}
     */
    private function subscriptions(): array
    {
        $counts = DB::table('server_subscriptions')
            ->whereIn('status', [ServerSubscription::STATUS_ACTIVE, ServerSubscription::STATUS_SUSPENDED])
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) AS total')
            ->pluck('total', 'status');

        return [
            'active' => $this->int($counts->get(ServerSubscription::STATUS_ACTIVE)),
            'suspended' => $this->int($counts->get(ServerSubscription::STATUS_SUSPENDED)),
            'mrr_tokens' => $this->perThirtyDays(
                DB::table('server_subscriptions')
                    ->where('status', ServerSubscription::STATUS_ACTIVE)
                    ->groupBy('period_days')
                    ->selectRaw('period_days, SUM(price_tokens) AS tokens')
                    ->get()
                    ->all(),
            ),
        ];
    }

    /**
     * Renewals in the next seven days and how many of them the owner's balance does not cover.
     * An owner's renewals are paid in date order from one balance, so the later ones are the
     * short ones. The locked price is used: a sleep discount can only make a renewal cheaper.
     *
     * @return array{days: int, count: int, tokens: int, short_of_balance: int}
     */
    private function renewalsDue(): array
    {
        $now = CarbonImmutable::now();
        $due = DB::table('server_subscriptions')
            ->join('users', 'users.id', '=', 'server_subscriptions.user_id')
            ->where('server_subscriptions.status', ServerSubscription::STATUS_ACTIVE)
            ->where('server_subscriptions.auto_renew', true)
            ->whereBetween('server_subscriptions.next_renewal_at', [$now, $now->addDays(self::RENEWAL_WINDOW_DAYS)])
            ->oldest('server_subscriptions.next_renewal_at')
            ->orderBy('server_subscriptions.id')
            ->get(['server_subscriptions.user_id', 'server_subscriptions.price_tokens', 'users.token_balance']);

        $balances = [];
        $tokens = 0;
        $short = 0;
        foreach ($due as $row) {
            $user = $this->int($row->user_id);
            $price = $this->int($row->price_tokens);
            $balances[$user] ??= $this->int($row->token_balance);
            $tokens += $price;

            if ($balances[$user] < $price) {
                $short++;

                continue;
            }

            $balances[$user] -= $price;
        }

        return ['days' => self::RENEWAL_WINDOW_DAYS, 'count' => $due->count(), 'tokens' => $tokens, 'short_of_balance' => $short];
    }

    /**
     * Subscriptions that ended in the range, and what they were worth per 30 days.
     *
     * @return array{cancelled: int, unpaid: int, deleted: int, total: int, mrr_tokens: int}
     */
    private function churn(RevenueRange $range): array
    {
        $rows = DB::table(SubscriptionEndings::TABLE)
            ->whereBetween('created_at', [$range->from, $range->to])
            ->groupBy('reason', 'period_days')
            ->selectRaw('reason, period_days, COUNT(*) AS total, SUM(price_tokens) AS tokens')
            ->get();

        $counts = array_fill_keys(SubscriptionEndings::REASONS, 0);
        foreach ($rows as $row) {
            $reason = self::string($row->reason);
            if (array_key_exists($reason, $counts)) {
                $counts[$reason] += $this->int($row->total);
            }
        }

        return [
            'cancelled' => $counts[SubscriptionEndings::REASON_CANCELLED],
            'unpaid' => $counts[SubscriptionEndings::REASON_UNPAID],
            'deleted' => $counts[SubscriptionEndings::REASON_DELETED],
            'total' => array_sum($counts),
            'mrr_tokens' => $this->perThirtyDays($rows->all()),
        ];
    }

    /**
     * The plans with the most active subscriptions, with what those are worth per 30 days.
     *
     * @return list<PlanRow>
     */
    private function topPlans(): array
    {
        $rows = DB::table('server_subscriptions')
            ->where('status', ServerSubscription::STATUS_ACTIVE)
            ->groupBy('plan_id', 'period_days')
            ->selectRaw('plan_id, period_days, COUNT(*) AS total, SUM(price_tokens) AS tokens')
            ->get();

        /** @var array<string, array{id: int|null, active: int, rows: list<object>}> $plans */
        $plans = [];
        foreach ($rows as $row) {
            $id = $row->plan_id === null ? null : $this->int($row->plan_id);
            $key = (string) ($id ?? 'none');
            $plans[$key] ??= ['id' => $id, 'active' => 0, 'rows' => []];
            $plans[$key]['active'] += $this->int($row->total);
            $plans[$key]['rows'][] = $row;
        }

        $names = ShopPlan::query()
            ->whereIn('id', array_filter(array_column($plans, 'id'), fn (?int $id): bool => $id !== null))
            ->pluck('name', 'id');

        $top = array_map(fn (array $plan): array => [
            'id' => $plan['id'],
            'name' => $plan['id'] === null ? 'Deleted plan' : self::string($names->get($plan['id'], 'Deleted plan')),
            'active' => $plan['active'],
            'mrr_tokens' => $this->perThirtyDays($plan['rows']),
        ], array_values($plans));

        usort($top, fn (array $a, array $b): int => [$b['active'], $b['mrr_tokens'], $a['name']] <=> [$a['active'], $a['mrr_tokens'], $b['name']]);

        return array_slice($top, 0, self::TOP_PLANS);
    }

    /**
     * From the server charges in the range, in one pass: tokens renewals did not charge because
     * the server slept, and what coupons took off first periods (with how many purchases used one).
     *
     * @return array{sleep_saved: int, coupon_discount: int, coupon_purchases: int}
     */
    private function serverCharges(RevenueRange $range): array
    {
        $row = $this->ledger($range, [TokenTransaction::TYPE_SERVER_CHARGE])
            ->selectRaw("COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.sleep_saved_tokens')) AS UNSIGNED)), 0) AS saved")
            ->selectRaw("COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.coupon_discount')) AS UNSIGNED)), 0) AS discount")
            ->selectRaw("COUNT(JSON_EXTRACT(meta, '$.coupon_id')) AS coupons")
            ->first();

        return [
            'sleep_saved' => $this->int($row?->saved),
            'coupon_discount' => $this->int($row?->discount),
            'coupon_purchases' => $this->int($row?->coupons),
        ];
    }

    /**
     * Coupon discounts and the tokens coupons and referrals gave away in the range. A referral
     * reward taken back (a refund after the payout) is a negative referral row.
     *
     * @param  array{sleep_saved: int, coupon_discount: int, coupon_purchases: int}  $charges
     * @return array{coupon_discount_tokens: int, coupon_purchases: int, coupon_credit_tokens: int, coupon_credits: int, referral_reward_tokens: int, referral_rewards: int, referral_clawback_tokens: int}
     */
    private function promotions(RevenueRange $range, array $charges): array
    {
        $credits = $this->ledger($range, [TokenTransaction::TYPE_COUPON])
            ->selectRaw('COALESCE(SUM(amount), 0) AS tokens, COUNT(*) AS credits')
            ->first();
        $referrals = $this->ledger($range, [TokenTransaction::TYPE_REFERRAL])
            ->selectRaw('COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS rewarded')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount > 0 THEN 1 ELSE 0 END), 0) AS rewards')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END), 0) AS clawed_back')
            ->first();

        return [
            'coupon_discount_tokens' => $charges['coupon_discount'],
            'coupon_purchases' => $charges['coupon_purchases'],
            'coupon_credit_tokens' => $this->int($credits?->tokens),
            'coupon_credits' => $this->int($credits?->credits),
            'referral_reward_tokens' => $this->int($referrals?->rewarded),
            'referral_rewards' => $this->int($referrals?->rewards),
            'referral_clawback_tokens' => $this->int($referrals?->clawed_back),
        ];
    }

    /**
     * Tokens sold, tokens spent on servers and new users, for every day of the range.
     *
     * @return list<DailyRow>
     */
    private function daily(RevenueRange $range): array
    {
        $sold = $this->ledger($range, [TokenTransaction::TYPE_PURCHASE])
            ->groupByRaw('DATE(created_at)')
            ->selectRaw('DATE(created_at) AS day, SUM(amount) AS tokens')
            ->pluck('tokens', 'day');
        $spent = $this->ledger($range, self::SPEND_TYPES)
            ->groupByRaw('DATE(created_at)')
            ->selectRaw('DATE(created_at) AS day, SUM(amount) AS tokens')
            ->pluck('tokens', 'day');
        $users = DB::table('users')
            ->whereBetween('created_at', [$range->from, $range->to])
            ->groupByRaw('DATE(created_at)')
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS total')
            ->pluck('total', 'day');

        return array_map(fn (string $date): array => [
            'date' => $date,
            'tokens_sold' => $this->int($sold->get($date)),
            'tokens_spent' => -$this->int($spent->get($date)),
            'new_users' => $this->int($users->get($date)),
        ], $range->dates());
    }

    /**
     * Sums tokens per period as tokens per 30 days, rounding down once per period length.
     *
     * @param  array<array-key, object>  $rows  rows with `period_days` and `tokens`
     */
    private function perThirtyDays(array $rows): int
    {
        $byPeriod = [];
        foreach ($rows as $row) {
            $days = $this->int($row->period_days ?? null);
            if ($days > 0) {
                $byPeriod[$days] = ($byPeriod[$days] ?? 0) + $this->int($row->tokens ?? null);
            }
        }

        $total = 0;
        foreach ($byPeriod as $days => $tokens) {
            $total += intdiv($tokens * self::MRR_DAYS, $days);
        }

        return $total;
    }

    private function transactionId(mixed $reference): string
    {
        $reference = self::string($reference);

        return str_ends_with($reference, '#dispute') ? mb_substr($reference, 0, -8) : $reference;
    }

    private function currency(mixed $currency): string
    {
        $currency = mb_strtoupper(mb_trim(self::string($currency)));

        return $currency === '' ? 'UNKNOWN' : $currency;
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
