/** @vitest-environment jsdom */

import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AuthenticatedLayout from './AuthenticatedLayout';

const session = vi.hoisted(() => ({ active: false }));

vi.mock('@/bootstrap', () => ({ hasBootstrapSession: () => session.active }));
vi.mock('@/router/viewRoutes', () => ({
    layoutRoute: () => Object.assign(() => <p>Panel layout</p>, { preload: () => Promise.resolve() }),
}));
vi.mock('@tanstack/react-router', () => ({
    lazyRouteComponent: () => Object.assign(() => <p>Public homepage</p>, { preload: () => Promise.resolve() }),
}));

afterEach(cleanup);

describe('authenticated layout', () => {
    it('shows the public homepage to a visitor without a session', () => {
        session.active = false;
        render(<AuthenticatedLayout />);

        expect(screen.getByText('Public homepage')).toBeDefined();
        expect(screen.queryByText('Panel layout')).toBeNull();
    });

    it('keeps the panel for a signed-in user', () => {
        session.active = true;
        render(<AuthenticatedLayout />);

        expect(screen.getByText('Panel layout')).toBeDefined();
        expect(screen.queryByText('Public homepage')).toBeNull();
    });
});
