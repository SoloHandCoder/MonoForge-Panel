/** @vitest-environment jsdom */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import type { AxiosAdapter } from 'axios';
import type { PropsWithChildren } from 'react';
import { toast } from 'sonner';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import http from '@/api/http';
import FriendsContainer from './FriendsContainer';

const list = {
    object: 'friend_list',
    attributes: {
        friends: [
            {
                uuid: '6a0c2b9e-3f1d-4c7a-9b8e-2d5f4a1c0e93',
                username: 'sam',
                image: 'https://gravatar.com/avatar/1',
                since: '2026-11-01T12:00:00+00:00',
            },
        ],
        incoming: [
            {
                id: 2,
                username: 'jordan',
                image: 'https://gravatar.com/avatar/2',
                created_at: '2026-11-14T12:00:00+00:00',
            },
        ],
        outgoing: [{ id: 3, identifier: 'alex@example.com', created_at: '2026-11-15T12:00:00+00:00' }],
    },
};

/** What the fake API answers: the list for reads, the uniform message for a request, else no content. */
function reply(method: string | undefined, url: string | undefined) {
    if (method === 'get') {
        return { data: list, status: 200 };
    }

    if (method === 'post' && url === '/api/client/account/friends/requests') {
        return { data: { message: "If that user exists, they'll get a request." }, status: 202 };
    }

    return { data: '', status: 204 };
}

let requests: { method: string; url: string; body: unknown }[];
const adapter = http.defaults.adapter;

beforeEach(() => {
    requests = [];
    http.defaults.adapter = (async (config) => {
        const body: unknown = config.data ? JSON.parse(String(config.data)) : undefined;

        requests.push({ method: config.method ?? 'get', url: config.url ?? '', body });

        return {
            ...reply(config.method, config.url),
            statusText: 'OK',
            headers: {},
            config,
        };
    }) satisfies AxiosAdapter;
});

afterEach(() => {
    cleanup();
    http.defaults.adapter = adapter;
    vi.restoreAllMocks();
});

const wrapper = ({ children }: PropsWithChildren) => (
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        {children}
    </QueryClientProvider>
);

describe('FriendsContainer', () => {
    it('lists friends, requests for the user and the requests they sent', async () => {
        render(<FriendsContainer />, { wrapper });

        const friends = await screen.findByRole('list', { name: 'Friends' });

        expect(within(friends).getByText('sam')).toBeVisible();
        expect(within(screen.getByRole('list', { name: 'Friend requests for you' })).getByText('jordan')).toBeVisible();
        // A sent request shows only what the user typed.
        expect(
            within(screen.getByRole('list', { name: 'Sent friend requests' })).getByText('alex@example.com')
        ).toBeVisible();
    });

    it('sends a request and says only that the user gets it if they exist', async () => {
        const success = vi.spyOn(toast, 'success');

        render(<FriendsContainer />, { wrapper });

        const input = await screen.findByLabelText('Username or email');

        fireEvent.change(input, { target: { value: '  riley ' } });
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Send request' }));
        });

        await waitFor(() => expect(input).toHaveValue(''));
        expect(requests).toContainEqual({
            method: 'post',
            url: '/api/client/account/friends/requests',
            body: { user: 'riley' },
        });
        expect(success).toHaveBeenCalledWith('Friend request sent', {
            description: "If that user exists, they'll get a request.",
        });
    });

    it('accepts, declines and cancels requests', async () => {
        render(<FriendsContainer />, { wrapper });

        await act(async () => {
            fireEvent.click(await screen.findByRole('button', { name: 'Accept jordan' }));
        });
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Decline jordan' }));
        });
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Cancel the request to alex@example.com' }));
        });

        await waitFor(() =>
            expect(
                requests.filter((request) => request.method !== 'get').map(({ method, url }) => `${method} ${url}`)
            ).toEqual([
                'post /api/client/account/friends/requests/2/accept',
                'post /api/client/account/friends/requests/2/decline',
                'delete /api/client/account/friends/requests/3',
            ])
        );
    });
});
