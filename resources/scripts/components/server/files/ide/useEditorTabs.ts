import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { basename, dirname } from 'pathe';
import { httpErrorToHuman } from '@/api/http';
import {
    isFileObjectEditable,
    serverFileContentQueryOptions,
    serverFilesQueryOptions,
    useSaveFileContent,
    writeFileContentsInput,
    type FileObject,
} from '@/api/server/files/queries';
import { bytesToString } from '@/lib/formatters';
import { useDebouncedCallback } from '@/plugins/useDebouncedCallback';
import { detectLanguage, type EditorLanguage } from './languages';

/** Files above this size are not opened: the buffer would make the editor sluggish. */
export const LARGE_FILE_BYTES = 5 * 1024 * 1024;

export interface EditorTab {
    readonly path: string;
    readonly name: string;
    readonly language: EditorLanguage;
    readonly status: 'loading' | 'ready' | 'error';
    /** Why the file could not be opened; set when the status is "error". */
    readonly error: string | null;
    /** The text the editor's model starts from: the file as loaded. */
    readonly content: string;
    readonly dirty: boolean;
    readonly saving: boolean;
    /** Bytes of the buffer, updated shortly after typing stops. */
    readonly size: number;
}

export interface EditorTabsOptions {
    readonly uuid: string;
    /** May list directories, which tells the size and type of a file before it is fetched. */
    readonly canList: boolean;
    readonly canReadContent: boolean;
    /** May not write files: every tab is read-only and never dirty. */
    readonly readOnly: boolean;
}

export interface EditorTabs {
    readonly tabs: readonly EditorTab[];
    readonly activePath: string | null;
    readonly activeTab: EditorTab | null;
    readonly activate: (path: string) => void;
    /** Opens a file in a tab, or shows its tab if it is open; `file` is its listing entry when known. */
    readonly open: (path: string, file?: FileObject) => Promise<void>;
    /** Closes without asking; ask first when the tab is dirty. */
    readonly close: (path: string) => void;
    readonly closeOthers: (path: string) => void;
    /** Reports the full buffer of a file after an edit. */
    readonly change: (path: string, value: string) => void;
    /** Writes the buffer of exactly this file, whichever tab is shown. Resolves whether it was saved. */
    readonly save: (path: string) => Promise<boolean>;
    readonly hasUnsavedChanges: () => boolean;
}

const byteLength = (value: string) => new TextEncoder().encode(value).length;

const loadingTab = (path: string): EditorTab => ({
    path,
    name: basename(path),
    language: detectLanguage(path),
    status: 'loading',
    error: null,
    content: '',
    dirty: false,
    saving: false,
    size: 0,
});

/** Why a listed entry cannot be opened, or null when it can. */
function refusal(file: FileObject): string | null {
    if (!file.attributes.is_file) {
        return 'This is a folder, not a file.';
    }

    if (!isFileObjectEditable(file)) {
        return 'This file is not text, so the editor cannot open it. Download it from the file manager instead.';
    }

    const size = file.attributes.size ?? 0;

    return size > LARGE_FILE_BYTES
        ? `This file is ${bytesToString(size)}; the editor opens files up to ${bytesToString(LARGE_FILE_BYTES)}. Download it from the file manager instead.`
        : null;
}

/**
 * The editor's open files. Buffers live in refs, so typing re-renders nothing until a tab's dirty
 * flag flips; a save writes the buffer of the path it was given, read when the save starts.
 */
export function useEditorTabs({ uuid, canList, canReadContent, readOnly }: EditorTabsOptions): EditorTabs {
    const queryClient = useQueryClient();
    const { mutateAsync: writeFile } = useSaveFileContent();
    const [tabs, setTabs] = useState<readonly EditorTab[]>([]);
    const [activePath, setActivePath] = useState<string | null>(null);
    const latestTabs = useRef(tabs);
    const opened = useRef(new Set<string>());
    const buffers = useRef(new Map<string, string>());
    const saved = useRef(new Map<string, string>());
    const dirty = useRef(new Set<string>());
    const saving = useRef(new Set<string>());
    const resized = useRef(new Set<string>());

    useEffect(() => {
        latestTabs.current = tabs;
    });

    const update = useCallback((path: string, patch: Partial<EditorTab>) => {
        setTabs((current) => current.map((tab) => (tab.path === path ? { ...tab, ...patch } : tab)));
    }, []);

    const markDirty = useCallback(
        (path: string, value: boolean) => {
            if (dirty.current.has(path) !== value) {
                if (value) {
                    dirty.current.add(path);
                } else {
                    dirty.current.delete(path);
                }

                update(path, { dirty: value });
            }
        },
        [update]
    );

    const flushSizes = useDebouncedCallback(() => {
        const paths = new Set(resized.current);

        resized.current.clear();
        setTabs((current) =>
            current.map((tab) => {
                const buffer = paths.has(tab.path) ? buffers.current.get(tab.path) : undefined;

                return buffer === undefined ? tab : { ...tab, size: byteLength(buffer) };
            })
        );
    }, 300);

    const open = useCallback(
        async (path: string, file?: FileObject) => {
            setActivePath(path);
            if (opened.current.has(path)) {
                return;
            }

            opened.current.add(path);
            setTabs((current) => [...current, loadingTab(path)]);

            const fail = (message: string) => update(path, { status: 'error', error: message });

            if (!canReadContent) {
                fail('You do not have permission to open files on this server.');

                return;
            }

            try {
                let entry = file;

                if (!entry && canList) {
                    const listing = await queryClient.fetchQuery(serverFilesQueryOptions(uuid, dirname(path)));

                    entry = listing.data.find((item) => item.attributes.name === basename(path));
                    if (!entry) {
                        fail('This file does not exist.');

                        return;
                    }
                }

                const refused = entry ? refusal(entry) : null;

                if (refused) {
                    fail(refused);

                    return;
                }

                const content = await queryClient.fetchQuery(serverFileContentQueryOptions(uuid, path));

                if (!opened.current.has(path) || saved.current.has(path)) {
                    return;
                }

                saved.current.set(path, content);
                buffers.current.set(path, content);
                update(path, { status: 'ready', content, size: byteLength(content) });
            } catch (error) {
                fail(httpErrorToHuman(error));
            }
        },
        [queryClient, uuid, canList, canReadContent, update]
    );

    const forget = useCallback((path: string) => {
        opened.current.delete(path);
        buffers.current.delete(path);
        saved.current.delete(path);
        dirty.current.delete(path);
        resized.current.delete(path);
    }, []);

    const close = useCallback(
        (path: string) => {
            const current = latestTabs.current;
            const index = current.findIndex((tab) => tab.path === path);
            const remaining = current.filter((tab) => tab.path !== path);

            forget(path);
            setTabs((tabs) => tabs.filter((tab) => tab.path !== path));
            setActivePath((active) =>
                active === path ? (remaining[Math.min(index, remaining.length - 1)]?.path ?? null) : active
            );
        },
        [forget]
    );

    const closeOthers = useCallback(
        (path: string) => {
            for (const tab of latestTabs.current) {
                if (tab.path !== path) {
                    forget(tab.path);
                }
            }

            setTabs((tabs) => tabs.filter((tab) => tab.path === path));
            setActivePath(path);
        },
        [forget]
    );

    const change = useCallback(
        (path: string, value: string) => {
            const base = saved.current.get(path);

            if (base === undefined) {
                return;
            }

            buffers.current.set(path, value);
            resized.current.add(path);
            flushSizes();
            markDirty(path, !readOnly && value !== base);
        },
        [readOnly, markDirty, flushSizes]
    );

    const save = useCallback(
        async (path: string): Promise<boolean> => {
            const base = saved.current.get(path);

            if (readOnly || base === undefined || saving.current.has(path)) {
                return false;
            }

            // Read now: the user may switch tabs or keep typing while the write is in flight.
            const value = buffers.current.get(path) ?? base;

            saving.current.add(path);
            update(path, { saving: true });
            try {
                await writeFile(writeFileContentsInput(uuid, path, value));
            } catch {
                // The mutation shows the error.
                return false;
            } finally {
                saving.current.delete(path);
                update(path, { saving: false });
            }

            if (!opened.current.has(path)) {
                return true;
            }

            saved.current.set(path, value);
            markDirty(path, (buffers.current.get(path) ?? value) !== value);

            return true;
        },
        [readOnly, uuid, writeFile, update, markDirty]
    );

    const hasUnsavedChanges = useCallback(() => dirty.current.size > 0, []);
    const activeTab = useMemo(() => tabs.find((tab) => tab.path === activePath) ?? null, [tabs, activePath]);

    return {
        tabs,
        activePath,
        activeTab,
        activate: setActivePath,
        open,
        close,
        closeOthers,
        change,
        save,
        hasUnsavedChanges,
    };
}
