import type { AssistantProviderPreset } from '@/api/assistant';

/**
 * The admin AI Assistant tab's sub-tabs, in order. Each has a route at /panel/assistant[/<key>] (see
 * routeTree.ts) that renders AssistantAdminContainer, which shows the tab matching the path.
 */
export const assistantTabs = [
    { key: 'chat', label: 'Chat', path: '/panel/assistant' },
    { key: 'providers', label: 'Providers', path: '/panel/assistant/providers' },
    { key: 'settings', label: 'Settings', path: '/panel/assistant/settings' },
    { key: 'usage', label: 'Usage', path: '/panel/assistant/usage' },
] as const;

export type AssistantTab = (typeof assistantTabs)[number]['key'];

/** The sub-tab a /panel/assistant path shows; anything unknown shows Chat. */
export const assistantTabFromPath = (pathname: string): AssistantTab => {
    const segment = pathname.replace(/\/+$/, '').split('/panel/assistant')[1]?.split('/')[1] ?? '';

    return assistantTabs.find((tab) => tab.key === segment)?.key ?? 'chat';
};

export interface ProviderPresetDefaults {
    label: string;
    base_url: string;
    model: string;
    /** Whether the provider needs an API key to answer. */
    needs_key: boolean;
}

/** Prefills for the provider form; the backend applies the preset's protocol quirks itself. */
export const providerPresets = {
    clinepass: {
        label: 'ClinePass',
        base_url: 'https://api.cline.bot/api/v1',
        model: 'cline-pass/deepseek-v4.1-flash',
        needs_key: true,
    },
    cline: {
        label: 'Cline (pay as you go)',
        base_url: 'https://api.cline.bot/api/v1',
        model: 'deepseek/deepseek-chat',
        needs_key: true,
    },
    ollama: { label: 'Ollama', base_url: 'http://127.0.0.1:11434/v1', model: 'llama3.1', needs_key: false },
    openrouter: {
        label: 'OpenRouter',
        base_url: 'https://openrouter.ai/api/v1',
        model: 'deepseek/deepseek-chat',
        needs_key: true,
    },
    deepseek: { label: 'DeepSeek', base_url: 'https://api.deepseek.com', model: 'deepseek-flash', needs_key: true },
    openai: { label: 'OpenAI', base_url: 'https://api.openai.com/v1', model: 'gpt-4o-mini', needs_key: true },
    custom: { label: 'Custom (OpenAI-compatible)', base_url: '', model: '', needs_key: false },
} as const satisfies Record<AssistantProviderPreset, ProviderPresetDefaults>;

export const providerPresetOrder: readonly AssistantProviderPreset[] = [
    'clinepass',
    'cline',
    'ollama',
    'openrouter',
    'deepseek',
    'openai',
    'custom',
];

/** The month before or after a `YYYY-MM` period. */
export const shiftPeriod = (period: string, months: number): string => {
    const [year = 1970, month = 1] = period.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1 + months, 1));

    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
};

export const currentPeriod = (now = new Date()): string =>
    `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;

export const formatPeriod = (period: string): string => {
    const [year = 1970, month = 1] = period.split('-').map(Number);

    return new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(
        new Date(Date.UTC(year, month - 1, 1))
    );
};
