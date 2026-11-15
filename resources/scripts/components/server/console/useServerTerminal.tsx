import { useEffect, useMemo, useRef, useState, useSyncExternalStore, type ComponentType, type RefObject } from 'react';
import type { Terminal } from 'ghostty-web';
import { useCurrentServerIdentifier } from '@/api/server/queries';
import { SocketRequest } from '@/components/server/events';
import copyToClipboard from '@/lib/clipboard';
import { cn } from '@/lib/cn';
import type { Websocket } from '@/plugins/Websocket';
import { usePermissions } from '@/plugins/usePermissions';
import { usePersistedState } from '@/plugins/usePersistedState';
import { useServerPlayers, useServerStore, useSocketConnected, useSocketInstance } from '@/state/server';
import type { ServerConsoleCommand, ServerTerminalModel, TerminalCanvasProps } from '@/views/server/console';
import { useLoadedServer } from '@/views/server/loadedServer';
import ConsoleScrollbar from './ConsoleScrollbar';
import { consoleGame, GAME_COMMANDS, suggestCommands, trackPlayers, type ConsoleGame } from './consoleGame';
import { ConsoleOutputStore, type ConsoleOutput } from './consoleOutput';
import type { TerminalScrollStore } from './TerminalScrollStore';
import { useConsoleSocketLogs } from './useConsoleSocketLogs';
import { useTerminal } from './useTerminal';

const HISTORY_LENGTH = 32;
const noHistory: string[] = [];
const noCommands: readonly string[] = [];

interface TerminalSession {
    ref: (element: HTMLDivElement | null) => void;
    scroll: TerminalScrollStore;
    terminal: RefObject<Terminal | null>;
}

/** The canvas of one terminal, with the scrollbar drawn in the gutter beside it. */
function createTerminalCanvas({ ref, scroll, terminal }: TerminalSession): ComponentType<TerminalCanvasProps> {
    return function TerminalCanvas({ className }: TerminalCanvasProps) {
        return (
            <div className={cn('relative h-full w-full', className)}>
                <div className='h-full w-full' ref={ref} />
                <ConsoleScrollbar scroll={scroll} terminal={terminal} />
            </div>
        );
    };
}

/** The command line: its value, the history it steps through, completions and sending. */
function useConsoleCommand(
    serverId: string,
    socket: Websocket | null,
    connected: boolean,
    commands: readonly string[]
): ServerConsoleCommand {
    const [allowed] = usePermissions(['control.console']);
    const [stored, setHistory] = usePersistedState<string[]>(`${serverId}:command_history`, []);
    const [value, setValue] = useState('');
    const index = useRef(-1);
    const history = Array.isArray(stored) ? stored : noHistory;

    return useMemo(() => {
        const step = (next: number) => {
            index.current = next;
            setValue(history[next] || '');
        };

        return {
            allowed,
            disabled: !socket || !connected,
            value,
            history,
            suggestions: suggestCommands(value, commands),
            setValue,
            previous: () => step(Math.min(index.current + 1, history.length - 1)),
            next: () => step(Math.max(index.current - 1, -1)),
            submit: () => {
                if (!value.trim().length) {
                    return;
                }

                setHistory((previous) =>
                    [value, ...(Array.isArray(previous) ? previous : []).filter((entry) => entry !== value)].slice(
                        0,
                        HISTORY_LENGTH
                    )
                );
                index.current = -1;
                socket?.send(SocketRequest.SEND_COMMAND, value);
                setValue('');
            },
        };
    }, [allowed, socket, connected, value, history, setHistory, commands]);
}

/** Draws the output store in the Ghostty canvas too, for themes that show the canvas. */
function useMirrorToCanvas(store: ConsoleOutputStore, terminal: RefObject<Terminal | null>, ready: boolean) {
    useEffect(() => {
        const term = terminal.current;

        if (!ready || !term) {
            return;
        }

        term.clear();
        for (const raw of store.rawLines()) {
            term.writeln(raw);
        }

        return store.onChange((change) => {
            if (change.kind === 'clear') {
                term.clear();
            } else {
                term.writeln(change.raw);
            }
        });
    }, [store, terminal, ready]);
}

/** Keeps the player count of the server's store up to date with what the console reports. */
function useTrackPlayers(store: ConsoleOutputStore, game: ConsoleGame | null) {
    const players = useServerPlayers();
    const setPlayers = useServerStore((state) => state.stats.setPlayers);
    const latest = useRef(players);

    useEffect(() => {
        latest.current = players;
    }, [players]);

    useEffect(() => {
        if (!game) {
            return;
        }

        return store.onChange((change) => {
            // History is sent again after a clear, which counts the players again from the start.
            const next = change.kind === 'clear' ? null : trackPlayers(game, latest.current, change.line.text);

            if (next !== latest.current) {
                latest.current = next;
                setPlayers(next);
            }
        });
    }, [store, game, setPlayers]);
}

/** A file name for a download of the console: the server's name and the time, without anything a file system minds. */
export function consoleLogFileName(serverName: string, at: Date): string {
    const name =
        serverName
            .toLowerCase()
            .replaceAll(/[^a-z0-9_-]+/g, '-')
            .replaceAll(/^-+|-+$/g, '')
            .slice(0, 48) || 'server';
    const pad = (value: number) => String(value).padStart(2, '0');
    const stamp = `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}-${pad(at.getHours())}${pad(at.getMinutes())}`;

    return `${name}-console-${stamp}.log`;
}

function downloadText(fileName: string, text: string) {
    const url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
    const anchor = document.createElement('a');

    anchor.href = url;
    anchor.download = fileName;
    anchor.rel = 'noopener';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    setTimeout(() => URL.revokeObjectURL(url), 0);
}

/** The server's console: the output the websocket sends, the terminal canvas drawing it, and the command line. */
export function useServerTerminal(): ServerTerminalModel {
    const server = useLoadedServer();
    const { ref, retry, scroll, terminal, terminalError, terminalReady } = useTerminal();
    // Smooth scrolling can settle a fraction of a line above the bottom, which still counts as at the bottom.
    const scrolledUp = useSyncExternalStore(scroll.subscribe, () => scroll.getSnapshot().viewportY > 0.5);
    const connected = useSocketConnected();
    const instance = useSocketInstance();
    const game = consoleGame(server);
    const command = useConsoleCommand(
        useCurrentServerIdentifier() ?? '',
        instance,
        connected,
        game ? GAME_COMMANDS[game] : noCommands
    );
    const [Terminal] = useState(() => createTerminalCanvas({ ref, scroll, terminal }));
    const [store] = useState(() => new ConsoleOutputStore());
    const sink = useRef(store);
    const serverName = server.attributes.name;

    useConsoleSocketLogs({ connected, instance, terminal: sink, terminalReady: true });
    useMirrorToCanvas(store, terminal, terminalReady);
    useTrackPlayers(store, game);

    const output = useMemo<ConsoleOutput>(
        () => ({
            subscribe: store.subscribe,
            getSnapshot: store.getSnapshot,
            copy: () => copyToClipboard(store.text()),
            download: () => downloadText(consoleLogFileName(serverName, new Date()), store.text()),
        }),
        [store, serverName]
    );

    return useMemo(
        () => ({
            Terminal,
            output,
            connected,
            loading: !terminalError && (!connected || !terminalReady),
            failed: !!terminalError,
            retry,
            scrolledUp,
            scrollToBottom: () => terminal.current?.scrollToBottom(),
            command,
        }),
        [Terminal, output, terminalError, connected, terminalReady, retry, scrolledUp, terminal, command]
    );
}

/** A terminal showing fixed output, for fixtures. */
export function createSampleTerminal(lines: readonly string[]): ComponentType<TerminalCanvasProps> {
    return function SampleTerminal({ className }: TerminalCanvasProps) {
        const { ref, scroll, terminal, terminalReady } = useTerminal();
        const [Canvas] = useState(() => createTerminalCanvas({ ref, scroll, terminal }));

        useEffect(() => {
            if (terminalReady) {
                for (const line of lines) {
                    terminal.current?.writeln(line);
                }
            }
        }, [terminal, terminalReady]);

        return <Canvas className={className} />;
    };
}

/** Fixed output for fixtures, as the console would have parsed it. */
export function createSampleOutput(lines: readonly string[]): ConsoleOutput {
    const store = new ConsoleOutputStore();

    for (const line of lines) {
        store.writeln(line);
    }

    store.flush();

    return {
        subscribe: store.subscribe,
        getSnapshot: store.getSnapshot,
        copy: () => copyToClipboard(store.text()),
        download: () => downloadText(consoleLogFileName('server', new Date()), store.text()),
    };
}
