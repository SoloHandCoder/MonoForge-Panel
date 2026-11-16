<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Billing;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Billing\GetRevenueRequest;
use Pterodactyl\Services\Billing\Revenue\RevenueService;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Billing', 'Revenue, refunds, subscriptions and churn of the token shop.')]
class RevenueController extends AdminApiController
{
    private const array EXAMPLE = [
        'range' => ['from' => '2026-10-18', 'to' => '2026-11-16', 'days' => 30],
        'generated_at' => '2026-11-16T12:00:00+00:00',
        'tokens' => ['sold' => 12000, 'purchases' => 14, 'refunded' => 500, 'charged_back' => 0, 'net' => 11500, 'spent_on_servers' => 9800],
        'money' => [[
            'currency' => 'EUR',
            'gross' => '119.86',
            'refunded' => '4.99',
            'charged_back' => '0.00',
            'net' => '114.87',
            'payments' => 14,
            'refunds' => 1,
            'chargebacks' => 0,
        ]],
        'disputes_open' => 0,
        'subscriptions' => ['active' => 21, 'suspended' => 2, 'mrr_tokens' => 10500],
        'renewals_due' => ['days' => 7, 'count' => 6, 'tokens' => 3000, 'short_of_balance' => 2],
        'churn' => ['cancelled' => 1, 'unpaid' => 2, 'deleted' => 0, 'total' => 3, 'mrr_tokens' => 1500],
        'top_plans' => [['id' => 3, 'name' => 'Minecraft 4 GB', 'active' => 9, 'mrr_tokens' => 4500]],
        'sleep_saved_tokens' => 640,
        'ai' => ['charged_tokens' => 42, 'ai_tokens' => 512000],
        'users' => ['new' => 37, 'paying' => 11],
        'daily' => [['date' => '2026-11-16', 'tokens_sold' => 1000, 'tokens_spent' => 350, 'new_users' => 2]],
    ];

    /**
     * Return the revenue dashboard for a range of days.
     */
    #[Endpoint('Get revenue', "Returns the token shop revenue for the last 7, 30 or 90 days, or a custom range of up to 366 days: tokens sold, money in per currency (never converted or mixed), refunds and chargebacks, net, active subscriptions and what they bring in per 30 days, renewals due in the next 7 days and how many the owner's balance does not cover, churn, the top plans, tokens saved by auto-sleep, AI usage charged and new and paying users, with a row per day. Days are in the panel's timezone, both ends included. Reports are cached for five minutes. Users are only counted, never listed.")]
    #[QueryParam('range', 'string', 'The last 7, 30 or 90 days (today included), or custom with from and to. 30 by default.', required: false, example: '30', enum: GetRevenueRequest::RANGES)]
    #[QueryParam('from', 'string', 'The first day of a custom range, as YYYY-MM-DD.', required: false, example: '2026-10-01')]
    #[QueryParam('to', 'string', 'The last day of a custom range, as YYYY-MM-DD; today at the latest, and at most 366 days after from.', required: false, example: '2026-10-31')]
    #[ScribeResponse(self::EXAMPLE, description: 'Revenue returned.')]
    #[ResponseField('range', 'object', 'The days reported on.', required: true)]
    #[ResponseField('range.from', 'string', 'The first day, as YYYY-MM-DD.', required: true)]
    #[ResponseField('range.to', 'string', 'The last day, as YYYY-MM-DD.', required: true)]
    #[ResponseField('range.days', 'integer', 'How many days the range covers.', required: true)]
    #[ResponseField('generated_at', 'string', 'When the report was built; it is cached for five minutes.', required: true)]
    #[ResponseField('tokens', 'object', 'Tokens moved in the range.', required: true)]
    #[ResponseField('tokens.sold', 'integer', 'Tokens credited for purchases.', required: true)]
    #[ResponseField('tokens.purchases', 'integer', 'Purchases credited.', required: true)]
    #[ResponseField('tokens.refunded', 'integer', 'Tokens taken back for payments refunded in the range.', required: true)]
    #[ResponseField('tokens.charged_back', 'integer', 'Tokens taken back for payments charged back in the range.', required: true)]
    #[ResponseField('tokens.net', 'integer', 'Tokens sold less those refunded and charged back.', required: true)]
    #[ResponseField('tokens.spent_on_servers', 'integer', 'Tokens charged for servers and add-ons.', required: true)]
    #[ResponseField('money', 'object[]', 'Money per currency, by currency code.', required: true)]
    #[ResponseField('money.currency', 'string', 'The currency code Tebex reported.', required: true)]
    #[ResponseField('money.gross', 'string', 'Paid for payments credited in the range, as a decimal.', required: true)]
    #[ResponseField('money.refunded', 'string', 'Paid for payments refunded in the range, as a decimal.', required: true)]
    #[ResponseField('money.charged_back', 'string', 'Paid for payments charged back in the range, as a decimal.', required: true)]
    #[ResponseField('money.net', 'string', 'Gross less refunded and charged back, as a decimal; can be negative.', required: true)]
    #[ResponseField('money.payments', 'integer', 'Payments credited.', required: true)]
    #[ResponseField('money.refunds', 'integer', 'Payments refunded.', required: true)]
    #[ResponseField('money.chargebacks', 'integer', 'Payments charged back.', required: true)]
    #[ResponseField('disputes_open', 'integer', 'Payments with a dispute open now, whatever the range.', required: true)]
    #[ResponseField('subscriptions', 'object', 'Subscriptions now, whatever the range.', required: true)]
    #[ResponseField('subscriptions.active', 'integer', 'Active subscriptions.', required: true)]
    #[ResponseField('subscriptions.suspended', 'integer', 'Subscriptions suspended for not being paid.', required: true)]
    #[ResponseField('subscriptions.mrr_tokens', 'integer', 'What the active subscriptions charge per 30 days, in tokens, before sleep discounts.', required: true)]
    #[ResponseField('renewals_due', 'object', 'Renewals due from now, whatever the range.', required: true)]
    #[ResponseField('renewals_due.days', 'integer', 'How many days ahead renewals are counted.', required: true)]
    #[ResponseField('renewals_due.count', 'integer', 'Active, auto-renewing subscriptions that renew in those days.', required: true)]
    #[ResponseField('renewals_due.tokens', 'integer', 'Their locked prices, in tokens.', required: true)]
    #[ResponseField('renewals_due.short_of_balance', 'integer', "Renewals the owner's balance does not cover now, paying each owner's renewals in date order.", required: true)]
    #[ResponseField('churn', 'object', 'Subscriptions that ended in the range.', required: true)]
    #[ResponseField('churn.cancelled', 'integer', 'Cancelled.', required: true)]
    #[ResponseField('churn.unpaid', 'integer', 'Deleted after not being paid.', required: true)]
    #[ResponseField('churn.deleted', 'integer', 'Deleted while running (by the owner or an administrator).', required: true)]
    #[ResponseField('churn.total', 'integer', 'All of them.', required: true)]
    #[ResponseField('churn.mrr_tokens', 'integer', 'What they charged per 30 days, in tokens.', required: true)]
    #[ResponseField('top_plans', 'object[]', 'The plans with the most active subscriptions, at most 5.', required: true)]
    #[ResponseField('top_plans.id', 'integer', 'The plan ID, or null for subscriptions whose plan was deleted.', nullable: true)]
    #[ResponseField('top_plans.name', 'string', 'The plan name.', required: true)]
    #[ResponseField('top_plans.active', 'integer', 'Active subscriptions on it.', required: true)]
    #[ResponseField('top_plans.mrr_tokens', 'integer', 'What they charge per 30 days, in tokens.', required: true)]
    #[ResponseField('sleep_saved_tokens', 'integer', 'Tokens renewals in the range did not charge because the server slept.', required: true)]
    #[ResponseField('ai', 'object', 'AI assistant usage in the range.', required: true)]
    #[ResponseField('ai.charged_tokens', 'integer', 'Panel tokens charged for AI usage.', required: true)]
    #[ResponseField('ai.ai_tokens', 'integer', 'AI model tokens used, free allowance included.', required: true)]
    #[ResponseField('users', 'object', 'Users in the range.', required: true)]
    #[ResponseField('users.new', 'integer', 'Accounts created.', required: true)]
    #[ResponseField('users.paying', 'integer', 'Users with at least one purchase credited.', required: true)]
    #[ResponseField('daily', 'object[]', 'One row per day of the range, oldest first.', required: true)]
    #[ResponseField('daily.date', 'string', 'The day, as YYYY-MM-DD.', required: true)]
    #[ResponseField('daily.tokens_sold', 'integer', 'Tokens credited for purchases.', required: true)]
    #[ResponseField('daily.tokens_spent', 'integer', 'Tokens charged for servers and add-ons.', required: true)]
    #[ResponseField('daily.new_users', 'integer', 'Accounts created.', required: true)]
    public function __invoke(GetRevenueRequest $request, RevenueService $revenue): JsonResponse
    {
        return new JsonResponse($revenue->report($request->range()));
    }
}
