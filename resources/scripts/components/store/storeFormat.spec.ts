import { describe, expect, it } from 'vitest';
import { mapCatalog, type RawStoreCatalog, type StoreLocation, type StorePlan } from '@/api/store/types';
import { cpu, estimate, fromPerMonth, megabytes, periodNote, sortPlans } from '@/components/store/storeFormat';
import {
    firstLocation,
    planLocations,
    validateName,
    validateSubdomain,
    withAddons,
} from '@/components/store/useCheckout';

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
    canProvision: true,
    fullNodeIds: [],
    ...overrides,
});

const locations: StoreLocation[] = [
    {
        id: 1,
        name: 'Home',
        multiplier: 1,
        available: true,
        nodes: [{ id: 10, name: 'home-1', multiplier: 1, available: true }],
    },
    {
        id: 2,
        name: 'Pro',
        multiplier: 1.5,
        available: true,
        nodes: [{ id: 20, name: 'pro-1', multiplier: 1, available: true }],
    },
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

    it('marks the nodes at their shop limits, and a location made only of those, unavailable', () => {
        const pro: StoreLocation = {
            id: 2,
            name: 'Pro',
            multiplier: 1.5,
            available: true,
            nodes: [
                { id: 20, name: 'pro-1', multiplier: 1, available: true },
                { id: 21, name: 'pro-2', multiplier: 1, available: true },
            ],
        };
        const [home, mixed] = planLocations(plan({ fullNodeIds: [10, 20] }), [locations[0], pro]);

        expect(home).toMatchObject({ available: false, nodes: [{ id: 10, available: false }] });
        expect(mixed).toMatchObject({
            available: true,
            nodes: [
                { id: 20, available: false },
                { id: 21, available: true },
            ],
        });
        expect(firstLocation([home, mixed])?.id).toBe(2);
        expect(firstLocation([home])?.id).toBe(1);
    });

    it('reads the catalog the store endpoint returns', () => {
        const resources = {
            memory: 2048,
            swap: 0,
            disk: 10240,
            io: 500,
            cpu: 100,
            databases: 1,
            backups: 1,
            allocations: 0,
        };
        const eu = (nodes: { id: number; name: string; multiplier: number; can_provision?: boolean }[]) => ({
            id: 2,
            short: 'eu',
            long: null,
            multiplier: '1.50',
            nodes,
        });
        const raw: RawStoreCatalog = {
            enabled: true,
            subdomains_enabled: true,
            subdomain_base: 'monoforge.net',
            balance: 300,
            categories: [
                {
                    id: 1,
                    name: 'Minecraft',
                    slug: 'minecraft',
                    description: null,
                    sort: 0,
                    visible: true,
                    plans: [
                        {
                            id: 4,
                            name: 'Starter',
                            description: null,
                            price_tokens: 500,
                            from_price_tokens: 750,
                            resources,
                            can_provision: false,
                            eggs: [{ id: 7, name: 'Paper', description: null, image: null }],
                            locations: [eu([{ id: 20, name: 'eu-1', multiplier: 1.5, can_provision: false }])],
                        },
                        {
                            id: 5,
                            name: 'Big',
                            description: 'More room',
                            price_tokens: 900,
                            from_price_tokens: 1350,
                            resources: { ...resources, memory: 8192 },
                            can_provision: false,
                            eggs: [],
                            locations: [eu([{ id: 21, name: 'eu-2', multiplier: 1.5 }])],
                        },
                    ],
                },
            ],
            periods: [{ id: 1, name: 'Monthly', days: 30, price_modifier_percent: 0 }],
            addons: [],
        };

        const catalog = mapCatalog(raw);
        const [starter, big] = catalog.categories[0].plans;

        expect(starter).toMatchObject({
            priceTokens: 500,
            memory: 2048,
            nodeIds: [20],
            sort: 0,
            canProvision: false,
            fullNodeIds: [20],
        });
        expect(big).toMatchObject({ memory: 8192, nodeIds: [21], sort: 1, fullNodeIds: [] });
        expect(catalog.locations).toEqual([
            {
                id: 2,
                name: 'eu',
                multiplier: 1.5,
                available: true,
                nodes: [
                    { id: 20, name: 'eu-1', multiplier: 1.5, available: true },
                    { id: 21, name: 'eu-2', multiplier: 1.5, available: true },
                ],
            },
        ]);
        expect(planLocations(starter, catalog.locations)[0]).toMatchObject({
            available: false,
            nodes: [{ id: 20, available: false }],
        });
        expect(catalog).toMatchObject({
            canProvision: false,
            subdomainsEnabled: true,
            subdomainBase: 'monoforge.net',
            balance: 300,
        });
    });
});
