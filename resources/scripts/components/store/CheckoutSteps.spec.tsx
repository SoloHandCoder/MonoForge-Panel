/** @vitest-environment jsdom */

import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { StoreLocation } from '@/api/store/types';
import { LocationStep } from '@/components/store/CheckoutSteps';
import { CAPACITY_MESSAGE } from '@/components/store/StoreNotices';

const locations: StoreLocation[] = [
    {
        id: 1,
        name: 'Frankfurt',
        multiplier: 1,
        available: true,
        nodes: [
            { id: 10, name: 'fra-1', multiplier: 1, available: false },
            { id: 11, name: 'fra-2', multiplier: 1, available: true },
        ],
    },
    {
        id: 2,
        name: 'Helsinki',
        multiplier: 1,
        available: false,
        nodes: [{ id: 20, name: 'hel-1', multiplier: 1, available: false }],
    },
];

afterEach(cleanup);

describe('LocationStep', () => {
    it('greys out a full location and a full node with the capacity text', () => {
        render(
            <LocationStep locations={locations} locationId={1} nodeId={null} onLocation={vi.fn()} onNode={vi.fn()} />
        );

        expect(screen.getByText('Helsinki').closest('label')?.querySelector('input')?.disabled).toBe(true);
        expect(screen.getByText('Frankfurt').closest('label')?.querySelector('input')?.disabled).toBe(false);
        expect((screen.getByRole('radio', { name: 'fra-1' }) as HTMLButtonElement).disabled).toBe(true);
        expect((screen.getByRole('radio', { name: 'fra-2' }) as HTMLButtonElement).disabled).toBe(false);
        expect(screen.getAllByText(CAPACITY_MESSAGE)).toHaveLength(2);
    });
});
