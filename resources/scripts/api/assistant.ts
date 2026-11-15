// The AI assistant (phase 4). The endpoints are in the generated client, but its response types
// are loose (every field optional, open objects), and the server and admin assistants share one
// set of hooks, so the calls stay hand-written here with the exact shapes the controllers return
// (ConversationPresenter, AiProvider::toApiArray, AssistantUsageController).
/* oxlint-disable no-restricted-properties, rules/generated-api-mutations */
import { keepPreviousData, queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';
import { toast } from 'sonner';
import http, { httpErrorToHuman } from '@/api/http';
import { notifyHttpError, notifyServerError } from '@/plugins/notifications';

/** A JSON value, as tool arguments and tool display summaries carry them. */
export type AssistantJson =
    | string
    | number
    | boolean
    | null
    | readonly AssistantJson[]
    | { readonly [key: string]: AssistantJson };

export type AssistantJsonFields = { readonly [key: string]: AssistantJson };

export type AssistantWarning = 'near_limit' | 'free_exhausted' | 'no_balance';

/** `GET /api/client/assistant`: whether the assistant is on, and the user's allowance this month. */
export interface AssistantStatus {
    enabled: boolean;
    server_enabled: boolean;
    /** Free AI tokens per month. */
    free_limit: number;
    /** Free AI tokens used this month. */
    free_used: number;
    /** AI tokens per panel token. */
    ratio: number;
    /** The user's panel token balance. */
    balance: number;
    warning: AssistantWarning | null;
}

export type AssistantConversationStatus = 'idle' | 'running' | 'awaiting_approval' | 'error';

export type AssistantToolCallStatus = 'pending' | 'approved' | 'denied' | 'running' | 'done' | 'failed';

/** Tool results are not messages in the payload: they appear on their call as `result_preview`. */
export type AssistantRole = 'user' | 'assistant';

export interface AssistantToolCall {
    id: number;
    /** The tool's snake_case name, e.g. `read_file`. */
    name: string;
    /** A short human label from the tool's `describeCall`, e.g. "Read config/server.properties". */
    label: string;
    arguments: AssistantJsonFields;
    needs_confirmation: boolean;
    status: AssistantToolCallStatus;
    result_preview: string | null;
    display: AssistantJsonFields | null;
}

export interface AssistantMessage {
    id: number;
    role: AssistantRole;
    content: string | null;
    created_at: string | null;
    tool_calls: AssistantToolCall[];
}

export interface AssistantConversationSummary {
    uuid: string;
    title: string;
    status: AssistantConversationStatus;
    updated_at: string | null;
}

export interface AssistantConversationUsage {
    free_used: number;
    free_limit: number;
    /** Panel tokens charged this month. */
    charged_this_month: number;
}

export interface AssistantConversation {
    uuid: string;
    title: string;
    status: AssistantConversationStatus;
    error: string | null;
    messages: AssistantMessage[];
    usage: AssistantConversationUsage;
}

/** The conversation list, newest first. */
export interface AssistantConversationList {
    data: AssistantConversationSummary[];
}

export interface AssistantMessageInput {
    message: string;
}

/** Server conversations run with the user's subuser permissions; admin ones with their admin permissions. */
export type AssistantScope = { readonly kind: 'server'; readonly server: string } | { readonly kind: 'admin' };

export const adminAssistantScope: AssistantScope = { kind: 'admin' };

export const serverAssistantScope = (server: string): AssistantScope => ({ kind: 'server', server });

const conversationsPath = (scope: AssistantScope): string =>
    scope.kind === 'admin'
        ? '/api/admin/assistant/conversations'
        : `/api/client/servers/${encodeURIComponent(scope.server)}/assistant/conversations`;

const conversationPath = (scope: AssistantScope, conversation: string): string =>
    `${conversationsPath(scope)}/${encodeURIComponent(conversation)}`;

export const getAssistantStatus = async (signal?: AbortSignal): Promise<AssistantStatus> =>
    (await http.get<AssistantStatus>('/api/client/assistant', { signal })).data;

export const listAssistantConversations = async (
    scope: AssistantScope,
    signal?: AbortSignal
): Promise<AssistantConversationList> =>
    (await http.get<AssistantConversationList>(conversationsPath(scope), { signal })).data;

export const createAssistantConversation = async (
    scope: AssistantScope,
    body: AssistantMessageInput
): Promise<AssistantConversation> => (await http.post<AssistantConversation>(conversationsPath(scope), body)).data;

export const getAssistantConversation = async (
    scope: AssistantScope,
    conversation: string,
    signal?: AbortSignal
): Promise<AssistantConversation> =>
    (await http.get<AssistantConversation>(conversationPath(scope, conversation), { signal })).data;

/** Answers 409 while the conversation is running or awaiting approval; returns the conversation. */
export const sendAssistantMessage = async (
    scope: AssistantScope,
    conversation: string,
    body: AssistantMessageInput
): Promise<AssistantConversation> =>
    (await http.post<AssistantConversation>(`${conversationPath(scope, conversation)}/messages`, body)).data;

export type AssistantDecision = 'approve' | 'deny';

export const decideAssistantToolCall = async (
    scope: AssistantScope,
    conversation: string,
    toolCall: number,
    decision: AssistantDecision
): Promise<AssistantConversation> =>
    (
        await http.post<AssistantConversation>(
            `${conversationPath(scope, conversation)}/tool-calls/${toolCall}/${decision}`
        )
    ).data;

export const deleteAssistantConversation = async (scope: AssistantScope, conversation: string): Promise<void> => {
    await http.delete(conversationPath(scope, conversation));
};

// Admin: providers, settings and usage.

export type AssistantProviderPreset =
    | 'cline'
    | 'clinepass'
    | 'ollama'
    | 'openrouter'
    | 'deepseek'
    | 'openai'
    | 'custom';

export interface AssistantProvider {
    id: number;
    name: string;
    preset: AssistantProviderPreset;
    base_url: string;
    model: string;
    /** `{}` when the provider has none. */
    extra_headers: Readonly<Record<string, string>>;
    context_window: number;
    supports_tools: boolean;
    enabled: boolean;
    /** Keys are write-only: the panel only says whether one is stored. */
    has_key: boolean;
    created_at: string | null;
    updated_at: string | null;
}

export interface AssistantProviderInput {
    name: string;
    preset: AssistantProviderPreset;
    base_url: string;
    model: string;
    extra_headers: Readonly<Record<string, string>> | null;
    context_window: number;
    supports_tools: boolean;
    enabled: boolean;
    /** Left out to keep the stored key; null removes it. */
    api_key?: string | null;
}

/** What the provider form prefills for a preset. */
export interface AssistantPresetInfo {
    preset: AssistantProviderPreset;
    label: string;
    base_url: string;
    model: string;
    /** Whether the provider answers without an API key (a local Ollama does). */
    key_optional: boolean;
}

export interface AssistantProviderList {
    data: AssistantProvider[];
    meta: {
        default_provider_id: number | null;
        presets: AssistantPresetInfo[];
    };
}

/** `POST /api/admin/assistant/providers/{provider}/test`; never charged. */
export interface AssistantProviderTest {
    ok: boolean;
    latency_ms: number;
    error: string | null;
    /** The start of the model's reply when it answered. */
    reply: string | null;
}

/** The `monoforge:ai:*` settings. */
export interface AssistantSettings {
    enabled: boolean;
    server_enabled: boolean;
    admin_enabled: boolean;
    default_provider_id: number | null;
    ratio: number;
    free_tokens_monthly: number;
    warn_percent: number;
    max_tool_iterations: number;
    context_tokens: number;
    max_output_tokens: number;
}

export interface AssistantUsageTotals {
    /** All AI tokens of the server assistant. */
    ai_tokens: number;
    /** The part of them covered by the free allowance. */
    free_tokens: number;
    panel_tokens_charged: number;
    /** AI tokens of the admin assistant, which are never charged. */
    admin_ai_tokens: number;
    /** Model calls. */
    calls: number;
}

export interface AssistantUsageRow extends AssistantUsageTotals {
    user: { id: number; username: string; email: string };
}

/** `GET /api/admin/assistant/usage?period=YYYY-MM`: per-user totals for the month. */
export interface AssistantUsageReport {
    period: string;
    data: AssistantUsageRow[];
    totals: AssistantUsageTotals;
}

const providersPath = '/api/admin/assistant/providers';

export const listAssistantProviders = async (signal?: AbortSignal): Promise<AssistantProviderList> =>
    (await http.get<AssistantProviderList>(providersPath, { signal })).data;

export const createAssistantProvider = async (body: AssistantProviderInput): Promise<AssistantProvider> =>
    (await http.post<AssistantProvider>(providersPath, body)).data;

export const updateAssistantProvider = async (id: number, body: AssistantProviderInput): Promise<AssistantProvider> =>
    (await http.put<AssistantProvider>(`${providersPath}/${id}`, body)).data;

export const deleteAssistantProvider = async (id: number): Promise<void> => {
    await http.delete(`${providersPath}/${id}`);
};

export const testAssistantProvider = async (id: number): Promise<AssistantProviderTest> =>
    (await http.post<AssistantProviderTest>(`${providersPath}/${id}/test`)).data;

export const getAssistantSettings = async (signal?: AbortSignal): Promise<AssistantSettings> =>
    (await http.get<AssistantSettings>('/api/admin/assistant/settings', { signal })).data;

export const updateAssistantSettings = async (body: AssistantSettings): Promise<AssistantSettings> =>
    (await http.put<AssistantSettings>('/api/admin/assistant/settings', body)).data;

export const getAssistantUsage = async (period: string, signal?: AbortSignal): Promise<AssistantUsageReport> =>
    (await http.get<AssistantUsageReport>('/api/admin/assistant/usage', { params: { period }, signal })).data;

// Refusals.

/** Where a user tops up panel tokens once the free AI tokens are used up. */
export const ASSISTANT_WALLET_PATH = '/account/wallet';

export interface AssistantErrorMessage {
    title: string;
    description: string;
    /** Whether topping up the wallet fixes it (402). */
    wallet: boolean;
}

const httpStatus = (cause: unknown): number | undefined =>
    axios.isAxiosError(cause) ? cause.response?.status : undefined;

/**
 * The assistant's refusals in words a user can act on: 402 no free AI tokens and no balance,
 * 403 turned off (or no access), 503 no provider set up, 409 a turn is still running or waiting
 * for Allow/Deny. Anything else keeps the server's message under `fallback`.
 */
export const describeAssistantError = (cause: unknown, fallback: string): AssistantErrorMessage => {
    switch (httpStatus(cause) ?? 0) {
        case 402:
            return {
                title: 'Out of AI tokens',
                description:
                    "You have used this month's free AI tokens and your token balance is empty. Top up your wallet to keep using the assistant.",
                wallet: true,
            };
        case 403: {
            const message = httpErrorToHuman(cause);

            return /turned off/i.test(message)
                ? {
                      title: 'The assistant is turned off',
                      description: 'An administrator has turned the AI assistant off.',
                      wallet: false,
                  }
                : { title: 'You cannot use the assistant here', description: message, wallet: false };
        }

        case 503:
            return {
                title: 'The assistant is not set up yet',
                description: 'No AI provider is enabled. An administrator needs to add one under Admin → AI Assistant.',
                wallet: false,
            };
        case 409:
            return {
                title: 'The assistant is busy',
                description: 'Wait for the current reply, or allow or deny the pending action first.',
                wallet: false,
            };
        default:
            return { title: fallback, description: httpErrorToHuman(cause), wallet: false };
    }
};

/** One line for inline error states, such as a conversation that failed to load. */
export const assistantErrorText = (cause: unknown): string => {
    const { title, description } = describeAssistantError(cause, 'Something went wrong');

    return httpStatus(cause) === undefined ? description : `${title}. ${description}`;
};

export const notifyAssistantError = (cause: unknown, fallback: string) => {
    console.error(cause);

    const { title, description, wallet } = describeAssistantError(cause, fallback);

    return notifyServerError(title, {
        description,
        action: wallet
            ? { label: 'Open wallet', onClick: () => window.location.assign(ASSISTANT_WALLET_PATH) }
            : undefined,
    });
};

// Queries.

/** How often a running conversation is polled. */
export const ASSISTANT_POLL_MS = 1500;

const assistantKey = ['assistant'] as const;
const scopeKey = (scope: AssistantScope) => (scope.kind === 'admin' ? 'admin' : `server:${scope.server}`);
const conversationsKey = (scope: AssistantScope) => [...assistantKey, scopeKey(scope), 'conversations'] as const;
const conversationKey = (scope: AssistantScope, conversation: string) =>
    [...conversationsKey(scope), conversation] as const;

export const assistantStatusQueryKey = [...assistantKey, 'status'] as const;
const statusKey = assistantStatusQueryKey;
const providersKey = [...assistantKey, 'admin', 'providers'] as const;
const settingsKey = [...assistantKey, 'admin', 'settings'] as const;

export const assistantStatusQueryOptions = () =>
    queryOptions({
        queryKey: statusKey,
        queryFn: ({ signal }) => getAssistantStatus(signal),
        staleTime: 30_000,
    });

export const useAssistantStatus = (enabled = true) => useQuery({ ...assistantStatusQueryOptions(), enabled });

export const useAssistantConversations = (scope: AssistantScope) =>
    useQuery({
        queryKey: conversationsKey(scope),
        queryFn: ({ signal }) => listAssistantConversations(scope, signal),
    });

/** Whether a conversation is still being worked on, so the thread polls it. */
export const isConversationBusy = (conversation: Pick<AssistantConversation, 'status'> | undefined): boolean =>
    conversation?.status === 'running';

/** The poll interval of a conversation: every 1.5 s while it runs, otherwise none. */
export const conversationPollInterval = (conversation: Pick<AssistantConversation, 'status'> | undefined) =>
    isConversationBusy(conversation) ? ASSISTANT_POLL_MS : false;

export const assistantConversationQueryOptions = (scope: AssistantScope, conversation: string) =>
    queryOptions({
        queryKey: conversationKey(scope, conversation),
        queryFn: ({ signal }) => getAssistantConversation(scope, conversation, signal),
        refetchInterval: (query) => conversationPollInterval(query.state.data),
    });

export const useAssistantConversation = (scope: AssistantScope, conversation: string | null) =>
    useQuery({
        ...assistantConversationQueryOptions(scope, conversation ?? ''),
        enabled: conversation !== null,
    });

/** Keeps the list's status in step with an open conversation, without refetching the list on every poll. */
const syncSummary = (
    summaries: AssistantConversationList | undefined,
    conversation: Pick<AssistantConversation, 'uuid' | 'status' | 'title'>
): AssistantConversationList | undefined =>
    summaries && {
        data: summaries.data.map((summary) =>
            summary.uuid === conversation.uuid
                ? { ...summary, status: conversation.status, title: conversation.title }
                : summary
        ),
    };

let optimisticId = 0;

/** A user message shown in the thread until the server's copy arrives. */
const optimisticMessage = (content: string): AssistantMessage => {
    optimisticId -= 1;

    return { id: optimisticId, role: 'user', content, created_at: new Date().toISOString(), tool_calls: [] };
};

export const useCreateAssistantConversation = (scope: AssistantScope) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (message: string) => createAssistantConversation(scope, { message }),
        onSuccess: async (conversation) => {
            queryClient.setQueryData(conversationKey(scope, conversation.uuid), conversation);
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: conversationsKey(scope), exact: true }),
                queryClient.invalidateQueries({ queryKey: statusKey }),
            ]);
        },
        onError: async (error) => {
            notifyAssistantError(error, 'Unable to start the conversation');
            await queryClient.invalidateQueries({ queryKey: statusKey });
        },
    });
};

export const useSendAssistantMessage = (scope: AssistantScope, conversation: string) => {
    const queryClient = useQueryClient();
    const key = conversationKey(scope, conversation);

    return useMutation({
        mutationFn: (message: string) => sendAssistantMessage(scope, conversation, { message }),
        onMutate: async (message) => {
            await queryClient.cancelQueries({ queryKey: key, exact: true });
            const previous = queryClient.getQueryData<AssistantConversation>(key);

            if (previous) {
                queryClient.setQueryData<AssistantConversation>(key, {
                    ...previous,
                    status: 'running',
                    error: null,
                    messages: [...previous.messages, optimisticMessage(message)],
                });
            }

            return { previous };
        },
        onError: (error, _message, context) => {
            if (context?.previous) {
                queryClient.setQueryData(key, context.previous);
            }

            notifyAssistantError(error, 'Unable to send the message');
        },
        onSuccess: (updated) => {
            queryClient.setQueryData(key, updated);
        },
        onSettled: async (_updated, error) => {
            // A successful send already returned the conversation; refetch only to recover from an error.
            if (error) {
                await queryClient.invalidateQueries({ queryKey: key, exact: true });
            }

            await queryClient.invalidateQueries({ queryKey: statusKey });
        },
    });
};

export interface AssistantDecisionInput {
    toolCall: number;
    decision: AssistantDecision;
}

export const useDecideAssistantToolCall = (scope: AssistantScope, conversation: string) => {
    const queryClient = useQueryClient();
    const key = conversationKey(scope, conversation);

    return useMutation({
        mutationFn: ({ toolCall, decision }: AssistantDecisionInput) =>
            decideAssistantToolCall(scope, conversation, toolCall, decision),
        // The response is the conversation after the call ran (Allow) or was refused (Deny); while
        // the turn continues in the queue its status is `running`, so polling picks it up.
        onSuccess: (updated) => {
            queryClient.setQueryData(key, updated);
        },
        onError: async (error, { decision }) => {
            notifyAssistantError(
                error,
                decision === 'approve' ? 'Unable to allow the action' : 'Unable to deny the action'
            );
            await queryClient.invalidateQueries({ queryKey: key, exact: true });
        },
    });
};

export const useDeleteAssistantConversation = (scope: AssistantScope) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (conversation: string) => deleteAssistantConversation(scope, conversation),
        onSuccess: async (_result, conversation) => {
            queryClient.removeQueries({ queryKey: conversationKey(scope, conversation), exact: true });
            await queryClient.invalidateQueries({ queryKey: conversationsKey(scope), exact: true });
            toast.success('Conversation deleted');
        },
        onError: (error) => notifyHttpError(error, 'Unable to delete the conversation'),
    });
};

/** Mirrors an open conversation's status and title into the cached list. */
export const useSyncConversationSummary = (scope: AssistantScope) => {
    const queryClient = useQueryClient();

    return (conversation: Pick<AssistantConversation, 'uuid' | 'status' | 'title'>) =>
        queryClient.setQueryData<AssistantConversationList>(conversationsKey(scope), (summaries) =>
            syncSummary(summaries, conversation)
        );
};

export const useAssistantProviders = () =>
    useQuery({ queryKey: providersKey, queryFn: ({ signal }) => listAssistantProviders(signal) });

export interface AssistantProviderSave {
    id?: number;
    body: AssistantProviderInput;
}

export const useSaveAssistantProvider = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, body }: AssistantProviderSave) =>
            id === undefined ? createAssistantProvider(body) : updateAssistantProvider(id, body),
        onSuccess: async (_provider, { id }) => {
            await queryClient.invalidateQueries({ queryKey: providersKey });
            toast.success(id === undefined ? 'Provider added' : 'Provider saved');
        },
        onError: (error) => notifyHttpError(error, 'Unable to save the provider'),
    });
};

export const useDeleteAssistantProvider = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id: number) => deleteAssistantProvider(id),
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: providersKey });
            toast.success('Provider deleted');
        },
        onError: (error) => notifyHttpError(error, 'Unable to delete the provider'),
    });
};

export const useTestAssistantProvider = () =>
    useMutation({
        mutationFn: (id: number) => testAssistantProvider(id),
        onSuccess: (result) => {
            const latency = `${result.latency_ms} ms`;

            return result.ok
                ? toast.success('The provider answered', {
                      description: result.reply ? `“${result.reply}” in ${latency}` : latency,
                  })
                : toast.error('The provider failed', { description: result.error ?? latency });
        },
        onError: (error) => notifyHttpError(error, 'Unable to test the provider'),
    });

export const useAssistantSettings = () =>
    useQuery({ queryKey: settingsKey, queryFn: ({ signal }) => getAssistantSettings(signal) });

export const useUpdateAssistantSettings = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (body: AssistantSettings) => updateAssistantSettings(body),
        onSuccess: async (settings) => {
            queryClient.setQueryData(settingsKey, settings);
            await queryClient.invalidateQueries({ queryKey: statusKey });
            toast.success('Assistant settings saved');
        },
        onError: (error) => notifyHttpError(error, 'Unable to save the assistant settings'),
    });
};

export const useAssistantUsage = (period: string) =>
    useQuery({
        queryKey: [...assistantKey, 'admin', 'usage', period],
        queryFn: ({ signal }) => getAssistantUsage(period, signal),
        placeholderData: keepPreviousData,
    });
