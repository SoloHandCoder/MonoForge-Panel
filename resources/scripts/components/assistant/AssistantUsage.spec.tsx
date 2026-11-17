/** @vitest-environment jsdom */
import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { AssistantStatus } from '@/api/assistant';
import { assistantBlockedReason } from '@/components/server/assistant/ServerAssistantContainer';
import { AssistantUsageMeter, AssistantWarningBanner } from './AssistantUsage';

vi.mock('@/sdk/navigation', () => ({
    PanelLink: ({
        destination,
        children,
        className,
    }: {
        destination: { to: string };
        children: ReactNode;
        className?: string;
    }) => (
        <a href={destination.to} className={className}>
            {children}
        </a>
    ),
}));

afterEach(cleanup);

const status = (overrides: Partial<AssistantStatus> = {}): AssistantStatus => ({
    enabled: true,
    server_enabled: true,
    crash_doctor_enabled: true,
    paused: false,
    free_limit: 50000,
    free_used: 41000,
    ratio: 1000,
    balance: 250,
    warning: null,
    ...overrides,
});

describe('AssistantWarningBanner', () => {
    it('renders nothing without a warning', () => {
        render(<AssistantWarningBanner warning={null} />);

        expect(screen.queryByTestId('assistant-warning')).not.toBeInTheDocument();
    });

    it.each([
        ['near_limit', 'running low'],
        ['free_exhausted', "used this month's free AI tokens"],
        ['no_balance', 'Out of AI tokens'],
    ] as const)('shows the %s banner with a wallet link', (warning, text) => {
        render(<AssistantWarningBanner warning={warning} />);

        expect(screen.getByTestId('assistant-warning')).toHaveAttribute('data-warning', warning);
        expect(screen.getByRole('alert')).toHaveTextContent(text);
        expect(screen.getByRole('link', { name: 'Open your wallet' })).toHaveAttribute('href', '/account/wallet');
    });
});

describe('AssistantUsageMeter', () => {
    it('shows the free allowance, the ratio and the balance', () => {
        render(<AssistantUsageMeter status={status()} />);

        const meter = screen.getByTestId('assistant-usage');

        expect(meter).toHaveTextContent('41,000 / 50,000');
        expect(meter).toHaveTextContent('1,000 AI tokens = 1 token');
        expect(screen.getByRole('link', { name: /250/ })).toHaveAttribute('href', '/account/wallet');
    });
});

describe('assistantBlockedReason', () => {
    it('blocks chatting only when it is off or nothing is left to pay with', () => {
        expect(assistantBlockedReason(undefined)).toBeNull();
        expect(assistantBlockedReason(status({ warning: 'free_exhausted' }))).toBeNull();
        expect(assistantBlockedReason(status({ warning: 'no_balance' }))).toBe('Buy tokens to keep chatting.');
        expect(assistantBlockedReason(status({ server_enabled: false }))).toBe('The assistant is turned off.');
    });
});
