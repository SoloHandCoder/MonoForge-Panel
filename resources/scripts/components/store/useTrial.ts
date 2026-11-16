import { useRef, useState } from 'react';
import { isAxiosError } from 'axios';
import { httpErrorToHuman } from '@/api/http';
import { newIdempotencyKey, useStartTrial } from '@/api/store/queries';
import type { PurchaseResult, StoreCatalog, StorePlan } from '@/api/store/types';
import { MIN_WORKING_MS, type CheckoutError } from '@/components/store/useCheckout';

const MIN_FAILURE_MS = 900;

const wait = (ms: number) => new Promise((resolve) => window.setTimeout(resolve, Math.max(0, ms)));

/** The free trial of a plan as the store offers it to this player, or null when the plan has none. */
export interface PlanTrial {
    hours: number;
    eligible: boolean;
    /** Why this player cannot start one; null when they can. */
    reason: string | null;
}

export function trialFor(catalog: StoreCatalog, plan: StorePlan): PlanTrial | null {
    const { trial } = catalog;

    if (!trial.enabled || !trial.planIds.includes(plan.id)) {
        return null;
    }

    return { hours: trial.hours, eligible: trial.eligible, reason: trial.reason };
}

export type CheckoutMode = 'buy' | 'trial';

/**
 * The checkout's mode for a plan with a free trial: `active` is the trial while the player has picked
 * it (and may start it), null while buying. `#trial` in the URL opens the checkout on the trial.
 */
export function useCheckoutTrial(catalog: StoreCatalog, plan: StorePlan, hash: string) {
    const trial = trialFor(catalog, plan);
    const [mode, setMode] = useState<CheckoutMode>(trial?.eligible === true && hash === 'trial' ? 'trial' : 'buy');
    const active = mode === 'trial' && trial?.eligible === true ? trial : null;

    return { trial, active, setMode };
}

/** "Try free for 48 h". */
export const tryFreeLabel = (hours: number): string => `Try free for ${hours} h`;

export interface TrialDetails {
    planId: number;
    eggId: number;
    locationId: number | null;
    nodeId: number | null;
    name: string;
}

/**
 * Starts the free trial behind the loading screen, keeping one idempotency key per attempt (a request
 * lost on the way keeps its key, so retrying it never starts a second server).
 */
export function useTrialFlow() {
    const start = useStartTrial();
    const keyRef = useRef(newIdempotencyKey());
    const [working, setWorking] = useState(false);
    const [result, setResult] = useState<PurchaseResult | null>(null);
    const [error, setError] = useState<CheckoutError | null>(null);

    const begin = async (details: TrialDetails, captcha: () => Promise<string | null>) => {
        setError(null);
        const token = await captcha();

        if (token === null) {
            return;
        }

        setWorking(true);
        const started = Date.now();

        try {
            const response = await start.mutateAsync({
                body: {
                    plan_id: details.planId,
                    egg_id: details.eggId,
                    location_id: details.locationId,
                    node_id: details.nodeId,
                    name: details.name,
                    idempotency_key: keyRef.current,
                    // Empty when the panel has no captcha; it is left out then.
                    'g-recaptcha-response': token === '' ? undefined : token,
                },
            });

            await wait(MIN_WORKING_MS - (Date.now() - started));

            const { server, replayed, address } = response.attributes;

            setResult({
                server,
                replayed,
                address,
                tokens_charged: 0,
                period_days: 0,
                discount_tokens: 0,
                coupon: null,
                warning: null,
            });
        } catch (cause) {
            await wait(MIN_FAILURE_MS - (Date.now() - started));
            if (isAxiosError(cause) && cause.response) {
                keyRef.current = newIdempotencyKey();
            }

            setWorking(false);
            setError({ message: httpErrorToHuman(cause), short: false });
        }
    };

    return { begin, working, result, error };
}
