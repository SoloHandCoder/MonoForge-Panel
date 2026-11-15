/** @vitest-environment jsdom */

import { AxiosError, AxiosHeaders } from 'axios';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as PageQueries from '@/api/server/page/queries';
import { slugProblem, suggestSlug, type ServerPageSettings } from '@/api/server/page/queries';
import ServerShareContainer, { bannerProblem } from './ServerShareContainer';

const state = vi.hoisted(() => ({
    settings: undefined as ServerPageSettings | undefined,
    save: vi.fn(),
    upload: vi.fn(),
    remove: vi.fn(),
}));

vi.mock('@/api/server/page/queries', async (importOriginal) => ({
    ...(await importOriginal<typeof PageQueries>()),
    useServerPage: () => ({ data: state.settings, isPending: false, isError: false }),
    useSaveServerPage: () => ({ mutate: state.save, isPending: false }),
    useUploadServerPageBanner: () => ({ mutate: state.upload, isPending: false }),
    useDeleteServerPageBanner: () => ({ mutate: state.remove, isPending: false }),
}));
vi.mock('@/api/server/queries', () => ({
    useCurrentServerUuid: () => 'd3aac109-e5a0-4331-b03e-3454f7e02bbe',
    useCurrentServerName: () => 'My Skyblock!',
}));
vi.mock('@/components/elements/ServerContentBlock', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const settings = (overrides: Partial<ServerPageSettings> = {}): ServerPageSettings => ({
    exists: false,
    slug: null,
    url: null,
    published: false,
    noindex: false,
    description: '',
    banner_color: null,
    banner_image_url: null,
    votes: 0,
    has_address: false,
    ...overrides,
});

afterEach(cleanup);
beforeEach(() => {
    vi.clearAllMocks();
    state.settings = settings();
});

describe('public page settings', () => {
    it('suggests a slug from the server name, warns about the missing address and saves', async () => {
        const user = userEvent.setup();

        render(<ServerShareContainer />);

        const slug = screen.getByLabelText('Page address');

        expect(slug).toHaveValue('my-skyblock');
        expect(screen.getByText('No join address yet')).toBeInTheDocument();
        expect(screen.getByText('Save the page first, then add a banner image.')).toBeInTheDocument();

        await user.click(screen.getByRole('switch', { name: /Published/ }));
        await user.type(screen.getByLabelText('Description'), 'Hello **world**');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(state.save).toHaveBeenCalledWith(
            {
                path: { server_uuid: 'd3aac109-e5a0-4331-b03e-3454f7e02bbe' },
                body: {
                    slug: 'my-skyblock',
                    published: true,
                    noindex: false,
                    description: 'Hello **world**',
                    banner_color: '',
                },
            },
            expect.anything()
        );
    });

    it('blocks a malformed slug before sending it and shows the panel’s own refusal', async () => {
        const user = userEvent.setup();

        state.save.mockImplementation((_input, options: { onError: (error: AxiosError) => void }) =>
            options.onError(
                new AxiosError('Invalid', '422', undefined, undefined, {
                    status: 422,
                    statusText: 'Unprocessable',
                    headers: {},
                    config: { headers: new AxiosHeaders() },
                    data: {
                        errors: [
                            {
                                code: 'ValidationException',
                                status: '422',
                                detail: 'That address is already taken.',
                                meta: { source_field: 'slug' },
                            },
                        ],
                    },
                })
            )
        );
        render(<ServerShareContainer />);

        const slug = screen.getByLabelText('Page address');

        await user.clear(slug);
        await user.type(slug, 'a--b');
        expect(screen.getByText(/single hyphens/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();

        await user.clear(slug);
        await user.type(slug, 'taken');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(await screen.findByText('That address is already taken.')).toBeInTheDocument();
    });

    it('shows the live link and votes, previews markdown and uploads a banner', async () => {
        const user = userEvent.setup();

        state.settings = settings({
            exists: true,
            slug: 'skyblock',
            url: 'https://panel.example.com/s/skyblock',
            published: true,
            description: 'A **bold** start',
            votes: 7,
            has_address: true,
        });
        render(<ServerShareContainer />);

        expect(screen.getByText('Your page is live')).toBeInTheDocument();
        expect(screen.getByText('7 votes')).toBeInTheDocument();
        expect(screen.queryByText('No join address yet')).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Preview' }));
        expect(screen.getByText('bold').tagName).toBe('STRONG');

        const file = new File(['x'], 'banner.png', { type: 'image/png' });

        await user.upload(screen.getByLabelText(/PNG, JPEG or WebP/), file);
        expect(state.upload).toHaveBeenCalledWith({
            path: { server_uuid: 'd3aac109-e5a0-4331-b03e-3454f7e02bbe' },
            body: { banner: file },
        });
    });

    it('refuses an svg or an oversized banner without uploading it', async () => {
        const user = userEvent.setup({ applyAccept: false });

        state.settings = settings({ exists: true, slug: 'skyblock', url: 'https://panel.example.com/s/skyblock' });
        render(<ServerShareContainer />);

        await user.upload(
            screen.getByLabelText(/PNG, JPEG or WebP/),
            new File(['<svg/>'], 'x.svg', { type: 'image/svg+xml' })
        );

        expect(screen.getByText('Choose a PNG, JPEG or WebP image.')).toBeInTheDocument();
        expect(state.upload).not.toHaveBeenCalled();
    });
});

describe('slug and banner helpers', () => {
    it('checks slugs like the panel does', () => {
        expect(slugProblem('skyblock')).toBeNull();
        expect(slugProblem('my-server-2')).toBeNull();
        expect(slugProblem('ab')).not.toBeNull();
        expect(slugProblem('a'.repeat(33))).not.toBeNull();
        expect(slugProblem('Sky')).not.toBeNull();
        expect(slugProblem('-sky')).not.toBeNull();
        expect(slugProblem('sky--block')).not.toBeNull();
    });

    it('suggests a slug from a name', () => {
        expect(suggestSlug('My Skyblock!')).toBe('my-skyblock');
        expect(suggestSlug('  Café  Crème ')).toBe('cafe-creme');
        expect(suggestSlug('x'.repeat(40))).toHaveLength(32);
    });

    it('checks banner type and size', () => {
        expect(bannerProblem(new File(['x'], 'a.webp', { type: 'image/webp' }))).toBeNull();
        expect(bannerProblem(new File(['x'], 'a.gif', { type: 'image/gif' }))).not.toBeNull();
        expect(
            bannerProblem(new File([new Uint8Array(1024 * 1024 + 1)], 'a.png', { type: 'image/png' }))
        ).not.toBeNull();
    });
});
