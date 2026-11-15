/** @vitest-environment jsdom */
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { AssistantToolCall } from '@/api/assistant';
import ToolCallCard from './ToolCallCard';

afterEach(cleanup);

const call = (overrides: Partial<AssistantToolCall> = {}): AssistantToolCall => ({
    id: 3,
    name: 'read_file',
    label: 'Read .env',
    arguments: { path: '.env' },
    details: [{ label: 'path', value: '/.env' }],
    needs_confirmation: true,
    status: 'pending',
    result_preview: null,
    display: null,
    ...overrides,
});

describe('ToolCallCard', () => {
    it('calls back with approve and deny', async () => {
        const onDecide = vi.fn();

        render(<ToolCallCard call={call()} onDecide={onDecide} />);

        await userEvent.click(screen.getByRole('button', { name: 'Allow: Read .env' }));
        await userEvent.click(screen.getByRole('button', { name: 'Deny: Read .env' }));

        expect(onDecide.mock.calls).toEqual([['approve'], ['deny']]);
    });

    it('tints a sensitive read amber and a destructive action red', () => {
        const { rerender } = render(<ToolCallCard call={call()} onDecide={vi.fn()} />);

        expect(screen.getByTestId('tool-call')).toHaveAttribute('data-tone', 'warning');
        expect(screen.getByText(/may contain secrets/)).toBeInTheDocument();

        rerender(<ToolCallCard call={call({ name: 'power', label: 'Restart the server' })} onDecide={vi.fn()} />);

        expect(screen.getByTestId('tool-call')).toHaveAttribute('data-tone', 'danger');
        expect(screen.getByText(/power state/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Allow: Restart the server' })).toBeEnabled();
    });

    it('shows no buttons once decided, and disables both while a decision is sent', () => {
        const { rerender } = render(
            <ToolCallCard call={call({ status: 'done', result_preview: 'APP_KEY=…' })} onDecide={vi.fn()} />
        );

        expect(screen.queryByRole('button', { name: /Allow/ })).not.toBeInTheDocument();
        expect(screen.getByText('Done')).toBeInTheDocument();

        rerender(<ToolCallCard call={call()} onDecide={vi.fn()} deciding='approve' />);

        expect(screen.getByRole('button', { name: 'Allow: Read .env' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Deny: Read .env' })).toBeDisabled();
    });

    it('expands to the arguments and result', async () => {
        render(<ToolCallCard call={call({ status: 'done', needs_confirmation: false, result_preview: 'hello' })} />);

        const toggle = screen.getByRole('button', { name: 'Show details' });

        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await userEvent.click(toggle);

        expect(toggle).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByText(/"path": ".env"/)).toBeInTheDocument();
        expect(screen.getByText('hello')).toBeInTheDocument();
    });

    it('shows every argument of a pending call in full, without opening anything', () => {
        const command = `say Restarting soon ${'. '.repeat(80)}rcon-secret-here`;

        render(
            <ToolCallCard
                call={call({
                    name: 'send_command',
                    label: `Run the command: ${command.slice(0, 100)}…`,
                    arguments: { command },
                    details: [{ label: 'command', value: command }],
                })}
                onDecide={vi.fn()}
            />
        );

        const details = screen.getByTestId('tool-call-details');

        expect(details).toBeVisible();
        expect(details).toHaveTextContent('rcon-secret-here');
        expect(screen.getByRole('button', { name: 'Show details' })).toHaveAttribute('aria-expanded', 'false');
        expect(screen.getByText(/^Run the command:/)).not.toHaveClass('truncate');
    });

    it('lists every path a pending delete removes', () => {
        render(
            <ToolCallCard
                call={call({
                    name: 'delete_files',
                    label: 'Delete 3 files in /logs',
                    arguments: { root: '/logs', files: ['a.log', 'b.log', 'c.log'] },
                    details: [{ label: 'Paths (3)', value: '/logs/a.log\n/logs/b.log\n/logs/c.log' }],
                })}
                onDecide={vi.fn()}
            />
        );

        expect(screen.getByTestId('tool-call-details')).toHaveTextContent('/logs/a.log /logs/b.log /logs/c.log');
    });

    it('keeps decided calls compact', () => {
        render(<ToolCallCard call={call({ status: 'done', result_preview: 'ok' })} onDecide={vi.fn()} />);

        expect(screen.queryByTestId('tool-call-details')).not.toBeInTheDocument();
        expect(screen.getByText('Read .env')).toHaveClass('truncate');
    });
});
