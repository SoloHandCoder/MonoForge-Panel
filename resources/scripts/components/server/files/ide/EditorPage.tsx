import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useSearch } from '@tanstack/react-router';
import { dirname } from 'pathe';
import {
    ArrowLeft,
    CircleAlert,
    FileSearch,
    Lock,
    PanelLeftClose,
    PanelLeftOpen,
    Save,
    SquareTerminal,
} from 'lucide-react';
import { useCurrentServer } from '@/api/server/queries';
import { Dialog } from '@/components/elements/dialog';
import Spinner from '@/components/elements/Spinner';
import { fileListDestination } from '@/components/server/files/breadcrumbs';
import { bytesToString } from '@/lib/formatters';
import { cn } from '@/lib/cn';
import { usePageScrollLock } from '@/lib/scrollLock';
import { UNSAVED_CHANGES_MESSAGE, useNavigationBlocker } from '@/plugins/useNavigationBlocker';
import { usePermissions } from '@/plugins/usePermissions';
import { parseEditorSearch, type EditorSearch } from '@/router/search';
import { PanelLink } from '@/sdk/navigation';
import { setPageTitle } from '@/views/pageTitle';
import EditorTabsBar from './EditorTabsBar';
import FileTree from './FileTree';
import QuickOpen from './QuickOpen';
import { useEditorTabs, type EditorTab, type EditorTabs } from './useEditorTabs';

// Monaco and its workers load with the editor, never with the rest of the panel.
const MonacoCanvas = lazy(() => import('./monaco/MonacoCanvas'));

/** Every directory above a path, outermost first, without the root. */
const ancestors = (path: string): string[] => {
    const segments = path.split('/').filter(Boolean).slice(0, -1);

    return segments.map((_, index) => `/${segments.slice(0, index + 1).join('/')}`);
};

/** The folders to expand when the editor opens: those above the file, and the folder asked for. */
const initiallyExpanded = ({ path, dir }: EditorSearch): ReadonlySet<string> =>
    new Set([...ancestors(path ?? ''), ...ancestors(dir ? `${dir}/-` : '')]);

const iconButton = cn(
    'inline-flex h-8 items-center gap-1.5 rounded-sm px-2 text-sm text-muted-foreground',
    'transition-colors duration-150 motion-reduce:transition-none hover:bg-(--hover-row) hover:text-foreground',
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-40'
);

const narrowScreen = () => window.matchMedia?.('(max-width: 47.99rem)').matches ?? false;

function Shortcut({ children }: { children: string }) {
    return <kbd className='rounded-xs border border-border px-1 font-mono'>{children}</kbd>;
}

function EmptyState() {
    const mod = /mac|iphone|ipad/i.test(navigator.platform || navigator.userAgent) ? '⌘' : 'Ctrl';

    return (
        <div className='grid h-full place-items-center p-6 text-center'>
            <div className='max-w-sm space-y-3'>
                <SquareTerminal aria-hidden className='mx-auto size-10 text-accent/70' />
                <p className='text-sm text-foreground'>Open a file from the tree to start editing.</p>
                <p className='text-xs text-muted-foreground'>
                    <Shortcut>{`${mod}+P`}</Shortcut> opens a file by name, <Shortcut>{`${mod}+S`}</Shortcut> saves and{' '}
                    <Shortcut>{`${mod}+F`}</Shortcut> finds and replaces.
                </p>
            </div>
        </div>
    );
}

function TabProblem({ tab }: { tab: EditorTab }) {
    return (
        <div className='grid h-full place-items-center bg-card p-6'>
            <div
                role='alert'
                className='flex max-w-md items-start gap-3 rounded-md border border-border bg-popover p-4'
            >
                <CircleAlert aria-hidden className='mt-0.5 size-5 shrink-0 text-destructive' />
                <div className='min-w-0 space-y-1'>
                    <p className='truncate text-sm font-medium text-foreground'>{tab.name}</p>
                    <p className='text-sm text-muted-foreground'>{tab.error}</p>
                </div>
            </div>
        </div>
    );
}

interface HeaderProps {
    readonly serverName: string;
    readonly back: ReturnType<typeof fileListDestination>;
    readonly sidebar: boolean;
    readonly onToggleSidebar: () => void;
    readonly onQuickOpen: () => void;
    /** Null when the user may not save. */
    readonly onSave: (() => void) | null;
    readonly activeTab: EditorTab | null;
}

function EditorHeader({ serverName, back, sidebar, onToggleSidebar, onQuickOpen, onSave, activeTab }: HeaderProps) {
    const SidebarIcon = sidebar ? PanelLeftClose : PanelLeftOpen;

    return (
        <header className='flex h-11 shrink-0 items-center gap-1 border-b border-border bg-sidebar px-2'>
            <PanelLink destination={back} className={iconButton} aria-label='Back to the file manager'>
                <ArrowLeft aria-hidden className='size-4' />
                <span className='hidden sm:inline'>Files</span>
            </PanelLink>
            <button
                type='button'
                className={iconButton}
                aria-label={sidebar ? 'Hide the file tree' : 'Show the file tree'}
                aria-expanded={sidebar}
                aria-controls='editor-file-tree'
                onClick={onToggleSidebar}
            >
                <SidebarIcon aria-hidden className='size-4' />
            </button>
            <h1 className='ms-1 min-w-0 truncate text-sm'>
                <span className='font-medium text-foreground'>{serverName}</span>
                <span className='text-muted-foreground'> · Editor</span>
            </h1>
            <div className='ms-auto flex items-center gap-1'>
                <button type='button' className={iconButton} onClick={onQuickOpen}>
                    <FileSearch aria-hidden className='size-4' />
                    <span className='hidden sm:inline'>Quick open</span>
                </button>
                {onSave && (
                    <button
                        type='button'
                        className={cn(iconButton, activeTab?.dirty && 'text-accent')}
                        disabled={!activeTab?.dirty || activeTab.saving}
                        onClick={onSave}
                    >
                        {activeTab?.saving ? <Spinner size='small' /> : <Save aria-hidden className='size-4' />}
                        <span className='hidden sm:inline'>Save</span>
                    </button>
                )}
            </div>
        </header>
    );
}

function StatusBar({
    tab,
    readOnly,
    cursor,
}: {
    tab: EditorTab | null;
    readOnly: boolean;
    cursor: { line: number; column: number };
}) {
    return (
        <footer
            aria-label='Status bar'
            className='flex h-6 shrink-0 items-center gap-3 overflow-hidden border-t border-border bg-sidebar px-3 text-xs text-muted-foreground'
        >
            {readOnly && (
                <span className='flex items-center gap-1 text-warning'>
                    <Lock aria-hidden className='size-3' />
                    Read-only
                </span>
            )}
            {tab?.saving && <span>Saving…</span>}
            {tab?.status === 'ready' && (
                <span className='ms-auto flex items-center gap-3 whitespace-nowrap'>
                    <span>{`Ln ${cursor.line}, Col ${cursor.column}`}</span>
                    <span>UTF-8</span>
                    <span>{bytesToString(tab.size)}</span>
                    <span className='text-foreground'>{tab.language.label}</span>
                </span>
            )}
        </footer>
    );
}

/**
 * Monaco stays mounted while any file is loaded, so switching to a tab that is loading or failed
 * keeps the models, their undo history and their unsaved text; such a tab covers it instead.
 */
function EditorSurface({
    editor,
    readOnly,
    onCursor,
}: {
    editor: EditorTabs;
    readOnly: boolean;
    onCursor: (line: number, column: number) => void;
}) {
    const { tabs, activeTab } = editor;
    const [shownPath, setShownPath] = useState<string | null>(null);
    const openPaths = useMemo(() => tabs.map((tab) => tab.path), [tabs]);

    useEffect(() => {
        if (activeTab?.status === 'ready') {
            setShownPath(activeTab.path);
        } else if (shownPath && !tabs.some((tab) => tab.path === shownPath)) {
            setShownPath(tabs.find((tab) => tab.status === 'ready')?.path ?? null);
        }
    }, [activeTab, tabs, shownPath]);

    const shown = tabs.find((tab) => tab.path === shownPath && tab.status === 'ready');

    return (
        <div role='tabpanel' aria-label={activeTab?.name ?? 'Editor'} className='relative min-h-0 flex-1'>
            {shown && (
                <Suspense fallback={<Spinner size='large' centered />}>
                    <MonacoCanvas
                        path={shown.path}
                        content={shown.content}
                        language={shown.language.id}
                        readOnly={readOnly}
                        openPaths={openPaths}
                        onChange={editor.change}
                        onCursor={onCursor}
                    />
                </Suspense>
            )}
            {activeTab?.status !== 'ready' && (
                <div className='absolute inset-0 bg-card'>
                    {!activeTab && <EmptyState />}
                    {activeTab?.status === 'loading' && <Spinner size='large' centered />}
                    {activeTab?.status === 'error' && <TabProblem tab={activeTab} />}
                </div>
            )}
        </div>
    );
}

/** Ctrl/Cmd+S saves the shown file and Ctrl/Cmd+P opens quick open, wherever the focus is. */
function useEditorShortcuts(editor: EditorTabs, openQuickOpen: () => void) {
    const latest = useRef({ editor, openQuickOpen });

    useEffect(() => {
        latest.current = { editor, openQuickOpen };
    });

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            const key = event.key.toLowerCase();

            if (!(event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey || !['s', 'p'].includes(key)) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            const { editor, openQuickOpen } = latest.current;

            if (key === 'p') {
                openQuickOpen();
            } else if (editor.activeTab?.status === 'ready') {
                void editor.save(editor.activeTab.path);
            }
        };

        // Capturing, so the browser's own save and print dialogs never open.
        window.addEventListener('keydown', onKeyDown, true);

        return () => window.removeEventListener('keydown', onKeyDown, true);
    }, []);
}

/** Opens the file in the address once, then keeps the address on the active file so a reload reopens it. */
function useEditorAddress(editor: EditorTabs, search: EditorSearch) {
    const navigate = useNavigate();
    const initial = useRef(search.path);
    const { open, activePath, tabs } = editor;

    useEffect(() => {
        if (initial.current) {
            void open(initial.current);
        }
    }, [open]);

    useEffect(() => {
        const path = activePath ?? undefined;

        if (path !== search.path && (path !== undefined || tabs.length === 0)) {
            void navigate({ to: '.', search: (previous: EditorSearch) => ({ ...previous, path }), replace: true });
        }
    }, [activePath, search.path, tabs.length, navigate]);
}

/** The window title names the open file, and the page below the editor does not scroll. */
function useEditorWindow(tab: EditorTab | null, serverName: string) {
    useEffect(() => {
        setPageTitle(`${tab ? `${tab.name} | ` : ''}${serverName} | Editor`);
    }, [tab, serverName]);

    usePageScrollLock();
}

interface PendingClose {
    readonly names: readonly string[];
    readonly run: () => void;
}

/** The full-screen editor at /server/$id/editor: a file tree, tabs, Monaco and a status bar. */
export default function EditorPage() {
    const server = useCurrentServer()!;
    const { identifier: id, uuid, name: serverName } = server.attributes;
    const [canList, canReadContent, canUpdate] = usePermissions(['file.read', 'file.read-content', 'file.update']);
    const readOnly = !canUpdate;
    const search = parseEditorSearch(useSearch({ strict: false }));
    const editor = useEditorTabs({ uuid, canList, canReadContent, readOnly });
    const { tabs, activePath, activeTab, open, save, close, closeOthers } = editor;

    const [expanded, setExpanded] = useState(() => initiallyExpanded(search));
    const [sidebar, setSidebar] = useState(true);
    const [quickOpen, setQuickOpen] = useState(false);
    const [cursor, setCursor] = useState({ line: 1, column: 1 });
    const [pendingClose, setPendingClose] = useState<PendingClose | null>(null);

    useEditorAddress(editor, search);
    useEditorWindow(activeTab, serverName);
    useEditorShortcuts(editor, () => setQuickOpen(true));
    useNavigationBlocker(editor.hasUnsavedChanges, {
        // Switching files only changes the search; anything else leaves the editor.
        confirm: ({ current, next }) => current.pathname === next.pathname || window.confirm(UNSAVED_CHANGES_MESSAGE),
    });

    const toggle = useCallback((directory: string) => {
        setExpanded((current) => {
            const next = new Set(current);

            if (!next.delete(directory)) {
                next.add(directory);
            }

            return next;
        });
    }, []);

    const reveal = useCallback(
        (path: string) => {
            setExpanded((current) => new Set([...current, ...ancestors(path)]));
            void open(path);
        },
        [open]
    );

    const requestClose = (paths: readonly string[], run: () => void) => {
        const names = tabs.filter((tab) => tab.dirty && paths.includes(tab.path)).map((tab) => tab.name);

        if (names.length === 0) {
            run();
        } else {
            setPendingClose({ names, run });
        }
    };

    const dirtyPaths = useMemo(() => new Set(tabs.filter((tab) => tab.dirty).map((tab) => tab.path)), [tabs]);

    return (
        <div className='fixed inset-0 z-40 flex flex-col bg-background text-foreground'>
            <EditorHeader
                serverName={serverName}
                back={fileListDestination(id, activePath ? dirname(activePath) : (search.dir ?? '/'))}
                sidebar={sidebar}
                onToggleSidebar={() => setSidebar((shown) => !shown)}
                onQuickOpen={() => setQuickOpen(true)}
                onSave={readOnly ? null : () => activePath && void save(activePath)}
                activeTab={activeTab}
            />
            <div className='relative flex min-h-0 flex-1'>
                {sidebar && (
                    <nav
                        id='editor-file-tree'
                        aria-label='File tree'
                        className={cn(
                            'flex w-64 shrink-0 flex-col border-e border-border bg-sidebar',
                            'max-md:absolute max-md:inset-y-0 max-md:start-0 max-md:z-20 max-md:w-[min(18rem,85vw)] max-md:shadow-2xl'
                        )}
                    >
                        <p className='px-3 pt-2 pb-1 text-xs font-semibold tracking-wider text-muted-foreground uppercase'>
                            Explorer
                        </p>
                        <div className='min-h-0 flex-1 overflow-y-auto px-1 pb-2'>
                            {canList ? (
                                <FileTree
                                    uuid={uuid}
                                    expanded={expanded}
                                    activePath={activePath}
                                    dirtyPaths={dirtyPaths}
                                    onToggle={toggle}
                                    onOpen={(path, file) => {
                                        void open(path, file);
                                        if (narrowScreen()) {
                                            setSidebar(false);
                                        }
                                    }}
                                />
                            ) : (
                                <p className='px-2 py-1 text-xs text-muted-foreground'>
                                    You cannot browse the folders of this server.
                                </p>
                            )}
                        </div>
                    </nav>
                )}
                <main className='flex min-w-0 flex-1 flex-col bg-card'>
                    <EditorTabsBar
                        tabs={tabs}
                        activePath={activePath}
                        readOnly={readOnly}
                        onActivate={editor.activate}
                        onClose={(path) => requestClose([path], () => close(path))}
                        onCloseOthers={(path) =>
                            requestClose(
                                tabs.map((tab) => tab.path).filter((other) => other !== path),
                                () => closeOthers(path)
                            )
                        }
                    />
                    <EditorSurface
                        editor={editor}
                        readOnly={readOnly}
                        onCursor={(line, column) => setCursor({ line, column })}
                    />
                    <StatusBar tab={activeTab} readOnly={readOnly} cursor={cursor} />
                </main>
            </div>
            <QuickOpen
                open={quickOpen}
                onClose={() => setQuickOpen(false)}
                uuid={uuid}
                canSearch={canList}
                onPick={reveal}
            />
            <Dialog.Confirm
                open={pendingClose !== null}
                title='Discard unsaved changes?'
                confirm='Discard'
                onClose={() => setPendingClose(null)}
                onConfirmed={() => {
                    pendingClose?.run();
                    setPendingClose(null);
                }}
            >
                {`Your changes to ${pendingClose?.names.join(', ') ?? ''} will be lost.`}
            </Dialog.Confirm>
        </div>
    );
}
