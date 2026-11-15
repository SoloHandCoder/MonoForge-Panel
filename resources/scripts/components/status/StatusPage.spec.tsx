/** @vitest-environment jsdom */

import { AxiosError, AxiosHeaders } from 'axios';
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { PublicStatus, StatusDay } from '@/api/status/queries';
import StatusPage, { StatusView, dayTone } from './StatusPage';

interface QueryState {
    data?: PublicStatus;
    isPending: boolean;
    error: Error | null;
    refetch: () => void;
}

const query = vi.hoisted(() => ({ current: { isPending: true, error: null, refetch: () => {} } as QueryState }));

vi.mock('@/api/status/queries', () => ({ usePublicStatus: () => query.current }));

const days = (uptime: number | null, responseMs: number | null = null): StatusDay[] =>
    Array.from({ length: 90 }, (_, index) => ({
        date: `2026-${String(8 + Math.floor(index / 30)).padStart(2, '0')}-${String((index % 28) + 1).padStart(2, '0')}`,
        uptime: index < 60 ? null : uptime,
        response_ms: index < 60 ? null : responseMs,
    }));

const status = (overrides: Partial<PublicStatus> = {}): PublicStatus => ({
    state: 'operational',
    checked_at: new Date().toISOString(),
    panel: { name: 'Panel', status: 'operational', uptime: 100, days: days(100, 80), response_ms: 84 },
    nodes: [
        { name: 'EU Node 1', status: 'operational', uptime: 99.98, days: days(99.98) },
        { name: 'EU Node 2', status: 'operational', uptime: 100, days: days(100) },
    ],
    incidents: [],
    ...overrides,
});

afterEach(cleanup);

describe('status page', () => {
    it('shows the overall state, the panel with its response time, and every node', () => {
        render(<StatusView status={status()} />);

        expect(screen.getByRole('heading', { level: 1, name: 'All systems operational' })).toBeVisible();
        expect(screen.getByText(/Checked/)).toBeVisible();
        expect(screen.getByRole('heading', { name: 'Panel' })).toBeVisible();
        expect(screen.getByText('84 ms')).toBeVisible();
        expect(screen.getByRole('heading', { name: 'EU Node 1' })).toBeVisible();
        expect(screen.getByRole('heading', { name: 'EU Node 2' })).toBeVisible();
        expect(screen.getByRole('img', { name: '90-day uptime: 99.98% uptime' })).toBeVisible();
        expect(screen.queryByRole('heading', { name: 'Current incidents' })).toBeNull();
    });

    it('marks a partial outage and the node that is down, and shows incidents', () => {
        render(
            <StatusView
                status={status({
                    state: 'partial_outage',
                    nodes: [
                        { name: 'EU Node 1', status: 'operational', uptime: 100, days: days(100) },
                        { name: 'EU Node 2', status: 'outage', uptime: 91.5, days: days(91.5) },
                    ],
                    incidents: [
                        {
                            id: 3,
                            title: 'EU Node 2 is down',
                            body: 'We are looking into it. <b>soon</b>',
                            level: 'danger',
                            started_at: '2026-11-15T11:40:00+00:00',
                        },
                    ],
                })}
            />
        );

        expect(screen.getByRole('heading', { level: 1, name: 'Partial outage' })).toBeVisible();
        const node = screen.getByRole('heading', { name: 'EU Node 2' }).closest('li') as HTMLElement;

        expect(within(node).getByText('Outage')).toBeVisible();

        const incident = screen
            .getByRole('heading', { name: 'Outage: EU Node 2 is down' })
            .closest('article') as HTMLElement;

        expect(incident).toHaveAttribute('data-level', 'danger');
        // Announcement text is shown as text, never as markup.
        expect(within(incident).getByText(/We are looking into it\./)).toHaveTextContent('<b>soon</b>');
        expect(incident.querySelector('b')).toBeNull();
    });

    it('says when a component has no recent checks and the panel response time is unknown', () => {
        render(
            <StatusView
                status={status({
                    state: 'major_outage',
                    checked_at: null,
                    panel: { name: 'Panel', status: 'unknown', uptime: null, days: days(null), response_ms: null },
                    nodes: [],
                })}
            />
        );

        expect(screen.getByRole('heading', { level: 1, name: 'Major outage' })).toBeVisible();
        expect(screen.getByText('Waiting for the first check')).toBeVisible();
        expect(screen.getByText('No recent data')).toBeVisible();
        expect(screen.getByText('—')).toBeVisible();
    });

    it('colours days by uptime', () => {
        expect(dayTone(null)).toBe('bg-muted');
        expect(dayTone(100)).toBe('bg-success');
        expect(dayTone(99.5)).toBe('bg-success');
        expect(dayTone(97)).toBe('bg-warning');
        expect(dayTone(50)).toBe('bg-destructive');
    });

    it('explains that the page is turned off', () => {
        const response = {
            status: 404,
            statusText: 'Not Found',
            headers: {},
            config: { headers: new AxiosHeaders() },
            data: {},
        };

        query.current = {
            data: undefined,
            isPending: false,
            error: new AxiosError('Not Found', '404', undefined, undefined, response),
            refetch: vi.fn(),
        };

        render(<StatusPage />);

        expect(screen.getByText('The status page is not available.')).toBeVisible();
        expect(document.title).toContain('Status');
    });

    it('offers to try again when the status could not be loaded', () => {
        const refetch = vi.fn();

        query.current = { data: undefined, isPending: false, error: new Error('Network Error'), refetch };

        render(<StatusPage />);
        screen.getByRole('button', { name: 'Try again' }).click();

        expect(refetch).toHaveBeenCalled();
    });
});
