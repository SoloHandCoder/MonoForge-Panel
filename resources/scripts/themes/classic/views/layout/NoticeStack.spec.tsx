/** @vitest-environment jsdom */
/* oxlint-disable rules/classic-theme-imports -- the spec renders with testing-library */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { PanelNotice } from '@/sdk/theme';
import NoticeStack from './NoticeStack';

afterEach(cleanup);

const notice = (
    id: string,
    level: PanelNotice['level'],
    title: string,
    extra: Partial<PanelNotice> = {}
): PanelNotice => ({
    id,
    level,
    title,
    body: `${title} details.`,
    dismissible: true,
    action: null,
    ...extra,
});

const notices: PanelNotice[] = [
    notice('announcement:1', 'danger', 'Panel maintenance', { dismissible: false }),
    notice('announcement:2', 'warning', 'Node maintenance'),
    notice('announcement:3', 'info', 'New eggs'),
    notice('notice:1', 'success', 'Backup restored', { action: { label: 'Open account', url: '/account' } }),
];

describe('NoticeStack', () => {
    it('renders nothing without notices', () => {
        const { container } = render(<NoticeStack notices={[]} onDismiss={vi.fn()} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('colours each notice by its level and offers its action', () => {
        render(<NoticeStack notices={notices} onDismiss={vi.fn()} />);

        const region = screen.getByRole('region', { name: 'Notices' });
        const levels = [...region.querySelectorAll('[data-level]')].map((node) => node.getAttribute('data-level'));

        expect(levels).toEqual(['danger', 'warning', 'info', 'success']);
        expect(screen.getByRole('alert')).toHaveTextContent('Panel maintenance');
        expect(screen.getByRole('alert')).toHaveClass('border-destructive/40');
        expect(screen.getByText('Node maintenance').closest('[data-level]')).toHaveClass('bg-warning/10');
        expect(screen.getByText('New eggs').closest('[data-level]')).toHaveClass('bg-primary/10');
        expect(screen.getByText('Backup restored').closest('[data-level]')).toHaveClass('bg-success/10');
        expect(screen.getByRole('link', { name: 'Open account' })).toHaveAttribute('href', '/account');
    });

    it('dismisses only notices that allow it', () => {
        const onDismiss = vi.fn();

        render(<NoticeStack notices={notices} onDismiss={onDismiss} />);

        expect(screen.queryByRole('button', { name: 'Dismiss Panel maintenance' })).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Dismiss Node maintenance' }));

        expect(onDismiss).toHaveBeenCalledWith('announcement:2');
    });
});
