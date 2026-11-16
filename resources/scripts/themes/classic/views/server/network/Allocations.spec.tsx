/* oxlint-disable rules/classic-theme-imports -- the spec renders the classic view with testing-library */
/** @vitest-environment jsdom */
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ServerAllocationsModel } from '@/sdk/theme';
import Allocations from './Allocations';

afterEach(cleanup);

const resolved = async () => {};

const Nothing = () => null;

function model(rename: (name: string) => Promise<void>, canEdit = true): ServerAllocationsModel {
    return {
        allocations: [
            {
                id: 1,
                ip: '203.0.113.10',
                alias: null,
                address: '203.0.113.10',
                port: 25570,
                notes: null,
                primary: true,
            },
        ],
        playitAddress: 'survival.play.example.com',
        minecraftHostname: {
            hostname: 'survival',
            domain: 'play.example.com',
            address: 'survival.play.example.com',
            canEdit,
            rename,
        },
        loading: false,
        limit: 0,
        permissions: { create: false, update: false, delete: false },
        canManage: false,
        canCreate: false,
        actions: { create: resolved, setPrimary: resolved, setNotes: resolved, remove: resolved },
    };
}

describe('Allocations with the shared Minecraft entrance', () => {
    it('lets the owner change the name and refuses a bad one', async () => {
        const user = userEvent.setup();
        const rename = vi.fn(resolved);

        render(<Allocations model={model(rename)} Default={Nothing} />);

        const input = screen.getByRole('textbox', { name: 'Name in the address' });

        await user.clear(input);
        await user.type(input, 'Bad--name');

        expect(screen.getByRole('button', { name: 'Change' })).toBeDisabled();

        await user.clear(input);
        await user.type(input, 'my-smp');
        await user.click(screen.getByRole('button', { name: 'Change' }));

        expect(rename).toHaveBeenCalledWith('my-smp');
    });

    it('only shows the name to users who may not rename the server', () => {
        render(<Allocations model={model(vi.fn(resolved), false)} Default={Nothing} />);

        expect(screen.queryByRole('textbox', { name: 'Name in the address' })).not.toBeInTheDocument();
        expect(screen.getAllByText('survival.play.example.com').length).toBeGreaterThan(0);
    });
});
