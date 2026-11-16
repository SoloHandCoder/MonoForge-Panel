import type { AdminRevenue } from '@/api/admin/billing/queries';

/** Three days with two currencies, a short renewal and churn, as the revenue endpoint returns them. */
export const revenueFixture: AdminRevenue = {
    range: { from: '2026-11-14', to: '2026-11-16', days: 3 },
    generated_at: '2026-11-16T12:00:00+00:00',
    tokens: { sold: 1500, purchases: 2, refunded: 500, charged_back: 300, net: 700, spent_on_servers: 550 },
    money: [
        {
            currency: 'EUR',
            gross: '14.98',
            refunded: '4.99',
            charged_back: '0.00',
            net: '9.99',
            payments: 2,
            refunds: 1,
            chargebacks: 0,
        },
        {
            currency: 'USD',
            gross: '0.00',
            refunded: '0.00',
            charged_back: '3.50',
            net: '-3.50',
            payments: 0,
            refunds: 0,
            chargebacks: 1,
        },
    ],
    disputes_open: 1,
    subscriptions: { active: 3, suspended: 1, mrr_tokens: 942 },
    renewals_due: { days: 7, count: 2, tokens: 400, short_of_balance: 1 },
    churn: { cancelled: 1, unpaid: 1, deleted: 0, total: 2, mrr_tokens: 600 },
    top_plans: [
        { id: 2, name: '=Small', active: 2, mrr_tokens: 642 },
        { id: null, name: 'Deleted plan', active: 1, mrr_tokens: 300 },
    ],
    sleep_saved_tokens: 40,
    promotions: {
        coupon_discount_tokens: 120,
        coupon_purchases: 1,
        coupon_credit_tokens: 250,
        coupon_credits: 1,
        referral_reward_tokens: 400,
        referral_rewards: 2,
        referral_clawback_tokens: 200,
    },
    trials: { started: 5, running: 1, converted: 2, lapsed: 2, conversion_rate: 50 },
    ai: { charged_tokens: 7, ai_tokens: 9000 },
    users: { new: 4, paying: 2 },
    daily: [
        { date: '2026-11-14', tokens_sold: 0, tokens_spent: 0, new_users: 1 },
        { date: '2026-11-15', tokens_sold: 1000, tokens_spent: 300, new_users: 3 },
        { date: '2026-11-16', tokens_sold: 500, tokens_spent: 250, new_users: 0 },
    ],
};
