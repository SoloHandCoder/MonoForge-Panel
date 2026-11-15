/** @vitest-environment jsdom */
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterAll, afterEach, beforeAll, describe, expect, it, vi, type MockInstance } from 'vitest';
import { SocketEvent } from '@/components/server/events';
import { Websocket } from '@/plugins/Websocket';
import useWebsocketEvent from '@/plugins/useWebsocketEvent';
import { useServerStore, type ServerStatus } from '@/state/server';
import {
    createExtensionTestHost,
    createTestServer,
    type ExtensionTestHost,
    type TestServerOptions,
} from '@/sdk/testing';
import { clearThemeLayers, registerViewLayer } from '../registry';
import { View } from '../View';
import type { ServerGraphsModel, ServerStatsModel } from './console';
import { featureViews } from './features';

vi.mock('ghostty-web', () => ({ Ghostty: { load: () => new Promise(() => {}) } }));

let host: ExtensionTestHost | undefined;
let portal: HTMLElement;
let send: MockInstance<Websocket['send']>;
let addListener: MockInstance<Websocket['addListener']>;

beforeAll(() => {
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            disconnect() {}
        }
    );
    portal = document.body.appendChild(Object.assign(document.createElement('div'), { id: 'modal-portal' }));
});

afterAll(() => {
    portal.remove();
    vi.unstubAllGlobals();
});

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.restoreAllMocks();
});

/** Stands in for the server area's websocket handler, which records the power state. */
function StatusListener() {
    const setServerStatus = useServerStore((state) => state.status.setServerStatus);

    useWebsocketEvent(SocketEvent.STATUS, (state) => setServerStatus(state as ServerStatus));

    return null;
}

async function renderServer(children: React.ReactNode, options: TestServerOptions = {}) {
    send = vi.spyOn(Websocket.prototype, 'send').mockReturnValue(true);
    addListener = vi.spyOn(Websocket.prototype, 'addListener');
    host = createExtensionTestHost({ server: createTestServer(options) });

    await act(async () => {
        render(
            <>
                <StatusListener />
                {children}
            </>,
            { wrapper: host!.Wrapper }
        );
    });

    return host;
}

const status = (state: string) => act(() => host!.emitWebsocket('status', state));

const stats = (overrides: Partial<{ cpu: number; memory: number; disk: number; uptime: number }> = {}) =>
    act(() =>
        host!.emitWebsocket(
            'stats',
            JSON.stringify({
                cpu_absolute: overrides.cpu ?? 42.5,
                memory_bytes: overrides.memory ?? 512 * 1024 ** 2,
                disk_bytes: overrides.disk ?? 256 * 1024 ** 2,
                uptime: overrides.uptime ?? 3_723_000,
                network: { rx_bytes: 2048, tx_bytes: 1024 },
            })
        )
    );

describe('server.power', () => {
    it('sends the signal of each button the user may press', async () => {
        await renderServer(<View name='server.power' />);
        await status('offline');

        fireEvent.click(screen.getByRole('button', { name: 'Start' }));
        expect(send).toHaveBeenCalledWith('set state', 'start');
        expect(screen.getByRole('button', { name: 'Stop' })).toBeDisabled();

        await status('running');
        expect(screen.getByRole('status')).toHaveTextContent('Running');
        expect(screen.getByRole('button', { name: 'Start' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Restart' }));
        expect(send).toHaveBeenLastCalledWith('set state', 'restart');
    });

    it('asks before killing a stopping server', async () => {
        await renderServer(<View name='server.power' />);
        await status('stopping');

        fireEvent.click(screen.getByRole('button', { name: 'Kill' }));
        const dialog = await screen.findByRole('dialog');

        expect(send).not.toHaveBeenCalledWith('set state', 'kill');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Kill' }));
        expect(send).toHaveBeenCalledWith('set state', 'kill');
    });

    it('shows nothing but its slots without a power permission', async () => {
        await renderServer(<View name='server.power' />, { owner: false, permissions: ['file.read'] });

        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
});

describe('server.stats', () => {
    it('reads the usage Wings reports once, however many times it renders', async () => {
        await renderServer(
            <>
                <div data-testid='header'>
                    <View name='server.stats' />
                </div>
                <View name='server.stats' />
            </>
        );

        expect(addListener.mock.calls.filter(([event]) => event === 'stats')).toHaveLength(1);
        expect(send).toHaveBeenCalledWith('send stats');

        await status('running');
        await stats();

        const header = within(screen.getByTestId('header'));

        expect(header.getByText('1h 2m 3s')).toBeVisible();
        expect(screen.getAllByText('1h 2m 3s')).toHaveLength(2);
    });

    it('counts the players the console reports while the server runs', async () => {
        await renderServer(
            <>
                <View name='server.terminal' />
                <View name='server.stats' />
            </>,
            { eggFeatures: ['eula'] }
        );
        await status('running');

        expect(screen.queryByText('Players')).not.toBeInTheDocument();
        act(() =>
            host!.emitWebsocket('console output', '[12:00:00 INFO]: There are 2 of a max of 20 players online: a, b')
        );
        expect(await screen.findByText('2 / 20')).toBeVisible();

        act(() => host!.emitWebsocket('console output', '[12:00:01 INFO]: Steve joined the game'));
        expect(await screen.findByText('3 / 20')).toBeVisible();

        await status('offline');
        expect(screen.queryByText('Players')).not.toBeInTheDocument();
    });

    it('flags usage close to the limits', async () => {
        let model: ServerStatsModel | undefined;

        registerViewLayer('test-stats', 'theme', {
            'server.stats': (props: { model: ServerStatsModel }) => {
                model = props.model;

                return null;
            },
        });

        try {
            await renderServer(<View name='server.stats' />);
            await status('running');
            await stats({ cpu: 95, memory: 870 * 1024 ** 2 });

            expect(model?.cpu.alarm).toBe('danger');
            expect(model?.memory.alarm).toBe('warning');
            expect(model?.disk.alarm).toBeNull();
        } finally {
            clearThemeLayers();
        }
    });
});

describe('server.graphs', () => {
    it('collects samples while the server runs and clears them when it stops', async () => {
        let model: ServerGraphsModel | undefined;

        registerViewLayer('test-graphs', 'theme', {
            'server.graphs': (props: { model: ServerGraphsModel }) => {
                model = props.model;

                return null;
            },
        });

        try {
            await renderServer(<View name='server.graphs' />);
            await status('running');
            await stats({ cpu: 10 });
            await stats({ cpu: 20 });

            expect(model?.live).toBe(true);
            expect(model?.cpu.points.map((point) => point.value)).toEqual([10, 20]);
            expect(model?.memory.limit).toBe(1024);
            expect(model?.network.points.at(-1)).toMatchObject({ rx: 0, tx: 0 });
            expect(model?.disk.points.at(-1)?.value).toBe(256);
            expect(model?.disk.limit).toBe(1024);

            await status('offline');
            expect(model?.live).toBe(false);
            expect(model?.cpu.points).toEqual([]);
        } finally {
            clearThemeLayers();
        }
    });
});

describe('server.terminal', () => {
    it('sends commands and steps through their history', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        await renderServer(<View name='server.terminal' />);
        const input = screen.getByRole('combobox', { name: 'Console command input.' });

        fireEvent.change(input, { target: { value: 'say hello' } });
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(send).toHaveBeenCalledWith('send command', 'say hello');
        expect(input).toHaveValue('');

        fireEvent.change(input, { target: { value: 'list' } });
        fireEvent.keyDown(input, { key: 'Enter' });
        fireEvent.keyDown(input, { key: 'ArrowUp' });
        expect(input).toHaveValue('list');
        fireEvent.keyDown(input, { key: 'ArrowUp' });
        expect(input).toHaveValue('say hello');
        fireEvent.keyDown(input, { key: 'ArrowDown' });
        fireEvent.keyDown(input, { key: 'ArrowDown' });
        expect(input).toHaveValue('');
    });

    it('has no command line without the console permission', async () => {
        await renderServer(<View name='server.terminal' />, { owner: false, permissions: ['control.start'] });

        expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
    });

    it('completes the common commands of the egg with Tab', async () => {
        await renderServer(<View name='server.terminal' />, { eggFeatures: ['eula'] });
        const input = screen.getByRole('combobox', { name: 'Console command input.' });

        fireEvent.change(input, { target: { value: 'white' } });
        expect(screen.getByRole('option', { name: 'whitelist' })).toBeVisible();
        fireEvent.keyDown(input, { key: 'Tab' });
        expect(input).toHaveValue('whitelist ');
        expect(screen.getAllByRole('option').map((option) => option.textContent)).toEqual([
            'whitelist add',
            'whitelist remove',
            'whitelist list',
            'whitelist on',
            'whitelist off',
        ]);
        fireEvent.keyDown(input, { key: 'ArrowDown' });
        fireEvent.keyDown(input, { key: 'Tab' });
        expect(input).toHaveValue('whitelist remove ');
    });

    it('shows the output as text, with only https links clickable', async () => {
        await renderServer(<View name='server.terminal' />);

        act(() => {
            host!.emitWebsocket('console output', '<img src=x onerror="alert(1)"> [31mred[0m');
            host!.emitWebsocket(
                'console output',
                'see https://example.com/docs. or javascript:alert(1) or http://plain.example'
            );
            host!.emitWebsocket('console output', ']8;;javascript:alert(1)click]8;; me');
        });

        const log = await screen.findByRole('log', { name: 'Console output' });

        await waitFor(() => expect(log).toHaveTextContent('click me'));
        expect(log.querySelector('img')).toBeNull();
        expect(log).toHaveTextContent('<img src=x onerror="alert(1)"> red');
        expect(within(log).getByText('red')).toHaveStyle({ color: 'var(--terminal-ansi-red)' });

        const links = within(log).getAllByRole('link');

        expect(links).toHaveLength(1);
        expect(links[0]).toHaveAttribute('href', 'https://example.com/docs');
        expect(links[0]).toHaveAttribute('rel', 'noopener noreferrer nofollow');
        expect(links[0]).toHaveAttribute('target', '_blank');
    });

    it('filters the output by a search and pauses it', async () => {
        await renderServer(<View name='server.terminal' />);

        act(() => {
            host!.emitWebsocket('console output', '[12:00:00 INFO]: Loading world');
            host!.emitWebsocket('console output', '[12:00:01 WARN]: Can not keep up!');
        });

        const log = await screen.findByRole('log', { name: 'Console output' });

        await waitFor(() => expect(log).toHaveTextContent('Can not keep up!'));
        expect(log.querySelector('[data-level="warn"]')).toHaveTextContent('Can not keep up!');

        fireEvent.change(screen.getByRole('searchbox', { name: 'Search the console output' }), {
            target: { value: 'keep' },
        });
        await waitFor(() => expect(log.querySelector('mark')).toHaveTextContent('keep'));
        fireEvent.click(screen.getByRole('button', { name: 'Only show matching lines' }));
        expect(log).not.toHaveTextContent('Loading world');

        fireEvent.click(screen.getByRole('button', { name: 'Pause the console' }));
        act(() => host!.emitWebsocket('console output', 'keep this for later'));
        expect(await screen.findByRole('button', { name: /Paused · 1 new line/ })).toBeVisible();
        expect(log).not.toHaveTextContent('keep this for later');

        fireEvent.click(screen.getByRole('button', { name: /Paused/ }));
        await waitFor(() => expect(log).toHaveTextContent('keep this for later'));
    });
});

describe('feature prompts', () => {
    it('names the prompts the egg enables', () => {
        expect(featureViews(['EULA', 'pid_limit', 'unknown'])).toEqual([
            'server.feature.eula',
            'server.feature.pidLimit',
        ]);
    });

    it('opens the EULA prompt on the console when the output asks for it', async () => {
        await renderServer(<View name='page.server.console' active />, { eggFeatures: ['EULA'] });
        await status('offline');

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        act(() => host!.emitWebsocket('console output', 'You need to agree to the EULA in order to run the server.'));

        expect(await screen.findByRole('dialog')).toHaveTextContent('Minecraft® EULA');
    });
});

describe('page.server.console', () => {
    it('keeps the title while hidden, and composes the blocks', async () => {
        await renderServer(<View name='page.server.console' active={false} />);

        await waitFor(() => expect(screen.getByRole('heading', { name: 'Test server' })).toBeInTheDocument());
        expect(screen.getByRole('button', { name: 'Start' })).toBeInTheDocument();
        expect(screen.getByText('CPU Load', { selector: 'h3' })).toBeInTheDocument();
    });

    it('warns above the console while the server installs', async () => {
        await renderServer(<View name='page.server.console' active />, { status: 'installing' });

        expect(await screen.findByText(/running its installation process/)).toBeVisible();
    });
});
