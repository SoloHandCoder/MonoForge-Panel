import { describe, expect, it } from 'vitest';
import { mapPublicStorePlans, publicStorePlansQueryOptions } from './storePlans';

describe('public store plans', () => {
    it('maps the payload to the homepage shape', () => {
        expect(
            mapPublicStorePlans({
                enabled: true,
                assistant_enabled: true,
                periods: [{ id: 1, name: 'Monthly', days: 30 }],
                categories: [
                    {
                        name: 'Minecraft',
                        slug: 'minecraft',
                        description: null,
                        plans: [
                            {
                                id: 3,
                                name: 'Stone',
                                description: 'Small.',
                                resources: { memory: 2048, disk: 10240, cpu: 100, databases: 1, backups: 2 },
                                games: ['Paper'],
                                available: true,
                                prices: [{ period_id: 1, days: 30, tokens: 400 }],
                            },
                        ],
                    },
                ],
            })
        ).toEqual({
            enabled: true,
            assistantEnabled: true,
            periods: [{ id: 1, name: 'Monthly', days: 30 }],
            categories: [
                {
                    name: 'Minecraft',
                    slug: 'minecraft',
                    description: null,
                    plans: [
                        {
                            id: 3,
                            name: 'Stone',
                            description: 'Small.',
                            memory: 2048,
                            disk: 10240,
                            cpu: 100,
                            databases: 1,
                            backups: 2,
                            games: ['Paper'],
                            available: true,
                            prices: [{ periodId: 1, days: 30, tokens: 400 }],
                        },
                    ],
                },
            ],
        });
    });

    it('keeps the plans for as long as the server caches them', () => {
        expect(publicStorePlansQueryOptions().staleTime).toBe(60_000);
    });
});
