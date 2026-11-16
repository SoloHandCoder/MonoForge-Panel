/** @vitest-environment jsdom */

import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type * as GatewayQueries from '@/api/admin/nodes/gateway';
import { gatewayAllocationOptions, parseGatewayPorts } from '@/api/admin/nodes/gateway';
import NodeGatewaySettings, { hostError } from './NodeGatewaySettings';

const gateway: GatewayQueries.NodeGateway = {
    enabled: true,
    active: true,
    host: 'play.example.com',
    ports: '25565-25700',
    ranges: [[25565, 25700]],
    script: { url: 'https://panel.example.com/scripts/gateway.sh', sha256: null },
    commands: {
        download: 'curl -fsSL -o gateway.sh https://panel.example.com/scripts/gateway.sh',
        vps_init: 'sudo bash gateway.sh vps-init --ports 25565-25700',
        node_init: 'sudo bash gateway.sh node-init --gateway <VPS public IP> --gateway-key <VPS public key>',
        vps_add_node: 'sudo bash gateway.sh vps-add-node --node-key <node public key>',
        status: 'sudo bash gateway.sh status',
    },
};

const update = vi.hoisted(() => ({ mutateAsync: vi.fn(async () => ({})), isPending: false }));
const check = vi.hoisted(() => ({ mutate: vi.fn(), isPending: false, data: undefined }));

vi.mock('@/api/admin/nodes/gateway', async (importOriginal) => ({
    ...(await importOriginal<typeof GatewayQueries>()),
    useAdminNodeGateway: () => ({ data: gateway, isError: false }),
    useUpdateAdminNodeGateway: () => update,
    useCheckAdminNodeGateway: () => check,
}));

afterEach(cleanup);

describe('parseGatewayPorts', () => {
    it('follows the limits of gateway.sh', () => {
        expect(parseGatewayPorts('25565-25700, 7777')).toEqual({
            ok: true,
            ranges: [
                [25565, 25700],
                [7777, 7777],
            ],
        });
        expect(parseGatewayPorts('22').ok).toBe(false);
        expect(parseGatewayPorts('51000-52000').ok).toBe(false);
        expect(parseGatewayPorts('1,2,3,4,5,6,7,8').ok).toBe(false);
        expect(parseGatewayPorts('25700-25565').ok).toBe(false);
        expect(parseGatewayPorts('').ok).toBe(false);
    });
});

describe('hostError', () => {
    it('takes host names and IPv4 addresses only', () => {
        expect(hostError('play.example.com')).toBeUndefined();
        expect(hostError('203.0.113.5')).toBeUndefined();
        expect(hostError('https://play.example.com')).toBeDefined();
        expect(hostError('play.example.com:25565')).toBeDefined();
        expect(hostError('')).toBeDefined();
    });
});

describe('gatewayAllocationOptions', () => {
    it('lists forwarded ports first and marks the others', () => {
        const options = [
            { value: 1, label: 'node:30000', port: 30000 },
            { value: 2, label: 'node:25565', port: 25565 },
        ];

        expect(gatewayAllocationOptions(options, gateway).map((option) => option.label)).toEqual([
            'node:25565',
            'node:30000 (not forwarded)',
        ]);
        expect(gatewayAllocationOptions(options, { ...gateway, active: false })).toEqual(options);
    });
});

describe('NodeGatewaySettings', () => {
    it('shows the script commands with copy buttons and checks the typed ports', async () => {
        const user = userEvent.setup();

        render(<NodeGatewaySettings nodeId={3} />);

        expect(screen.getByText('sudo bash gateway.sh vps-init --ports 25565-25700')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Copy: 1. On the VPS' })).toBeInTheDocument();
        expect(screen.getByLabelText('Public host')).toHaveValue('play.example.com');

        await user.click(screen.getByRole('button', { name: 'Check allocations' }));

        expect(check.mutate).toHaveBeenCalledWith({ path: { node_id: 3 }, body: { ports: '25565-25700' } });
    });
});
