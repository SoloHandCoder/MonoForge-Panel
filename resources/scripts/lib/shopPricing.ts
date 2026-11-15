/**
 * The shop's pricing formula, mirrored from PricingService for live previews in the admin
 * editors. The server's quote is what players are charged.
 *
 * - base = round(price x days / 30 x (1 + modifier / 100))
 * - add-ons = sum of units x price x days / 30 x (1 + modifier / 100)
 * - total = ceil(base x multiplier + add-ons), never below 1
 */

// Absorbs float noise such as 114.99999 before rounding up, as the server does.
const EPSILON = 1e-6;

export interface PricingPeriod {
    days: number;
    price_modifier_percent: number;
}

/** What a 30-day price is multiplied by for the period, modifier included. */
export const periodFactor = (period: PricingPeriod): number =>
    (period.days / 30) * (1 + period.price_modifier_percent / 100);

/** PHP's round(): halves round away from zero. */
const roundHalfUp = (value: number): number => Math.sign(value) * Math.round(Math.abs(value));

/** The plan price for the period with its modifier, before any multiplier. */
export const basePrice = (priceTokens: number, period: PricingPeriod): number =>
    roundHalfUp(priceTokens * periodFactor(period));

/** What a player pays every period for the plan on a node with this multiplier, plus add-ons per 30 days. */
export const periodTotal = (
    priceTokens: number,
    period: PricingPeriod,
    multiplier = 1,
    addonTokensPer30Days = 0
): number =>
    Math.max(
        1,
        Math.ceil(basePrice(priceTokens, period) * multiplier + addonTokensPer30Days * periodFactor(period) - EPSILON)
    );

/** The period's price as tokens per 30 days, for comparing periods. */
export const perThirtyDays = (total: number, period: PricingPeriod): number =>
    period.days > 0 ? Math.round((total / period.days) * 30) : total;

/** "20% off", "10% surcharge" or "No discount". */
export const modifierLabel = (percent: number): string => {
    if (percent < 0) {
        return `${-percent}% off`;
    }

    return percent > 0 ? `${percent}% surcharge` : 'No discount';
};

export const formatTokens = (tokens: number): string => `${tokens.toLocaleString('en-US')} tokens`;

/** "1.5×", "1×", "1.25×". */
export const formatMultiplier = (multiplier: number): string => `${Number(multiplier.toFixed(2)).toString()}×`;
