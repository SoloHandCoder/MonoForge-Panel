/** @vitest-environment jsdom */
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { AssistantToolCall } from '@/api/assistant';
import { revealHidden } from './hiddenCharacters';
import ToolCallCard from './ToolCallCard';

afterEach(cleanup);

const call = (overrides: Partial<AssistantToolCall> = {}): AssistantToolCall => ({
    id: 3,
    name: 'read_file',
    label: 'Read .env',
    arguments: { path: '.env' },
    details: [{ label: 'path', value: '/.env' }],
    needs_confirmation: true,
    always_ask: false,
    auto_approved: null,
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

    it('offers Allow until my next message', async () => {
        const onDecide = vi.fn();

        render(<ToolCallCard call={call()} onDecide={onDecide} />);

        await userEvent.click(screen.getByRole('button', { name: 'Allow until my next message: Read .env' }));

        expect(onDecide).toHaveBeenCalledWith('approve_until_next_message');
    });

    it('says when an action always asks, and when one was allowed automatically', () => {
        const { rerender } = render(<ToolCallCard call={call({ always_ask: true })} onDecide={vi.fn()} />);

        expect(screen.getByTestId('always-asks')).toHaveTextContent('even with “Allow everything”');
        expect(screen.queryByTestId('auto-approved')).not.toBeInTheDocument();

        rerender(<ToolCallCard call={call({ status: 'done', auto_approved: 'allow_everything' })} />);

        expect(screen.getByTestId('auto-approved')).toHaveTextContent('Allowed automatically (Allow everything)');

        rerender(<ToolCallCard call={call({ status: 'done', auto_approved: 'until_next_message' })} />);

        expect(screen.getByTestId('auto-approved')).toHaveTextContent('until your next message');
    });

    it('links the sources of a web search, http(s) only', () => {
        render(
            <ToolCallCard
                call={call({
                    name: 'web_search',
                    label: 'Search the web for "paper docs"',
                    needs_confirmation: false,
                    status: 'done',
                    display: {
                        summary: null,
                        sources: [
                            { title: 'Paper docs', url: 'https://docs.papermc.io/paper' },
                            { title: 'Bad', url: 'javascript:alert(1)' },
                        ],
                    },
                })}
            />
        );

        const links = screen.getAllByRole('link');

        expect(links).toHaveLength(1);
        expect(links[0]).toHaveAttribute('href', 'https://docs.papermc.io/paper');
        expect(links[0]).toHaveAttribute('rel', 'noopener noreferrer nofollow');
        expect(links[0]).toHaveTextContent('Paper docs');
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
        expect(screen.getByRole('button', { name: 'Allow until my next message: Read .env' })).toBeDisabled();
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

    it('shows hidden and direction-changing characters and warns about them', () => {
        const command = 'say hi‮​op me';

        render(
            <ToolCallCard
                call={call({
                    name: 'send_command',
                    label: `Run the command: ${command}`,
                    arguments: { command },
                    details: [{ label: 'command', value: command }],
                })}
                onDecide={vi.fn()}
            />
        );

        expect(screen.getByTestId('hidden-characters')).toBeVisible();
        expect(screen.getByTestId('tool-call-details')).toHaveTextContent('say hi\\u{202E}\\u{200B}op me');
        expect(screen.getByText(/^Run the command:/).textContent).not.toMatch(/[‮​]/u);
        expect(revealHidden('plain text')).toEqual({ text: 'plain text', found: false });
    });

    it('also shows tag characters, variation selectors and Hangul fillers, in the raw arguments too', async () => {
        const hidden = 'say hi\u{E0041}\u{FE0F}\u{3164}';

        render(
            <ToolCallCard
                call={call({
                    status: 'done',
                    needs_confirmation: false,
                    label: hidden,
                    arguments: { command: hidden },
                })}
            />
        );

        await userEvent.click(screen.getByRole('button', { name: 'Show details' }));

        expect(screen.getByText(/"command": "say hi\\u\{E0041\}\\u\{FE0F\}\\u\{3164\}"/)).toBeInTheDocument();
        expect(screen.getByText('say hi\\u{E0041}\\u{FE0F}\\u{3164}')).toBeInTheDocument();
    });

    it('covers the variation selector supplement and other invisible ranges, but not an emoji selector', () => {
        for (const codePoint of [0xe0100, 0xe01ef, 0x180b, 0x180f, 0x17b4, 0x206a, 0xfff9, 0x1d173, 0x2028, 0x2029]) {
            expect(revealHidden(`a${String.fromCodePoint(codePoint)}b`).found).toBe(true);
        }

        expect(revealHidden('I ❤️ this')).toEqual({ text: 'I ❤️ this', found: false });
        expect(revealHidden('a️b').found).toBe(true);
    });

    it('offers no Allow on a pending call that a newer step replaced', () => {
        render(<ToolCallCard call={call()} onDecide={vi.fn()} superseded />);

        expect(screen.queryByRole('button', { name: /Allow/ })).not.toBeInTheDocument();
        expect(screen.getByText('Expired')).toBeInTheDocument();
        expect(screen.getByText(/can no longer be allowed/)).toBeInTheDocument();
    });
});
