/** @vitest-environment jsdom */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type * as TunnelQueries from '@/api/admin/tunnels/queries';
import NodePlayitAutoSetup, { switchCommands } from './NodePlayitAutoSetup';

const TOKEN = '3f9a1c7e5b2d4f6a8c0e1b3d5f7a9c2e4b6d8f0a1c3e5b7d';
const SHA = '9f2c1d7e4b3a5c6d8e0f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d';

const base: TunnelQueries.PlayitSwitchState = {
    mode: 'read_only',
    self_managed: false,
    switched_at: null,
    can_undo: false,
    claim: null,
    old_tunnels: [],
    old_tunnels_removed: 0,
    script: { url: 'https://panel.example.com/scripts/playit-switch-agent.sh', sha256: SHA },
};

const claim = (overrides: Partial<NonNullable<TunnelQueries.PlayitSwitchState['claim']>>) => ({
    status: 'waiting_for_visit' as const,
    claim_url: null,
    expires_at: null,
    agent_id: null,
    token_expires_at: null,
    fetched_at: null,
    report: null,
    reported_at: null,
    last_error: null,
    ...overrides,
});

const state = vi.hoisted(() => ({ current: undefined as TunnelQueries.PlayitSwitchState | undefined }));
const start = vi.hoisted(() => ({ mutateAsync: vi.fn(async () => {}), isPending: false }));
const remove = vi.hoisted(() => ({ mutateAsync: vi.fn(async () => {}), isPending: false }));
const createToken = vi.hoisted(() => ({
    mutate: vi.fn((_v: undefined, options?: { onSuccess?: (token: TunnelQueries.PlayitSwitchToken) => void }) =>
        options?.onSuccess?.({
            token: '3f9a1c7e5b2d4f6a8c0e1b3d5f7a9c2e4b6d8f0a1c3e5b7d',
            expires_at: '2026-11-23T08:29:00+00:00',
        })
    ),
    isPending: false,
}));
const idle = vi.hoisted(() => ({ mutateAsync: vi.fn(async () => {}), mutate: vi.fn(), isPending: false }));

vi.mock('@/api/admin/tunnels/queries', async (importOriginal) => ({
    ...(await importOriginal<typeof TunnelQueries>()),
    usePlayitSwitch: () => ({ data: state.current }),
    usePlayitClaimPolling: () => ({}),
    useStartPlayitClaim: () => start,
    useCreatePlayitSwitchToken: () => createToken,
    useFinishPlayitSwitch: () => idle,
    useUndoPlayitSwitch: () => idle,
    useRemoveOldTunnels: () => remove,
    useDismissOldTunnels: () => idle,
}));

const renderCard = () =>
    render(
        <QueryClientProvider client={new QueryClient()}>
            <NodePlayitAutoSetup nodeId={3} />
        </QueryClientProvider>
    );

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

describe('NodePlayitAutoSetup', () => {
    it('starts a claim only after a confirmation', async () => {
        state.current = base;
        renderCard();

        await userEvent.click(screen.getByRole('button', { name: 'Set up automatic tunnels' }));
        expect(start.mutateAsync).not.toHaveBeenCalled();

        const dialog = await screen.findByRole('dialog');

        expect(dialog.textContent).toContain('Nothing changes on the node or for players yet');

        await userEvent.click(within(dialog).getByRole('button', { name: 'Start' }));
        expect(start.mutateAsync).toHaveBeenCalledOnce();
    });

    it('shows the claim URL while the claim waits', () => {
        state.current = { ...base, claim: claim({ claim_url: 'https://playit.gg/claim/0a1b2c3d4e' }) };
        renderCard();

        const link = screen.getByRole('link', { name: /playit\.gg\/claim\/0a1b2c3d4e/ });

        expect(link.getAttribute('href')).toBe('https://playit.gg/claim/0a1b2c3d4e');
        expect(link.getAttribute('target')).toBe('_blank');
        expect(link.getAttribute('rel')).toContain('noopener');
        expect(screen.getByText(/Waiting for you to accept on playit\.gg/)).toBeTruthy();
    });

    it('shows the switch commands and the one-time token apart', async () => {
        state.current = {
            ...base,
            claim: claim({ status: 'ready', agent_id: '7c1e2d3f-4a5b-4c6d-8e9f-0a1b2c3d4e5f' }),
        };
        renderCard();

        expect(screen.getByText(/the new agent 7c1e2d3f-4a5b-4c6d-8e9f-0a1b2c3d4e5f belongs/)).toBeTruthy();

        await userEvent.click(screen.getByRole('button', { name: 'Show switch commands' }));
        expect(createToken.mutate).toHaveBeenCalledOnce();

        const dialog = await screen.findByRole('dialog');
        const blocks = within(dialog)
            .getAllByText((_content, element) => element?.tagName === 'PRE')
            .map((element) => element.textContent ?? '');

        expect(blocks[0]).toBe(switchCommands(base.script));
        expect(blocks[0]).not.toContain(TOKEN);
        expect(blocks[1]).toBe(TOKEN);
        expect(dialog.textContent).toContain('--rollback');
    });

    it('says addresses changed and removes old tunnels only after a confirmation', async () => {
        state.current = {
            ...base,
            mode: 'self_managed',
            self_managed: true,
            can_undo: true,
            switched_at: '2026-11-23T08:15:00+00:00',
            claim: claim({ status: 'switched', agent_id: '7c1e2d3f-4a5b-4c6d-8e9f-0a1b2c3d4e5f' }),
            old_tunnels: [
                {
                    id: 1,
                    playit_tunnel_id: '0c8f1f4e-6f7b-4a2e-9a1d-2f6e3c1b7a90',
                    name: 'survival',
                    address: 'old.joinmc.link',
                    local_port: 25565,
                    server_id: 12,
                    server_name: 'Survival',
                },
            ],
        };
        renderCard();

        expect(screen.getByText('Server addresses changed')).toBeTruthy();
        expect(screen.getByText('Survival · port 25565 · old.joinmc.link')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Undo switch' })).toBeTruthy();

        await userEvent.click(screen.getByRole('button', { name: 'Remove old tunnels' }));
        expect(remove.mutateAsync).not.toHaveBeenCalled();

        const dialog = await screen.findByRole('dialog');

        expect(dialog.textContent).toContain('This cannot be undone');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Remove old tunnels' }));
        expect(remove.mutateAsync).toHaveBeenCalledOnce();
    });
});

describe('switchCommands', () => {
    it('downloads the script, checks its hash and runs it against the panel', () => {
        expect(switchCommands(base.script)).toBe(
            [
                'curl -fsSL -o playit-switch-agent.sh https://panel.example.com/scripts/playit-switch-agent.sh',
                `echo "${SHA}  playit-switch-agent.sh" | sha256sum -c -`,
                'sudo bash playit-switch-agent.sh --panel https://panel.example.com',
            ].join('\n')
        );
    });
});
