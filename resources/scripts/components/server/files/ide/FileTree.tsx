import { createContext, use, useMemo, type CSSProperties } from 'react';
import { ChevronRight, File, FileCode, Folder, FolderOpen } from 'lucide-react';
import { fileObjectKind, isFileObjectEditable, useServerFiles, type FileObject } from '@/api/server/files/queries';
import { httpErrorToHuman } from '@/api/http';
import { cn } from '@/lib/cn';

/** A folder lists at most this many entries in the tree. */
export const TREE_ENTRY_LIMIT = 500;

interface FileTreeContext {
    readonly uuid: string;
    readonly expanded: ReadonlySet<string>;
    readonly activePath: string | null;
    readonly dirtyPaths: ReadonlySet<string>;
    readonly onToggle: (directory: string) => void;
    readonly onOpen: (path: string, file: FileObject) => void;
}

const TreeContext = createContext<FileTreeContext | null>(null);

function useTree(): FileTreeContext {
    const tree = use(TreeContext);

    if (!tree) {
        throw new Error('Tree rows render inside the file tree.');
    }

    return tree;
}

const childPath = (directory: string, name: string) => `${directory === '/' ? '' : directory}/${name}`;
const indent = (depth: number): CSSProperties => ({ paddingInlineStart: `${depth * 0.75 + 0.5}rem` });

const rowClass = cn(
    'flex w-full min-w-0 items-center gap-1.5 rounded-sm py-1 pe-2 text-left text-sm leading-5',
    'text-muted-foreground transition-colors duration-150 motion-reduce:transition-none',
    'hover:bg-(--hover-row) hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'
);

const sortEntries = (files: readonly FileObject[]) =>
    [...files].sort(
        (a, b) =>
            Number(a.attributes.is_file) - Number(b.attributes.is_file) ||
            a.attributes.name.localeCompare(b.attributes.name)
    );

function Notice({ depth, children, tone = 'muted' }: { depth: number; children: string; tone?: 'muted' | 'error' }) {
    return (
        <li
            role='none'
            style={indent(depth)}
            className={cn('py-1 pe-2 text-xs', tone === 'error' ? 'text-destructive' : 'text-muted-foreground')}
        >
            {children}
        </li>
    );
}

function FileRow({ path, file, depth }: { path: string; file: FileObject; depth: number }) {
    const { activePath, dirtyPaths, onOpen } = useTree();
    const active = activePath === path;
    const editable = isFileObjectEditable(file);
    const FileIcon = editable ? FileCode : File;
    const name = file.attributes.name;

    return (
        <li role='treeitem' aria-selected={active} aria-label={name}>
            <button
                type='button'
                className={cn(
                    rowClass,
                    active && 'bg-(--active-item) text-(--active-item-foreground)',
                    !editable && 'opacity-60'
                )}
                style={indent(depth)}
                title={editable ? path : `${path} (not a text file)`}
                onClick={() => onOpen(path, file)}
            >
                {/* Lines a file up with a folder's name, past the chevron. */}
                <span aria-hidden className='size-3.5 shrink-0' />
                <FileIcon aria-hidden className='size-4 shrink-0' />
                <span className='truncate'>{name}</span>
                {dirtyPaths.has(path) && (
                    <span aria-label='Unsaved changes' className='ms-auto size-2 shrink-0 rounded-full bg-accent' />
                )}
            </button>
        </li>
    );
}

/** A folder's entries; an expanded folder among them lists its own the same way. */
function DirectoryContents({ path, depth }: { path: string; depth: number }) {
    const { uuid, expanded, onToggle } = useTree();
    const { data, error, isPending } = useServerFiles(uuid, path);
    const entries = useMemo(() => sortEntries(data?.data ?? []), [data]);

    if (isPending) {
        return <Notice depth={depth}>Loading…</Notice>;
    }

    if (error) {
        return (
            <Notice depth={depth} tone='error'>
                {httpErrorToHuman(error)}
            </Notice>
        );
    }

    if (entries.length === 0) {
        return <Notice depth={depth}>This folder is empty.</Notice>;
    }

    return (
        <>
            {entries.slice(0, TREE_ENTRY_LIMIT).map((file) => {
                const child = childPath(path, file.attributes.name);

                if (fileObjectKind(file) !== 'directory') {
                    return <FileRow key={`f:${file.attributes.name}`} path={child} file={file} depth={depth} />;
                }

                const open = expanded.has(child);
                const FolderIcon = open ? FolderOpen : Folder;

                return (
                    <li
                        key={`d:${file.attributes.name}`}
                        role='treeitem'
                        aria-expanded={open}
                        aria-selected={false}
                        aria-label={file.attributes.name}
                    >
                        <button
                            type='button'
                            className={rowClass}
                            style={indent(depth)}
                            onClick={() => onToggle(child)}
                        >
                            <ChevronRight
                                aria-hidden
                                className={cn(
                                    'size-3.5 shrink-0 transition-transform duration-150 motion-reduce:transition-none',
                                    open && 'rotate-90'
                                )}
                            />
                            <FolderIcon aria-hidden className='size-4 shrink-0 text-accent' />
                            <span className='truncate'>{file.attributes.name}</span>
                        </button>
                        {open && (
                            <ul role='group'>
                                <DirectoryContents path={child} depth={depth + 1} />
                            </ul>
                        )}
                    </li>
                );
            })}
            {entries.length > TREE_ENTRY_LIMIT && (
                <Notice depth={depth}>{`${entries.length - TREE_ENTRY_LIMIT} more not shown; use quick open.`}</Notice>
            )}
        </>
    );
}

export interface FileTreeProps extends FileTreeContext {
    readonly className?: string;
}

/** The server's files, each folder listed only once it is expanded. */
export default function FileTree({ className, ...tree }: FileTreeProps) {
    return (
        <TreeContext.Provider value={tree}>
            <ul role='tree' aria-label='Files' className={cn('py-1', className)}>
                <DirectoryContents path='/' depth={0} />
            </ul>
        </TreeContext.Provider>
    );
}
