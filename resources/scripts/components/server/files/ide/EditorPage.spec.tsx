/** @vitest-environment jsdom */
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { AxiosAdapter } from 'axios';
import type * as Testing from '@/sdk/testing';
import type * as Http from '@/api/http';
import type EditorPageComponent from './EditorPage';
import type { MonacoCanvasProps } from './monaco/MonacoCanvas';

// Monaco needs a real browser; a textarea per file stands in for its models.
const models = new Map<string, string>();

vi.mock('./monaco/MonacoCanvas', () => ({
    default: ({ path, content, readOnly, onChange }: MonacoCanvasProps) => (
        <textarea
            key={path}
            aria-label={`Editor ${path}`}
            readOnly={readOnly}
            defaultValue={models.get(path) ?? content}
            onChange={(event) => {
                models.set(path, event.target.value);
                onChange(path, event.target.value);
            }}
        />
    ),
}));

const entry = (name: string, isFile = true, size = 12, mimetype = isFile ? 'text/plain' : 'inode/directory') => ({
    object: 'file_object',
    attributes: {
        name,
        mode: isFile ? '-rw-r--r--' : 'drwxr-xr-x',
        mode_bits: isFile ? '0644' : '0755',
        size,
        is_file: isFile,
        is_symlink: false,
        mimetype,
        created_at: '2026-01-01T12:00:00Z',
        modified_at: '2026-01-01T12:00:00Z',
    },
});

const listings = new Map<string, ReturnType<typeof entry>[]>([
    ['/', [entry('config', false), entry('server.properties'), entry('world.zip', true, 900, 'application/zip')]],
    ['/config', [entry('app.yml'), entry('huge.log', true, 6 * 1024 * 1024), entry('rules.json')]],
    ['/plugins/Essentials', [entry('config.yml')]],
]);

const searchResult = {
    object: 'list',
    data: [
        {
            object: 'file_search_result',
            attributes: { path: '/plugins/Essentials/config.yml', name: 'config.yml', is_file: true, size: 5 },
        },
    ],
    meta: { truncated: false },
};

/** What the fake panel answers to a request for a path. */
function respond(pathname: string, params: URLSearchParams) {
    if (pathname.endsWith('/files/list')) {
        return { object: 'list', data: listings.get(params.get('directory') ?? '/') ?? [] };
    }

    if (pathname.endsWith('/files/contents')) {
        return `contents of ${params.get('file')}`;
    }

    return pathname.endsWith('/files/search') ? searchResult : '';
}

let testing: typeof Testing;
let http: typeof Http.default;
let EditorPage: typeof EditorPageComponent;
let host: Testing.ExtensionTestHost | undefined;
let originalAdapter: typeof http.defaults.adapter;
let requests: { method: string; path: string; params: Record<string, string>; body: unknown }[];
let holdWrites: Promise<void> | null;

beforeEach(async () => {
    vi.resetModules();
    models.clear();
    const [sdk, transport, page] = await Promise.all([
        import('@/sdk/testing'),
        import('@/api/http'),
        import('./EditorPage'),
    ]);

    testing = sdk;
    http = transport.default;
    EditorPage = page.default;
    requests = [];
    holdWrites = null;
    originalAdapter = http.defaults.adapter;
    const adapter: AxiosAdapter = async (config) => {
        const url = new URL(config.url!, 'https://panel.test');
        const params = new URLSearchParams([
            ...url.searchParams,
            ...Object.entries((config.params ?? {}) as Record<string, string>),
        ]);

        requests.push({
            method: config.method!,
            path: url.pathname,
            params: Object.fromEntries(params),
            body: config.data,
        });
        if (url.pathname.endsWith('/files/write') && holdWrites) {
            await holdWrites;
        }

        return {
            data: respond(url.pathname, params),
            config,
            status: url.pathname.endsWith('/files/write') ? 204 : 200,
            statusText: 'OK',
            headers: {},
        };
    };

    http.defaults.adapter = adapter;
    vi.spyOn(console, 'error').mockImplementation(() => {});
}, 20000);

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    http.defaults.adapter = originalAdapter;
    vi.restoreAllMocks();
});

function open(search = '', permissions?: string[]) {
    host = testing.createExtensionTestHost({
        server: testing.createTestServer(permissions ? { owner: false, permissions } : {}),
        path: `/server/test-server/editor${search}`,
    });
    render(<EditorPage />, { wrapper: host.Wrapper });

    return host;
}

const writes = () => requests.filter((request) => request.path.endsWith('/files/write'));
const contentFetches = () => requests.filter((request) => request.path.endsWith('/files/contents'));
const statusBar = () => screen.getByRole('contentinfo', { name: 'Status bar' });
const tab = (name: string) => screen.findByRole('tab', { name });
const press = (key: string) => fireEvent.keyDown(window, { key, ctrlKey: true });

it('opens the file in the address with its language, size and encoding in the status bar', async () => {
    const editor = open('?path=%2Fconfig%2Fapp.yml');

    expect(await screen.findByLabelText('Editor /config/app.yml')).toHaveValue('contents of /config/app.yml');
    expect(await tab('app.yml')).toHaveAttribute('aria-selected', 'true');
    expect(statusBar()).toHaveTextContent('Ln 1, Col 1');
    expect(statusBar()).toHaveTextContent('UTF-8');
    expect(statusBar()).toHaveTextContent('27 Bytes');
    expect(statusBar()).toHaveTextContent('YAML');
    // The tree lists the root and expands the folders down to the open file.
    expect(await screen.findByRole('treeitem', { name: 'config' })).toHaveAttribute('aria-expanded', 'true');
    expect(editor.location().search).toEqual({ path: '/config/app.yml' });
});

it('lists a folder only once it is expanded and opens files from the tree in tabs', async () => {
    const editor = open();

    const folder = await screen.findByRole('treeitem', { name: 'config' });

    expect(requests.some((request) => request.params.directory === '/config')).toBe(false);

    fireEvent.click(within(folder).getByRole('button', { name: 'config' }));
    fireEvent.click(await screen.findByRole('button', { name: 'rules.json' }));
    expect(await screen.findByLabelText('Editor /config/rules.json')).toHaveValue('contents of /config/rules.json');

    fireEvent.click(screen.getByRole('button', { name: 'server.properties' }));
    expect(await screen.findByLabelText('Editor /server.properties')).toBeVisible();
    expect(statusBar()).toHaveTextContent('Properties');
    expect(screen.getAllByRole('tab').map((element) => element.textContent)).toEqual([
        'rules.json',
        'server.properties',
    ]);
    await waitFor(() => expect(editor.location().search).toEqual({ path: '/server.properties' }));

    fireEvent.click(await tab('rules.json'));
    expect(await screen.findByLabelText('Editor /config/rules.json')).toBeVisible();
    // A file opened from the tree is not fetched twice, nor listed again.
    expect(contentFetches()).toHaveLength(2);
});

it('marks a changed tab and saves it with Ctrl+S', async () => {
    open('?path=%2Fconfig%2Fapp.yml');

    fireEvent.change(await screen.findByLabelText('Editor /config/app.yml'), { target: { value: 'motd: hi' } });
    expect(await screen.findByTestId('unsaved-dot')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Close app.yml (unsaved changes)' })).toBeVisible();

    press('s');

    await waitFor(() => expect(screen.queryByTestId('unsaved-dot')).not.toBeInTheDocument());
    expect(writes()).toEqual([
        expect.objectContaining({ method: 'post', params: { file: '/config/app.yml' }, body: 'motd: hi' }),
    ]);
});

it('saves the file the save started on, even when the user switches tabs and keeps typing', async () => {
    let release = () => {};

    open('?path=%2Fconfig%2Fapp.yml');

    fireEvent.change(await screen.findByLabelText('Editor /config/app.yml'), { target: { value: 'first' } });
    holdWrites = new Promise((resolve) => {
        release = resolve;
    });
    press('s');
    await waitFor(() => expect(writes()).toHaveLength(1));

    fireEvent.click(screen.getByRole('button', { name: 'server.properties' }));
    fireEvent.change(await screen.findByLabelText('Editor /server.properties'), { target: { value: 'second' } });
    await act(async () => release());

    await waitFor(() => expect(screen.getAllByTestId('unsaved-dot')).toHaveLength(1));
    expect(writes()).toEqual([expect.objectContaining({ params: { file: '/config/app.yml' }, body: 'first' })]);
    expect(screen.getByRole('button', { name: 'Close server.properties (unsaved changes)' })).toBeVisible();
    expect(screen.getByRole('button', { name: 'Close app.yml' })).toBeVisible();
});

it('keeps every file read-only without the update permission', async () => {
    open('?path=%2Fconfig%2Fapp.yml', ['file.read', 'file.read-content']);

    const buffer = await screen.findByLabelText('Editor /config/app.yml');

    expect(buffer).toHaveAttribute('readonly');
    expect(screen.getByLabelText('Read-only')).toBeVisible();
    expect(statusBar()).toHaveTextContent('Read-only');
    expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();

    fireEvent.change(buffer, { target: { value: 'edited' } });
    press('s');

    await new Promise((resolve) => setTimeout(resolve, 20));
    expect(screen.queryByTestId('unsaved-dot')).not.toBeInTheDocument();
    expect(writes()).toEqual([]);
});

it('refuses files over 5 MB and files that are not text, without fetching them', async () => {
    open('?path=%2Fconfig%2Fhuge.log');

    expect(await screen.findByRole('alert')).toHaveTextContent('the editor opens files up to 5 MiB');
    fireEvent.click(screen.getByRole('button', { name: 'world.zip' }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('This file is not text'));
    expect(contentFetches()).toEqual([]);
});

it('asks before closing a tab with unsaved changes, and closes the others on request', async () => {
    open('?path=%2Fconfig%2Fapp.yml');

    fireEvent.change(await screen.findByLabelText('Editor /config/app.yml'), { target: { value: 'edited' } });
    fireEvent.click(screen.getByRole('button', { name: 'server.properties' }));
    await screen.findByLabelText('Editor /server.properties');

    fireEvent.click(screen.getByRole('button', { name: 'Close app.yml (unsaved changes)' }));
    const dialog = await screen.findByRole('dialog', { name: 'Discard unsaved changes?' });

    expect(dialog).toHaveTextContent('Your changes to app.yml will be lost.');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(screen.getAllByRole('tab')).toHaveLength(2);

    fireEvent.click(screen.getByRole('button', { name: 'Close app.yml (unsaved changes)' }));
    fireEvent.click(
        within(await screen.findByRole('dialog', { name: 'Discard unsaved changes?' })).getByRole('button', {
            name: 'Discard',
        })
    );
    await waitFor(() =>
        expect(screen.getAllByRole('tab').map((element) => element.textContent)).toEqual(['server.properties'])
    );
    expect(writes()).toEqual([]);

    fireEvent.click(screen.getByRole('button', { name: 'Close server.properties' }));
    expect(await screen.findByText('Open a file from the tree to start editing.')).toBeVisible();
});

it('opens a file by name with Ctrl+P, from loaded folders and the server search', async () => {
    open();
    await screen.findByRole('button', { name: 'server.properties' });

    press('p');
    const input = await screen.findByRole('combobox', { name: 'Search files by name' });

    fireEvent.change(input, { target: { value: 'srvprop' } });
    expect(screen.getAllByRole('option').map((option) => option.textContent)).toEqual(['server.properties/']);

    fireEvent.change(input, { target: { value: 'config' } });
    expect(await screen.findByRole('option', { name: /config\.yml/ })).toBeVisible();
    expect(requests.find((request) => request.path.endsWith('/files/search'))?.params).toEqual({ query: 'config' });

    fireEvent.keyDown(input, { key: 'Enter' });
    await waitFor(() => expect(screen.queryByRole('combobox')).not.toBeInTheDocument());
    expect(await tab('config.yml')).toHaveAttribute('aria-selected', 'true');
    expect(await screen.findByLabelText('Editor /plugins/Essentials/config.yml')).toHaveValue(
        'contents of /plugins/Essentials/config.yml'
    );
});

it('asks before leaving the editor with unsaved changes, but not when switching files', async () => {
    const editor = open('?path=%2Fconfig%2Fapp.yml');
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);

    fireEvent.change(await screen.findByLabelText('Editor /config/app.yml'), { target: { value: 'edited' } });
    fireEvent.click(screen.getByRole('button', { name: 'server.properties' }));
    await waitFor(() => expect(editor.location().search).toEqual({ path: '/server.properties' }));
    expect(confirm).not.toHaveBeenCalled();

    act(() => void editor.navigate({ to: '/server/$id/files', params: { id: 'test-server' } }));
    await waitFor(() => expect(confirm).toHaveBeenCalledTimes(1));
    expect(editor.location().pathname).toBe('/server/test-server/editor');
});
