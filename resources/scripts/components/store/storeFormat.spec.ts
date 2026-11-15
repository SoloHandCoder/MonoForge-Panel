import { describe, expect, it } from 'vitest';
import { mapCatalog, type RawStoreCatalog, type StoreLocation, type StorePlan } from '@/api/store/types';
import { cpu, estimate, fromPerMonth, megabytes, periodNote, sortPlans } from '@/components/store/storeFormat';
import { planLocations, validateName, validateSubdomain, withAddons } from '@/components/store/useCheckout';

const plan = (overrides: Partial<StorePlan> = {}): StorePlan => ({
    id: 1,
    categoryId: 1,
    name: 'Starter',
    description: '',
    priceTokens: 500,
    memory: 2048,
    swap: 0,
    disk: 10240,
    cpu: 100,
    databases: 1,
    backups: 1,
    allocations: 0,
    eggs: [],
    nodeIds: null,
    sort: 0,
    ...overrides,
});

const locations: StoreLocation[] = [
    { id: 1, name: 'Home', multiplier: 1, nodes: [{ id: 10, name: 'home-1', multiplier: 1 }] },
    { id: 2, name: 'Pro', multiplier: 1.5, nodes: [{ id: 20, name: 'pro-1', multiplier: 1 }] },
];

describe('store pricing', () => {
    it('sorts plans cheapest first by their lowest monthly price', () => {
        const plans = [
            plan({ id: 1, priceTokens: 900 }),
            plan({ id: 2, priceTokens: 300 }),
            plan({ id: 3, priceTokens: 500 }),
        ];

        expect(sortPlans(plans, locations).map((each) => each.id)).toEqual([2, 3, 1]);
    });

    it('quotes "from" the cheapest location the plan may use', () => {
        expect(fromPerMonth(plan(), locations)).toBe(500);
        expect(fromPerMonth(plan({ nodeIds: [20] }), locations)).toBe(750);
    });

    it('estimates with the shared formula: period modifier, multiplier, add-ons, rounded up', () => {
        const quarterly = { id: 2, name: 'Quarterly', days: 90, priceModifierPercent: -10 };
        const backup = {
            key: 'backup',
            name: 'Backup',
            description: null,
            resource: 'backups' as const,
            amount: 1,
            priceTokens: 50,
            maxUnits: 3,
        };

        // base round(500 × 3 × 0.9) = 1350, × 1.5 = 2025, + 2 × 50 × 3 × 0.9 = 270
        expect(estimate(plan(), quarterly, 1.5, [backup], { backup: 2 })).toBe(2295);
        expect(withAddons(plan(), [backup], { backup: 2 }).backups).toBe(3);
        expect(periodNote(quarterly)).toBe('Save 10%');
    });
});

describe('store formatting and checks', () => {
    it('formats resources the way players read them', () => {
        expect(megabytes(512)).toBe('512 MB');
        expect(megabytes(2048)).toBe('2 GB');
        expect(megabytes(0)).toBe('Unlimited');
        expect(cpu(200)).toBe('2 CPU threads');
        expect(cpu(150)).toBe('150% CPU');
    });

    it('checks the server name and subdomain like the endpoint does', () => {
        expect(validateName('  ')).not.toBeNull();
        expect(validateName('<b>')).not.toBeNull();
        expect(validateName('Survival')).toBeNull();
        expect(validateSubdomain('my-server')).toBeNull();
        expect(validateSubdomain('-bad')).not.toBeNull();
    });

    it('offers only the locations with a node the plan allows', () => {
        expect(planLocations(plan({ nodeIds: [20] }), locations).map((each) => each.id)).toEqual([2]);
    });

    it('reads the catalog envelope', () => {
        const raw: RawStoreCatalog = {
            categories: [
                {
                    id: 1,
                    name: 'Minecraft',
                    plans: [
                        {
                            id: 4,
                            name: 'Starter',
                            price_tokens: 500,
                            memory: 2048,
                            disk: 10240,
                            cpu: 100,
                            databases: 1,
                            backups: 1,
                            allocations: 0,
                        },
                    ],
                },
            ],
            periods: [{ id: 1, name: 'Monthly', days: 30, price_modifier_percent: 0 }],
            locations: [{ id: 2, short: 'eu', multiplier: '1.50' }],
            can_provision: false,
        };

        const catalog = mapCatalog({ object: 'store_catalog', attributes: raw });

        expect(catalog.categories[0].plans[0].priceTokens).toBe(500);
        expect(catalog.locations[0]).toMatchObject({ name: 'eu', multiplier: 1.5 });
        expect(catalog.canProvision).toBe(false);
        expect(catalog.subdomainsEnabled).toBe(false);
    });
});
