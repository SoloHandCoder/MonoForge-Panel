import type { AdminRevenue, AdminRevenueQuery } from '@/api/admin/billing/queries';

export type RevenuePreset = '7' | '30' | '90';

export const REVENUE_PRESETS: readonly { value: RevenuePreset; label: string }[] = [
    { value: '7', label: '7 days' },
    { value: '30', label: '30 days' },
    { value: '90', label: '90 days' },
];

/** The longest custom range the panel accepts. */
export const MAX_CUSTOM_DAYS = 366;

const pad = (value: number) => String(value).padStart(2, '0');

/** A date as YYYY-MM-DD in the browser's timezone. */
export const isoDate = (date: Date): string =>
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

/** Days from `from` to `to`, both included, or null when either is not a YYYY-MM-DD date. */
export function daysBetween(from: string, to: string): number | null {
    if (!DATE_PATTERN.test(from) || !DATE_PATTERN.test(to)) {
        return null;
    }

    const start = Date.parse(`${from}T00:00:00Z`);
    const end = Date.parse(`${to}T00:00:00Z`);

    return Number.isNaN(start) || Number.isNaN(end) ? null : Math.round((end - start) / 86_400_000) + 1;
}

/** Why a custom range cannot be asked for, or null when it can. */
export function customRangeError(from: string, to: string, today: string): string | null {
    const days = daysBetween(from, to);

    if (days === null) {
        return 'Pick both days.';
    }

    if (days < 1) {
        return 'The last day must be on or after the first.';
    }

    if (to > today) {
        return 'The last day cannot be in the future.';
    }

    return days > MAX_CUSTOM_DAYS ? `A custom range can be at most ${MAX_CUSTOM_DAYS} days.` : null;
}

export const revenueQuery = (preset: RevenuePreset | 'custom', from: string, to: string): AdminRevenueQuery =>
    preset === 'custom' ? { range: 'custom', from, to } : { range: preset };

const countFormat = new Intl.NumberFormat('en-US');
const compactFormat = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 });

export const formatCount = (value: number): string => countFormat.format(value);

/** A short axis label, such as 1.2K. */
export const formatCompact = (value: number): string => compactFormat.format(value);

/** A decimal amount from the panel ("12.30") in its currency; an unknown code is shown as is. */
export function formatMoney(amount: string, currency: string): string {
    const value = Number(amount);

    if (amount.trim() === '' || !Number.isFinite(value)) {
        return `${amount} ${currency}`;
    }

    try {
        return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(value);
    } catch {
        return `${amount} ${currency}`;
    }
}

/** A day (YYYY-MM-DD) as a short label, such as "Nov 16". */
export function formatDay(date: string): string {
    const parsed = new Date(`${date}T00:00:00Z`);

    return Number.isNaN(parsed.getTime())
        ? date
        : new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' }).format(parsed);
}

/** A CSV cell, quoted when it needs to be, and never read as a formula by a spreadsheet. */
export function csvCell(value: string | number): string {
    let text = String(value);

    // A plan name such as "=HYPERLINK(...)" is text; a number such as -5 or "-4.99" stays a number.
    if (/^[=+\-@\t\r]/.test(text) && !/^-\d+(?:\.\d+)?$/.test(text)) {
        text = `'${text}`;
    }

    return /[",\n\r]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
}

const csvRow = (cells: readonly (string | number)[]) => cells.map(csvCell).join(',');

/**
 * The report as CSV: the summary as metric/value rows, money per currency, the top plans, then a
 * row per day. Money stays a decimal string, as the panel sent it.
 */
export function revenueCsv(report: AdminRevenue): string {
    const lines: string[] = [
        csvRow(['metric', 'value']),
        csvRow(['from', report.range.from]),
        csvRow(['to', report.range.to]),
        csvRow(['tokens_sold', report.tokens.sold]),
        csvRow(['purchases', report.tokens.purchases]),
        csvRow(['tokens_refunded', report.tokens.refunded]),
        csvRow(['tokens_charged_back', report.tokens.charged_back]),
        csvRow(['tokens_net', report.tokens.net]),
        csvRow(['tokens_spent_on_servers', report.tokens.spent_on_servers]),
        csvRow(['disputes_open', report.disputes_open]),
        csvRow(['active_subscriptions', report.subscriptions.active]),
        csvRow(['suspended_subscriptions', report.subscriptions.suspended]),
        csvRow(['subscription_tokens_per_30_days', report.subscriptions.mrr_tokens]),
        csvRow([`renewals_next_${report.renewals_due.days}_days`, report.renewals_due.count]),
        csvRow(['renewal_tokens_due', report.renewals_due.tokens]),
        csvRow(['renewals_short_of_balance', report.renewals_due.short_of_balance]),
        csvRow(['churn_cancelled', report.churn.cancelled]),
        csvRow(['churn_unpaid', report.churn.unpaid]),
        csvRow(['churn_deleted', report.churn.deleted]),
        csvRow(['churn_tokens_per_30_days', report.churn.mrr_tokens]),
        csvRow(['sleep_saved_tokens', report.sleep_saved_tokens]),
        csvRow(['coupon_discount_tokens', report.promotions.coupon_discount_tokens]),
        csvRow(['coupon_purchases', report.promotions.coupon_purchases]),
        csvRow(['coupon_free_tokens', report.promotions.coupon_credit_tokens]),
        csvRow(['coupon_free_token_redemptions', report.promotions.coupon_credits]),
        csvRow(['referral_reward_tokens', report.promotions.referral_reward_tokens]),
        csvRow(['referral_rewards', report.promotions.referral_rewards]),
        csvRow(['referral_tokens_taken_back', report.promotions.referral_clawback_tokens]),
        csvRow(['trials_started_all_time', report.trials.started]),
        csvRow(['trials_running', report.trials.running]),
        csvRow(['trials_kept_all_time', report.trials.converted]),
        csvRow(['trials_lapsed_all_time', report.trials.lapsed]),
        csvRow(['trial_conversion_rate_percent', report.trials.conversion_rate]),
        csvRow(['ai_tokens_charged', report.ai.charged_tokens]),
        csvRow(['ai_model_tokens', report.ai.ai_tokens]),
        csvRow(['new_users', report.users.new]),
        csvRow(['paying_users', report.users.paying]),
        '',
        csvRow(['currency', 'gross', 'refunded', 'charged_back', 'net', 'payments', 'refunds', 'chargebacks']),
        ...report.money.map((row) =>
            csvRow([
                row.currency,
                row.gross,
                row.refunded,
                row.charged_back,
                row.net,
                row.payments,
                row.refunds,
                row.chargebacks,
            ])
        ),
        '',
        csvRow(['plan', 'active_subscriptions', 'tokens_per_30_days']),
        ...report.top_plans.map((plan) => csvRow([plan.name, plan.active, plan.mrr_tokens])),
        '',
        csvRow(['date', 'tokens_sold', 'tokens_spent', 'new_users']),
        ...report.daily.map((day) => csvRow([day.date, day.tokens_sold, day.tokens_spent, day.new_users])),
    ];

    return `${lines.join('\r\n')}\r\n`;
}

export const revenueCsvFilename = (report: AdminRevenue): string =>
    `revenue-${report.range.from}-to-${report.range.to}.csv`;

/** Saves the report as a CSV file in the browser. */
export function downloadRevenueCsv(report: AdminRevenue): void {
    const url = URL.createObjectURL(new Blob([revenueCsv(report)], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');

    link.href = url;
    link.download = revenueCsvFilename(report);
    document.body.append(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}
