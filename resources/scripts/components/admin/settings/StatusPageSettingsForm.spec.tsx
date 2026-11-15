/** @vitest-environment jsdom */

import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as StatusQueries from '@/api/admin/settings/status';
import type { AdminStatusPageSettings } from '@/api/admin/settings/status';
import { StatusPageSettingsForm } from './StatusPageSettingsForm';

const update = vi.hoisted(() => vi.fn());

vi.mock('@/api/admin/settings/status', async (importOriginal) => ({
    ...(await importOriginal<typeof StatusQueries>()),
    useUpdateAdminStatusPageSettings: () => ({ mutateAsync: update }),
}));

const settings: AdminStatusPageSettings = {
    enabled: true,
    publicUrl: '/status',
    nodes: [
        {
            id: 1,
            name: 'fsn-wings-01',
            location: 'EU',
            visible: true,
            displayName: 'Frankfurt',
            customName: 'Frankfurt',
            defaultName: 'EU Node 1',
        },
        {
            id: 2,
            name: 'fsn-wings-02',
            location: 'EU',
            visible: false,
            displayName: 'EU Node 2',
            customName: '',
            defaultName: 'EU Node 2',
        },
    ],
};

afterEach(cleanup);
beforeEach(() => vi.clearAllMocks());

describe('status page settings', () => {
    it('turns the page off, shows a node and renames it, sending a blank name as the default', async () => {
        const user = userEvent.setup();

        render(<StatusPageSettingsForm settings={settings} />);

        expect(screen.getByRole('link', { name: 'Open the status page' })).toHaveAttribute('href', '/status');
        const second = screen.getByLabelText('Display name of fsn-wings-02');

        expect(second).toHaveAttribute('placeholder', 'EU Node 2');

        await user.click(screen.getByRole('switch', { name: 'Show the public status page' }));
        await user.click(screen.getAllByRole('switch', { name: 'Shown' })[1]);
        await user.clear(screen.getByLabelText('Display name of fsn-wings-01'));
        await user.type(second, '  Falkenstein ');
        await user.click(screen.getByRole('button', { name: 'Save Changes' }));

        expect(update).toHaveBeenCalledWith({
            body: {
                enabled: false,
                nodes: [
                    { id: 1, visible: true, display_name: null },
                    { id: 2, visible: true, display_name: 'Falkenstein' },
                ],
            },
        });
    });

    it('says when there are no nodes', () => {
        render(<StatusPageSettingsForm settings={{ ...settings, nodes: [] }} />);

        expect(screen.getByText('There are no nodes yet.')).toBeVisible();
    });
});
