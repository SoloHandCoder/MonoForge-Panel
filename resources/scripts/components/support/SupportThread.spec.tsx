/** @vitest-environment jsdom */

import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SupportThread, { type ThreadMessage } from './SupportThread';

afterEach(cleanup);

const message = (overrides: Partial<ThreadMessage>): ThreadMessage => ({
    id: 1,
    body: 'Hello',
    author: 'steve',
    is_staff: false,
    attachments: [],
    expired_attachments: 0,
    created_at: '2026-11-15T12:00:00+00:00',
    ...overrides,
});

describe('support thread', () => {
    it('says when the images of an old ticket were removed', () => {
        render(
            <SupportThread
                viewer='player'
                loadAttachment={vi.fn()}
                messages={[
                    message({ id: 1, expired_attachments: 1 }),
                    message({ id: 2, expired_attachments: 3 }),
                    message({ id: 3 }),
                ]}
            />
        );

        expect(screen.getAllByTestId('support-expired').map((node) => node.textContent)).toEqual([
            'Attachment expired',
            '3 attachments expired',
        ]);
    });

    it('renders markdown without ever turning markup in a message into elements', () => {
        const { container } = render(
            <SupportThread
                viewer='player'
                loadAttachment={vi.fn()}
                messages={[
                    message({
                        body: '**Bold** <script>alert(1)</script> <img src=x onerror="alert(2)"> [bad](javascript:alert(3))',
                    }),
                ]}
            />
        );

        expect(screen.getByText('Bold').tagName).toBe('STRONG');
        expect(container.querySelector('script')).toBeNull();
        expect(container.querySelector('img')).toBeNull();
        expect(container.querySelector('a[href^="javascript"]')).toBeNull();
        expect(container.textContent).toContain('<script>alert(1)</script>');
    });

    it('marks staff replies and internal notes', () => {
        render(
            <SupportThread
                viewer='staff'
                loadAttachment={vi.fn()}
                messages={[
                    message({ id: 1, author: 'steve', body: 'It crashes' }),
                    message({ id: 2, author: 'admin', is_staff: true, body: 'Restart it' }),
                    message({ id: 3, author: 'admin', is_staff: true, is_internal: true, body: 'Probably a mod' }),
                ]}
            />
        );

        const items = screen.getAllByTestId('support-message');

        expect(within(items[0]).queryByText('Staff')).toBeNull();
        expect(within(items[1]).getByText('Staff')).toBeTruthy();
        expect(screen.getByRole('article', { name: 'Internal note by admin' })).toBeTruthy();
        expect(within(items[2]).getByText('Internal note')).toBeTruthy();
    });

    it('fetches attachments through the API and shows them as images', async () => {
        const load = vi.fn(() => Promise.resolve(new Blob(['png'], { type: 'image/png' })));
        const createObjectURL = vi.fn(() => 'blob:support-1');

        vi.stubGlobal('URL', Object.assign(URL, { createObjectURL, revokeObjectURL: vi.fn() }));

        render(
            <SupportThread
                viewer='player'
                loadAttachment={load}
                messages={[
                    message({
                        attachments: [
                            { id: 7, name: 'crash.png', mime: 'image/png', size: 2048, width: 640, height: 480 },
                        ],
                    }),
                ]}
            />
        );

        expect(await screen.findByRole('img', { name: 'crash.png' })).toHaveAttribute('src', 'blob:support-1');
        expect(load).toHaveBeenCalledWith(expect.objectContaining({ id: 7 }));
        vi.unstubAllGlobals();
    });
});
