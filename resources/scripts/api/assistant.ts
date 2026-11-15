// The AI assistant (phase 4 Task Y), written by hand against the API section of
// docs/superpowers/plans/2026-11-15-phase4-ai-assistant.md until the generated API client is
// regenerated with /api/client/assistant… and /api/admin/assistant….
/* oxlint-disable no-restricted-properties, rules/generated-api-mutations */
import { keepPreviousData, queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import http from '@/api/http';
import { notifyHttpError } from '@/plugins/notifications';

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

export type AssistantRole = 'user' | 'assistant' | 'tool';

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
    created_at: string;
    tool_calls: AssistantToolCall[];
}

export interface AssistantConversationSummary {
    uuid: string;
    title: string;
    status: AssistantConversationStatus;
    updated_at: string;
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

/**
 * The conversation list. The plan only says "list"; this assumes a `data` array, like the panel's
 * other list endpoints. Integration: check against Task W's resource.
 */
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

/** Answers 409 while the conversation is running or awaiting approval. */
export const sendAssistantMessage = async (
    scope: AssistantScope,
    conversation: string,
    body: AssistantMessageInput
): Promise<void> => {
    await http.post(`${conversationPath(scope, conversation)}/messages`, body);
};

export type AssistantDecision = 'approve' | 'deny';

export const decideAssistantToolCall = async (
    scope: AssistantScope,
    conversation: string,
    toolCall: number,
    decision: AssistantDecision
): Promise<void> => {
    await http.post(`${conversationPath(scope, conversation)}/tool-calls/${toolCall}/${decision}`);
};

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
    extra_headers: Readonly<Record<string, string>> | null;
    context_window: number;
    supports_tools: boolean;
    enabled: boolean;
    /** Keys are write-only: the panel only says whether one is stored. */
    has_key: boolean;
    created_at: string;
    updated_at: string;
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
    /** Left out to keep the stored key. */
    api_key?: string;
}

export interface AssistantProviderList {
    data: AssistantProvider[];
}

/** `POST /api/admin/assistant/providers/{provider}/test`. */
export interface AssistantProviderTest {
    ok: boolean;
    error: string | null;
    latency_ms: number | null;
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

export interface AssistantUsageRow {
    user_id: number;
    username: string;
    ai_tokens: number;
    free_tokens: number;
    panel_tokens_charged: number;
}

/** `GET /api/admin/assistant/usage?period=YYYY-MM`: per-user totals for the month. */
export interface AssistantUsageReport {
    period: string;
    data: AssistantUsageRow[];
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
        onError: (error) => notifyHttpError(error, 'Unable to start the conversation'),
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

            notifyHttpError(error, 'Unable to send the message');
        },
        onSettled: async () => {
            await queryClient.invalidateQueries({ queryKey: key, exact: true });
            await queryClient.invalidateQueries({ queryKey: statusKey });
        },
    });
};

/** A copy of the conversation with the call decided and the turn running again. */
const withDecision = (
    conversation: AssistantConversation,
    toolCall: number,
    decision: AssistantDecision
): AssistantConversation => {
    const next = structuredClone(conversation);

    next.status = 'running';

    for (const message of next.messages) {
        for (const call of message.tool_calls) {
            if (call.id === toolCall) {
                call.status = decision === 'approve' ? 'approved' : 'denied';
            }
        }
    }

    return next;
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
        onSuccess: (_result, { toolCall, decision }) => {
            const previous = queryClient.getQueryData<AssistantConversation>(key);

            if (previous) {
                queryClient.setQueryData(key, withDecision(previous, toolCall, decision));
            }
        },
        onError: (error, { decision }) =>
            notifyHttpError(error, decision === 'approve' ? 'Unable to allow the action' : 'Unable to deny the action'),
        onSettled: () => queryClient.invalidateQueries({ queryKey: key, exact: true }),
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
            const latency = result.latency_ms === null ? undefined : `${result.latency_ms} ms`;

            return result.ok
                ? toast.success('The provider answered', { description: latency })
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
