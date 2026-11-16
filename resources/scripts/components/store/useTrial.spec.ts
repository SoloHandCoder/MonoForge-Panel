import { describe, expect, it } from 'vitest';
import { trialHours, trialStateBadge } from '@/api/admin/shop/trials';
import { mapTrialOffer, type StoreCatalog, type StorePlan } from '@/api/store/types';
import { trialFor, tryFreeLabel } from '@/components/store/useTrial';

const plan = (id: number): StorePlan => ({
    id,
    categoryId: 1,
    name: `Plan ${id}`,
    description: '',
    priceTokens: 500,
    memory: 2048,
    swap: 0,
    disk: 10_000,
    cpu: 100,
    databases: 1,
    backups: 1,
    allocations: 0,
    eggs: [],
    nodeIds: null,
    sort: 0,
    canProvision: true,
    fullNodeIds: [],
});

const catalog = (trial: StoreCatalog['trial']): StoreCatalog => ({
    enabled: true,
    categories: [],
    periods: [],
    addons: [],
    locations: [],
    canProvision: true,
    subdomainsEnabled: false,
    subdomainBase: null,
    balance: 0,
    trial,
});

describe('free trials in the store', () => {
    it('maps the catalog offer and treats a missing one as no trials', () => {
        expect(mapTrialOffer({ enabled: true, hours: 48, plan_ids: [3], eligible: false, reason: 'Used.' })).toEqual({
            enabled: true,
            hours: 48,
            planIds: [3],
            eligible: false,
            reason: 'Used.',
        });
        expect(mapTrialOffer(undefined)).toEqual({
            enabled: false,
            hours: 0,
            planIds: [],
            eligible: false,
            reason: null,
        });
    });

    it('offers the trial only on trialable plans of an enabled offer', () => {
        const offer = { enabled: true, hours: 72, planIds: [3], eligible: true, reason: null };

        expect(trialFor(catalog(offer), plan(3))).toEqual({ hours: 72, eligible: true, reason: null });
        expect(trialFor(catalog(offer), plan(4))).toBeNull();
        expect(trialFor(catalog({ ...offer, enabled: false }), plan(3))).toBeNull();
        expect(trialFor(catalog({ ...offer, eligible: false, reason: 'Used.' }), plan(3))).toEqual({
            hours: 72,
            eligible: false,
            reason: 'Used.',
        });
        expect(tryFreeLabel(48)).toBe('Try free for 48\u00a0h');
    });
});

describe('admin trial settings', () => {
    it('only sends the trial lengths the panel accepts', () => {
        expect(trialHours(24)).toBe(24);
        expect(trialHours('72')).toBe(72);
        expect(trialHours(36)).toBe(48);
        expect(trialHours(null)).toBe(48);
    });

    it('labels every trial state', () => {
        expect(trialStateBadge('running')).toEqual({ label: 'Running', tone: 'accent' });
        expect(trialStateBadge('converted')).toEqual({ label: 'Kept', tone: 'success' });
        expect(trialStateBadge('expired')).toEqual({ label: 'Ended', tone: 'warning' });
        expect(trialStateBadge('deleted')).toEqual({ label: 'Deleted', tone: 'neutral' });
    });
});
