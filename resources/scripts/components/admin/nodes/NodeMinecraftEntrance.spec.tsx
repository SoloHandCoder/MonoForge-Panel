/** @vitest-environment jsdom */

import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type * as EntranceQueries from '@/api/admin/nodes/minecraftEntrance';
import { proxyPortError } from '@/api/admin/nodes/minecraftEntrance';
import NodeMinecraftEntrance, { domainError } from './NodeMinecraftEntrance';

const entrance: EntranceQueries.NodeMinecraftEntrance = {
    enabled: true,
    active: true,
    domain: 'play.example.com',
    mode: 'playit',
    entrance_port: 25565,
    proxy_port: 25500,
    listen_port: 25500,
    public_port: 41234,
    proxy_protocol: true,
    srv: false,
    tunnel_address: 'mc.ply.gg:41234',
    tunnel_error: null,
    synced_at: null,
    servers: 2,
    example_address: 'survival.play.example.com:41234',
    gateway_forwards_entrance: null,
    dns: [
        { type: 'CNAME', name: '*.play.example.com', value: 'mc.ply.gg', note: 'DNS only.' },
        {
            type: 'SRV',
            name: '_minecraft._tcp.survival.play.example.com',
            value: '0 5 41234 survival.play.example.com',
            note: 'x',
        },
    ],
    script: { url: 'https://panel.example.com/scripts/mc-router.sh', sha256: null },
    commands: {
        download: 'curl -fsSL -o mc-router.sh https://panel.example.com/scripts/mc-router.sh',
        install: 'sudo bash mc-router.sh install',
        status: 'sudo bash mc-router.sh status',
        remove: 'sudo bash mc-router.sh remove',
    },
};

const apply = vi.hoisted(() => ({ mutate: vi.fn(), isPending: false, data: undefined }));
const update = vi.hoisted(() => ({ mutateAsync: vi.fn(async () => ({})), isPending: false }));

vi.mock('@/api/admin/nodes/minecraftEntrance', async (importOriginal) => ({
    ...(await importOriginal<typeof EntranceQueries>()),
    useAdminNodeMinecraftEntrance: () => ({ data: entrance, isError: false }),
    useUpdateAdminNodeMinecraftEntrance: () => update,
    useApplyProxyProtocol: () => apply,
}));

afterEach(cleanup);

describe('NodeMinecraftEntrance', () => {
    it('lists the DNS records and the router commands', () => {
        render(<NodeMinecraftEntrance nodeId={4} />);

        expect(screen.getByText('*.play.example.com')).toBeInTheDocument();
        expect(screen.getByText('_minecraft._tcp.survival.play.example.com')).toBeInTheDocument();
        expect(screen.getByText('sudo bash mc-router.sh install')).toBeInTheDocument();
        expect(
            screen.getByText(/2 servers are routed, such as survival\.play\.example\.com:41234/)
        ).toBeInTheDocument();
        expect(screen.getByLabelText('Proxy port (on the node)')).toBeInTheDocument();
    });

    it('applies PROXY protocol on demand', async () => {
        const user = userEvent.setup();

        render(<NodeMinecraftEntrance nodeId={4} />);

        await user.click(screen.getByRole('button', { name: 'Apply to servers' }));

        expect(apply.mutate).toHaveBeenCalledWith({ path: { node_id: 4 } });
    });
});

describe('field checks', () => {
    it('take domains and node-local ports only', () => {
        expect(domainError('play.example.com')).toBeUndefined();
        expect(domainError('https://play.example.com')).toBeDefined();
        expect(domainError('')).toBeDefined();
        expect(proxyPortError(25500)).toBeUndefined();
        expect(proxyPortError(80)).toBeDefined();
        expect(proxyPortError(51820)).toBeDefined();
    });
});
