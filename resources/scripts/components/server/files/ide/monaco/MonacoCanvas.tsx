import { useEffect, useRef } from 'react';
import Editor, { loader, type OnMount } from '@monaco-editor/react';
import Spinner from '@/components/elements/Spinner';
import { monaco } from './setup';
import { defineMonoForgeTheme, MONOFORGE_THEME } from './theme';
import { modelPath } from './modelUri';

const modelKey = (path: string) => monaco.Uri.parse(modelPath(path)).toString();

// The bundled build, never the loader's default CDN copy.
loader.config({ monaco });

export interface MonacoCanvasProps {
    /** The open file the editor shows. */
    readonly path: string;
    /** The text a file's model starts from the first time the editor shows it. */
    readonly content: string;
    readonly language: string;
    readonly readOnly: boolean;
    /** Every open tab; the models of other files are disposed. */
    readonly openPaths: readonly string[];
    /** Reports the full text of the file that changed, which is not always the one shown. */
    readonly onChange: (path: string, value: string) => void;
    readonly onCursor: (line: number, column: number) => void;
}

/** One model per file, at a URI that keeps any character of its path. */

const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

/** Monaco bound to the editor's tabs: the model of each open file keeps its own undo history and view state. */
export default function MonacoCanvas(props: MonacoCanvasProps) {
    const { path, content, language, readOnly, openPaths } = props;
    const latest = useRef(props);
    const files = useRef(new Map<string, string>());

    useEffect(() => {
        latest.current = props;
    });

    files.current.set(modelKey(path), path);

    useEffect(() => {
        const open = new Set(openPaths.map(modelKey));

        for (const model of monaco.editor.getModels()) {
            const key = model.uri.toString();

            if (files.current.has(key) && !open.has(key)) {
                files.current.delete(key);
                model.dispose();
            }
        }
    }, [openPaths]);

    useEffect(
        () => () => {
            for (const model of monaco.editor.getModels()) {
                if (files.current.has(model.uri.toString())) {
                    model.dispose();
                }
            }

            files.current.clear();
        },
        []
    );

    const mount: OnMount = (editor) => {
        const reportCursor = () => {
            const position = editor.getPosition();

            if (position) {
                latest.current.onCursor(position.lineNumber, position.column);
            }
        };

        editor.onDidChangeModelContent(() => {
            const model = editor.getModel();
            const file = model ? files.current.get(model.uri.toString()) : undefined;

            if (model && file !== undefined) {
                latest.current.onChange(file, model.getValue());
            }
        });
        editor.onDidChangeCursorPosition(reportCursor);
        editor.onDidChangeModel(reportCursor);
        reportCursor();
        editor.focus();
    };

    const fontFamily = getComputedStyle(document.documentElement).getPropertyValue('--font-mono').trim();
    const calm = reducedMotion();

    return (
        <Editor
            className='h-full'
            path={modelPath(path)}
            defaultValue={content}
            language={language}
            theme={MONOFORGE_THEME}
            keepCurrentModel
            beforeMount={defineMonoForgeTheme}
            onMount={mount}
            loading={<Spinner size='large' centered />}
            options={{
                readOnly,
                readOnlyMessage: { value: 'You can only read files on this server.' },
                automaticLayout: true,
                fixedOverflowWidgets: true,
                fontFamily: fontFamily || undefined,
                fontSize: 13,
                fontLigatures: true,
                minimap: { enabled: true, renderCharacters: false },
                padding: { top: 8 },
                renderWhitespace: 'selection',
                scrollBeyondLastLine: false,
                smoothScrolling: !calm,
                cursorBlinking: calm ? 'solid' : 'smooth',
                cursorSmoothCaretAnimation: calm ? 'off' : 'on',
                stickyScroll: { enabled: true },
                unicodeHighlight: { ambiguousCharacters: false },
            }}
        />
    );
}
