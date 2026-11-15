import { useId, useRef, useState, type KeyboardEvent } from 'react';
import { ChevronsRight, CornerDownLeft } from 'lucide-react';
import { cn } from '@/sdk';
import type { ServerConsoleCommand } from '@/sdk/theme';

/**
 * The command line: Enter sends, ↑/↓ step through the history, and Tab completes the egg's
 * common commands, which show above the input. While suggestions show for typed text, ↑/↓ pick
 * one instead; once a command is recalled from the history, they keep stepping through it.
 */
export default function CommandBar({ command }: { command: ServerConsoleCommand }) {
    const listId = useId();
    const [active, setActive] = useState(0);
    const [dismissed, setDismissed] = useState(false);
    const recalled = useRef(false);
    const { suggestions } = command;
    const open = !dismissed && !command.disabled && suggestions.length > 0;
    const selected = Math.min(active, suggestions.length - 1);

    const change = (value: string) => {
        recalled.current = false;
        setDismissed(false);
        setActive(0);
        command.setValue(value);
    };

    const accept = (suggestion: string) => change(`${suggestion} `);

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            recalled.current = false;
            setActive(0);
            command.submit();

            return;
        }

        if (event.key === 'Tab' && open && !event.shiftKey) {
            // Only while there is something to complete, so Tab still moves focus otherwise.
            event.preventDefault();
            accept(suggestions[selected]);

            return;
        }

        if (event.key === 'Escape' && open) {
            event.preventDefault();
            setDismissed(true);

            return;
        }

        if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
            return;
        }

        // Keeps the cursor at the end of the recalled command.
        event.preventDefault();

        if (open && !recalled.current) {
            const by = event.key === 'ArrowUp' ? -1 : 1;

            setActive((selected + by + suggestions.length) % suggestions.length);

            return;
        }

        recalled.current = true;
        setDismissed(true);
        if (event.key === 'ArrowUp') {
            command.previous();
        } else {
            command.next();
        }
    };

    return (
        <div className='relative border-t border-border bg-background/40 transition-colors focus-within:border-accent'>
            {open && (
                <div className='absolute right-2 bottom-full left-2 z-20 mb-2 overflow-hidden rounded-lg border border-border bg-popover shadow-xl animate-fade-in sm:right-auto sm:min-w-64'>
                    <ul
                        id={listId}
                        role='listbox'
                        aria-label='Command suggestions'
                        className='max-h-56 overflow-y-auto py-1'
                    >
                        {suggestions.map((suggestion, index) => (
                            <li
                                key={suggestion}
                                id={`${listId}-${index}`}
                                role='option'
                                aria-selected={index === selected}
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => accept(suggestion)}
                                className={cn(
                                    'cursor-pointer px-3 py-1.5 font-mono text-xs text-popover-foreground',
                                    index === selected ? 'bg-primary/20 text-accent' : 'hover:bg-secondary'
                                )}
                            >
                                {suggestion}
                            </li>
                        ))}
                    </ul>
                    <p className='border-t border-border px-3 py-1 text-xs text-muted-foreground'>
                        <kbd className='font-sans'>Tab</kbd> to complete
                    </p>
                </div>
            )}
            <div className='flex items-center'>
                <ChevronsRight aria-hidden='true' className='pointer-events-none absolute left-3 size-4 text-accent' />
                <input
                    type='text'
                    role='combobox'
                    aria-label='Console command input.'
                    aria-autocomplete='list'
                    aria-expanded={open}
                    aria-controls={open ? listId : undefined}
                    aria-activedescendant={open ? `${listId}-${selected}` : undefined}
                    placeholder={command.disabled ? 'Waiting for the connection…' : 'Type a command…'}
                    disabled={command.disabled}
                    value={command.value}
                    onChange={(event) => change(event.currentTarget.value)}
                    onKeyDown={onKeyDown}
                    onBlur={() => setDismissed(true)}
                    onFocus={() => setDismissed(false)}
                    autoComplete='off'
                    autoCorrect='off'
                    autoCapitalize='none'
                    spellCheck={false}
                    className={cn(
                        'h-11 w-full min-w-0 bg-transparent pr-12 pl-10 font-mono text-sm text-foreground',
                        'placeholder:text-muted-foreground focus:outline-hidden disabled:cursor-not-allowed disabled:opacity-60'
                    )}
                />
                <button
                    type='button'
                    aria-label='Send command'
                    disabled={command.disabled || command.value.trim() === ''}
                    onClick={() => {
                        recalled.current = false;
                        command.submit();
                    }}
                    className={cn(
                        'absolute right-2 inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground transition-colors',
                        'hover:bg-primary hover:text-primary-foreground focus-visible:outline-2 focus-visible:outline-ring',
                        'disabled:pointer-events-none disabled:opacity-40'
                    )}
                >
                    <CornerDownLeft className='size-4' />
                </button>
            </div>
        </div>
    );
}
