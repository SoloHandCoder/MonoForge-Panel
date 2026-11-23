import type * as Monaco from 'monaco-editor/editor/editor.api.js';

/**
 * HyperScript (.hs), MonoForge's Minecraft scripting language (https://hyperscript.monoforge.net/Docs/):
 * Skript-like, indentation-based blocks opened by a line ending in ":", `#` line comments,
 * `{global}`, `{_local}` and `{@option}` variables, and `%placeholder%` / `%{var}%` inside text.
 */
export const HYPERSCRIPT_ID = 'hyperscript';

/** Words that start a top-level block or a section inside one. */
export const HYPERSCRIPT_STRUCTURES = [
    'options',
    'function',
    'local',
    'command',
    'trigger',
    'subcommand',
    'on',
    'every',
    'item',
    'recipe',
    'messages',
    'enchantment',
    'advancement',
    'mob',
    'currency',
    'placeholder',
    'test',
    'async',
    'sync',
    'permission',
    'usage',
    'description',
    'aliases',
    'cooldown',
    'warmup',
    'cost',
    'worlds',
    'limit',
    'disabled',
];

/** Control flow, loops, timing and error handling. */
export const HYPERSCRIPT_CONTROL = [
    'if',
    'else',
    'unless',
    'switch',
    'case',
    'default',
    'randomly',
    'chance',
    'loop',
    'for',
    'each',
    'while',
    'times',
    'break',
    'continue',
    'stop',
    'return',
    'fail',
    'wait',
    'until',
    'after',
    'repeat',
    'countdown',
    'once',
    'run',
    'schedule',
    'try',
    'error',
    'expect',
];

/** Effects (statements) a script line usually starts with. */
export const HYPERSCRIPT_EFFECTS = [
    'send',
    'broadcast',
    'log',
    'reply',
    'set',
    'add',
    'subtract',
    'delete',
    'append',
    'remove',
    'give',
    'teleport',
    'heal',
    'feed',
    'kill',
    'apply',
    'freeze',
    'unfreeze',
    'mute',
    'refuse',
    'ignite',
    'push',
    'grant',
    'save',
    'start',
    'end',
    'reset',
    'play',
    'spawn',
    'draw',
    'strike',
    'create',
    'execute',
    'cancel',
    'fire',
    'ask',
    'confirm',
    'open',
    'close',
    'show',
    'hide',
    'clear',
    'parse',
    'read',
];

/** Operators spelled as words. */
export const HYPERSCRIPT_OPERATORS = [
    'is',
    'are',
    'not',
    'and',
    'or',
    'contains',
    'does',
    'has',
    'have',
    'can',
    'in',
    'of',
    'to',
    'from',
    'by',
    'with',
    'within',
    'where',
    'as',
    'at',
    'than',
    'above',
    'below',
    'least',
    'most',
    'between',
    'equal',
    'starts',
    'ends',
    'matches',
    'any',
    'all',
    'set',
];

export const HYPERSCRIPT_TYPES = [
    'number',
    'integer',
    'text',
    'boolean',
    'list',
    'table',
    'duration',
    'location',
    'date',
    'player',
    'offlineplayer',
    'entity',
    'world',
];

export const HYPERSCRIPT_CONSTANTS = ['true', 'false', 'now', 'today', 'tomorrow', 'air', 'op'];

/** Time units, which the docs spell out in script lines (`wait 5 seconds`). */
export const HYPERSCRIPT_UNITS = [
    'tick',
    'ticks',
    'second',
    'seconds',
    'minute',
    'minutes',
    'hour',
    'hours',
    'day',
    'days',
    'week',
    'weeks',
];

/** The events of the Events reference, as written after `on`. */
export const HYPERSCRIPT_EVENTS = [
    'player join',
    'first join',
    'player quit',
    'player login',
    'player death',
    'player respawn',
    'player kill',
    'player chat',
    'player command',
    'player kick',
    'player move',
    'player jump',
    'player sneak',
    'player sprint',
    'player swim',
    'player teleport',
    'player heal',
    'world change',
    'left click',
    'right click',
    'entity click',
    'inventory click',
    'inventory open',
    'inventory close',
    'slot change',
    'hand swap',
    'item pickup',
    'item drop',
    'item break',
    'item damage',
    'craft',
    'consume',
    'enchant item',
    'anvil use',
    'furnace smelt',
    'villager trade',
    'fish',
    'book edit',
    'sign change',
    'entity damage',
    'hit',
    'entity death',
    'combat start',
    'combat end',
    'projectile shoot',
    'projectile hit',
    'totem use',
    'shield block',
    'kill streak',
    'afk',
    'afk return',
    'block break',
    'block place',
    'block burn',
    'explosion',
    'leaves decay',
    'redstone',
    'piston extend',
    'piston retract',
    'dispense',
    'hopper move',
    'crop grow',
    'bucket fill',
    'bucket empty',
    'weather change',
    'brew',
    'harvest',
    'region enter',
    'region leave',
    'mob spawn',
    'mob target',
    'entity tame',
    'entity breed',
    'shear',
    'flight toggle',
    'glide toggle',
    'entity mount',
    'entity dismount',
    'vehicle enter',
    'vehicle exit',
    'portal use',
    'bed enter',
    'bed leave',
    'level up',
    'experience gain',
    'advancement',
    'effect gained',
    'effect ended',
    'gamemode change',
    'hunger change',
    'blocked word',
    'server start',
    'server stop',
    'server ping',
    'console command',
    'resource pack accept',
    'resource pack decline',
    'load',
    'unload',
];

/** Placeholders usable bare in code and as `%name%` in text. */
export const HYPERSCRIPT_PLACEHOLDERS = [
    'player',
    'player.name',
    'player.uuid',
    'player.world',
    'player.location',
    'player.health',
    'player.gamemode',
    'player.ping',
    'arg.1',
    'arg.2',
    'args',
    'args.count',
    'server.tps',
    'server.online_players',
    'server.max_players',
    'server.players',
    'server.version',
    'server.uptime',
    'event.block',
    'event.message',
    'event.item',
    'loop-value',
    'loop-index',
    'loop-player',
    'nl',
];

interface Snippet {
    readonly label: string;
    readonly detail: string;
    readonly insert: string;
}

/** Templates for the common blocks; `${n:...}` are Monaco tab stops. */
export const HYPERSCRIPT_SNIPPETS: readonly Snippet[] = [
    {
        label: 'command',
        detail: 'A command with a trigger',
        insert: 'command /${1:name} ${2:[target:player]}:\n\tpermission: ${3:script.$1}\n\tusage: /$1 ${2}\n\ttrigger:\n\t\t${0:send "&aHello %player.name%!"}',
    },
    {
        label: 'on event',
        detail: 'An event handler',
        insert: 'on ${1:player join}:\n\t${0:send "&aWelcome, %player.name%!"}',
    },
    {
        label: 'function',
        detail: 'A function with arguments',
        insert: 'function ${1:name}({_${2:value}}):\n\t${0:return {_$2}}',
    },
    {
        label: 'every',
        detail: 'A repeating timer',
        insert: 'every ${1:5 minutes}:\n\t${0:broadcast "&7Remember to vote!"}',
    },
    {
        label: 'options',
        detail: 'Options block, used as {@name}',
        insert: 'options:\n\t${1:prefix}: "${2:&8[&bServer&8]&r}"',
    },
    { label: 'if', detail: 'If / else', insert: 'if ${1:condition}:\n\t${2}\nelse:\n\t${0}' },
    {
        label: 'loop players',
        detail: 'Loop all online players',
        insert: 'loop all players:\n\t${0:send "&e%loop-player%" to loop-player}',
    },
    { label: 'for each', detail: 'Loop a list', insert: 'for each {_${1:item}} in {_${2:list}}:\n\t${0}' },
    { label: 'try', detail: 'Try / on error', insert: 'try:\n\t${1}\non error:\n\t${0:log "%{_error}%"}' },
    {
        label: 'gui',
        detail: 'A menu',
        insert: 'open gui with title "${1:Menu}" with ${2:3} rows for player:\n\tslot ${3:13} is "${4:diamond}":\n\t\t${0}',
    },
    { label: 'test', detail: 'A script test', insert: 'test "${1:name}":\n\t${0:expect 1 + 1 is 2}' },
];

/** Registers the HyperScript language, its highlighter, editing rules and completions. */
export function registerHyperScript(monaco: typeof Monaco): void {
    monaco.languages.register({ id: HYPERSCRIPT_ID, extensions: ['.hs'], aliases: ['HyperScript', 'hs'] });

    monaco.languages.setLanguageConfiguration(HYPERSCRIPT_ID, {
        comments: { lineComment: '#' },
        brackets: [
            ['{', '}'],
            ['[', ']'],
            ['(', ')'],
        ],
        autoClosingPairs: [
            { open: '{', close: '}' },
            { open: '[', close: ']' },
            { open: '(', close: ')' },
            { open: '"', close: '"', notIn: ['string'] },
        ],
        surroundingPairs: [
            { open: '{', close: '}' },
            { open: '(', close: ')' },
            { open: '"', close: '"' },
            { open: '%', close: '%' },
        ],
        // A line ending in ":" (before an optional comment) opens a block; dedent closes it.
        indentationRules: {
            increaseIndentPattern: /^\s*[^#\s].*:\s*(#.*)?$/,
            decreaseIndentPattern: /^\s*(else|else if|else unless|on error|case|default)\b.*:\s*$/,
        },
        onEnterRules: [
            {
                beforeText: /^\s*[^#\s].*:\s*(#.*)?$/,
                action: { indentAction: monaco.languages.IndentAction.Indent },
            },
        ],
        folding: { offSide: true },
        wordPattern: /(-?\d*\.\d\w*)|([^\s`~!@$^&*()=+[{\]}\\|;:'",<>/?]+)/,
    });

    monaco.languages.setMonarchTokensProvider(HYPERSCRIPT_ID, {
        ignoreCase: true,
        defaultToken: '',
        structures: HYPERSCRIPT_STRUCTURES,
        control: HYPERSCRIPT_CONTROL,
        effects: HYPERSCRIPT_EFFECTS,
        operators: HYPERSCRIPT_OPERATORS,
        types: HYPERSCRIPT_TYPES,
        constants: HYPERSCRIPT_CONSTANTS,
        units: HYPERSCRIPT_UNITS,
        tokenizer: {
            root: [
                // `# Requires: 1.5.9` style headers read like comments too.
                [/#.*$/, 'comment'],
                // A command header: `command /name <arg:type> [opt:type = 1]:`
                [/(command)(\s+)(\/[\w:-]+)/, ['keyword', '', 'tag']],
                // Events: `on player join:` (the words after `on` up to the colon).
                [/^(\s*)(on)(\s+)(?=[a-z])/, ['', 'keyword', '']],
                [/\{@/, { token: 'variable.option', next: '@variable' }],
                [/\{_/, { token: 'variable.local', next: '@variable' }],
                [/\{/, { token: 'variable', next: '@variable' }],
                [/"/, { token: 'string.quote', next: '@string' }],
                [/<[\w:|= .-]+>/, 'type'],
                [/\b_[a-z][\w.-]*/, 'variable.local'],
                [/\bloop-(value|index|number|player)(-\d+)?\b/, 'variable'],
                [/\d+(\.\d+)?%/, 'number'],
                [/-?\d+(\.\d+)?/, 'number'],
                [
                    /[a-z][\w-]*(?=\s*\()/,
                    { cases: { '@control': 'keyword', '@effects': 'keyword', '@default': 'type.function' } },
                ],
                [
                    /[a-z][\w]*/,
                    {
                        cases: {
                            '@structures': 'keyword',
                            '@control': 'keyword',
                            '@effects': 'keyword',
                            '@constants': 'number',
                            '@types': 'type',
                            '@units': 'number',
                            '@operators': 'keyword.operator',
                            '@default': 'identifier',
                        },
                    },
                ],
                [/[=!<>]=|[+\-*/%<>]/, 'delimiter'],
                [/[()[\],:.]/, 'delimiter'],
                // A trailing backslash continues the statement on the next line.
                [/\\$/, 'delimiter'],
            ],
            // Inside `{...}`: `%player%` and nested `{...}` are allowed (`{%player%.coins}`).
            variable: [
                [/\{/, { token: 'variable', next: '@push' }],
                [/\}/, { token: 'variable', next: '@pop' }],
                [/[^{}]+/, 'variable'],
            ],
            string: [
                [/%\{/, { token: 'variable', next: '@interpolatedVariable' }],
                [/%[^%"\s][^%"]*%/, 'variable'],
                [/\{@[\w.-]+\}/, 'variable.option'],
                [/&[0-9a-fk-or]/i, 'type'],
                [/<#[0-9a-f]{6}>/i, 'type'],
                [/\\./, 'string.escape'],
                [/[^"%{&<\\]+/, 'string'],
                [/"/, { token: 'string.quote', next: '@pop' }],
                [/./, 'string'],
            ],
            // `%{_name}%` or `%{%player%.coins}%` inside text.
            interpolatedVariable: [
                [/\{/, { token: 'variable', next: '@variable' }],
                [/\}%/, { token: 'variable', next: '@pop' }],
                [/[^{}]+/, 'variable'],
            ],
        },
    } as Monaco.languages.IMonarchLanguage);

    monaco.languages.registerCompletionItemProvider(HYPERSCRIPT_ID, {
        triggerCharacters: ['%', ' '],
        provideCompletionItems(model, position) {
            const word = model.getWordUntilPosition(position);
            const range = new monaco.Range(position.lineNumber, word.startColumn, position.lineNumber, word.endColumn);
            const before = model.getValueInRange(
                new monaco.Range(position.lineNumber, 1, position.lineNumber, position.column)
            );
            const { Keyword, Snippet: SnippetKind, Event, Variable } = monaco.languages.CompletionItemKind;
            const asSnippet = monaco.languages.CompletionItemInsertTextRule.InsertAsSnippet;

            // `%` inside text: offer the placeholders.
            if (/%[\w.-]*$/.test(before) && (before.split('"').length - 1) % 2 === 1) {
                return {
                    suggestions: HYPERSCRIPT_PLACEHOLDERS.map((name) => ({
                        label: `%${name}%`,
                        kind: Variable,
                        insertText: `${name}%`,
                        range,
                    })),
                };
            }

            // After `on `: offer the event names, ready with their colon.
            if (/^\s*on\s+[\w ]*$/i.test(before)) {
                const typed = before.replace(/^\s*on\s+/i, '');
                const start = position.column - typed.length;

                return {
                    suggestions: HYPERSCRIPT_EVENTS.map((event) => ({
                        label: event,
                        kind: Event,
                        insertText: `${event}:`,
                        range: new monaco.Range(position.lineNumber, start, position.lineNumber, position.column),
                    })),
                };
            }

            const words = [
                ...new Set([
                    ...HYPERSCRIPT_STRUCTURES,
                    ...HYPERSCRIPT_CONTROL,
                    ...HYPERSCRIPT_EFFECTS,
                    ...HYPERSCRIPT_CONSTANTS,
                ]),
            ];

            return {
                suggestions: [
                    ...HYPERSCRIPT_SNIPPETS.map((snippet) => ({
                        label: snippet.label,
                        kind: SnippetKind,
                        detail: snippet.detail,
                        insertText: snippet.insert,
                        insertTextRules: asSnippet,
                        range,
                    })),
                    ...words.map((keyword) => ({ label: keyword, kind: Keyword, insertText: keyword, range })),
                ],
            };
        },
    });
}
