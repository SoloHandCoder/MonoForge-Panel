/** A Monaco language and the name the status bar shows for it. */
export interface EditorLanguage {
    readonly id: string;
    readonly label: string;
}

const plainText: EditorLanguage = { id: 'plaintext', label: 'Plain Text' };

const byExtension = new Map<string, EditorLanguage>([
    ['yml', { id: 'yaml', label: 'YAML' }],
    ['yaml', { id: 'yaml', label: 'YAML' }],
    ['json', { id: 'json', label: 'JSON' }],
    ['json5', { id: 'json', label: 'JSON' }],
    ['mcmeta', { id: 'json', label: 'JSON' }],
    ['properties', { id: 'ini', label: 'Properties' }],
    ['toml', { id: 'ini', label: 'TOML' }],
    ['cfg', { id: 'ini', label: 'Config' }],
    ['conf', { id: 'ini', label: 'Config' }],
    ['ini', { id: 'ini', label: 'INI' }],
    ['env', { id: 'ini', label: 'Environment' }],
    ['js', { id: 'javascript', label: 'JavaScript' }],
    ['mjs', { id: 'javascript', label: 'JavaScript' }],
    ['cjs', { id: 'javascript', label: 'JavaScript' }],
    ['ts', { id: 'typescript', label: 'TypeScript' }],
    ['lua', { id: 'lua', label: 'Lua' }],
    ['sk', { id: 'skript', label: 'Skript' }],
    // HyperScript, MonoForge's own Minecraft scripting language (not Haskell, on a game panel).
    ['hs', { id: 'hyperscript', label: 'HyperScript' }],
    ['xml', { id: 'xml', label: 'XML' }],
    ['md', { id: 'markdown', label: 'Markdown' }],
    ['markdown', { id: 'markdown', label: 'Markdown' }],
    ['sh', { id: 'shell', label: 'Shell' }],
    ['bash', { id: 'shell', label: 'Shell' }],
    ['dockerfile', { id: 'dockerfile', label: 'Dockerfile' }],
    ['py', { id: 'python', label: 'Python' }],
    ['html', { id: 'html', label: 'HTML' }],
    ['htm', { id: 'html', label: 'HTML' }],
    ['css', { id: 'css', label: 'CSS' }],
]);

/** The language of a file, from its name: `Dockerfile` by name, everything else by extension. */
export function detectLanguage(fileName: string): EditorLanguage {
    const name = fileName.slice(fileName.lastIndexOf('/') + 1).toLowerCase();

    if (name === 'dockerfile' || name.startsWith('dockerfile.')) {
        return byExtension.get('dockerfile') ?? plainText;
    }

    if (name === '.env' || name.startsWith('.env.')) {
        return byExtension.get('env') ?? plainText;
    }

    const dot = name.lastIndexOf('.');

    return (dot > 0 ? byExtension.get(name.slice(dot + 1)) : undefined) ?? plainText;
}
