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
    /** The AI crash doctor is offered (its switch and the server assistant are on). */
    crash_doctor_enabled: boolean;
    /** Free AI tokens per month. */
    free_limit: number;
    /** Free AI tokens used this month. */
    free_used: number;
    /** AI tokens per panel token. */
    ratio: number;
    /** The user's panel token balance. */
    balance: number;
    warning: AssistantWarning | null;
    /** The user paused the assistant: no turn starts or continues until they resume it. */
    paused: boolean;
}

/** `ask` asks before changes (the default); `allow_everything` allows them without asking. */
export type AssistantApprovalMode = 'ask' | 'allow_everything';

/** How a call that needed confirmation was allowed without a click. */
export type AssistantAutoApproval = 'allow_everything' | 'until_next_message';

export type AssistantConversationStatus = 'idle' | 'running' | 'awaiting_approval' | 'error';

/** `crash_doctor` for a diagnosis the crash doctor started. */
export type AssistantConversationMode = 'chat' | 'crash_doctor';

export type AssistantToolCallStatus = 'pending' | 'approved' | 'denied' | 'running' | 'done' | 'failed';

/** Tool results are not messages in the payload: they appear on their call as `result_preview`. */
export type AssistantRole = 'user' | 'assistant';

export interface AssistantToolCallDetail {
    label: string;
    value: string;
}

export interface AssistantToolCall {
    id: number;
    /** The tool's snake_case name, e.g. `read_file`. */
    name: string;
    /** A short human label from the tool's `describeCall`, e.g. "Read /config/server.properties". */
    label: string;
    /** The arguments as they run (paths resolved). */
    arguments: AssistantJsonFields;
    /** Every argument in full, as the card shows it before the user allows the call. */
    details: AssistantToolCallDetail[];
    needs_confirmation: boolean;
    /** Waits for a click even in "Allow everything" (many files, a world folder, tokens…). */
    always_ask: boolean;
    auto_approved: AssistantAutoApproval | null;
    status: AssistantToolCallStatus;
    result_preview: string | null;
    display: AssistantJsonFields | null;
}

export interface AssistantMessage {
    id: number;
    role: AssistantRole;
    content: string | null;
    /** `stopped` for the note the stop button leaves in the thread. */
    notice: 'stopped' | null;
    created_at: string | null;
    tool_calls: AssistantToolCall[];
}

export interface AssistantConversationSummary {
    uuid: string;
    title: string;
    mode: AssistantConversationMode;
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
    mode: AssistantConversationMode;
    approval_mode: AssistantApprovalMode;
    /** "Allow until my next message" holds for the turn in progress. */
    allow_until_next_message: boolean;
    /** Whether the panel lets conversations switch to "Allow everything". */
    allow_everything_available: boolean;
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

/** A click on a confirmation card: Allow, Allow until my next message, or Deny. */
export type AssistantChoice = 'approve' | 'approve_until_next_message' | 'deny';

export const decideAssistantToolCall = async (
    scope: AssistantScope,
    conversation: string,
    toolCall: number,
    choice: AssistantChoice
): Promise<AssistantConversation> => {
    const url = `${conversationPath(scope, conversation)}/tool-calls/${toolCall}/${choice === 'deny' ? 'deny' : 'approve'}`;
    const response =
        choice === 'approve_until_next_message'
            ? await http.post<AssistantConversation>(url, { until_next_message: true })
            : await http.post<AssistantConversation>(url);

    return response.data;
};

/** Stops the turn in progress; stopping an idle conversation changes nothing. */
export const stopAssistantConversation = async (
    scope: AssistantScope,
    conversation: string
): Promise<AssistantConversation> =>
    (await http.post<AssistantConversation>(`${conversationPath(scope, conversation)}/stop`)).data;

/** `allow_everything` needs `confirmed`: the user saw the warning. */
export const setAssistantApprovalMode = async (
    scope: AssistantScope,
    conversation: string,
    mode: AssistantApprovalMode,
    confirmed = false
): Promise<AssistantConversation> =>
    (
        await http.put<AssistantConversation>(`${conversationPath(scope, conversation)}/approval-mode`, {
            mode,
            confirmed,
        })
    ).data;

export const setAssistantPaused = async (paused: boolean): Promise<AssistantStatus> =>
    (await http.put<AssistantStatus>('/api/client/assistant/pause', { paused })).data;

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

export interface AssistantProviderHeader {
    name: string;
    has_value: boolean;
}

export interface AssistantProvider {
    id: number;
    name: string;
    preset: AssistantProviderPreset;
    base_url: string;
    model: string;
    /** Header names only: values are write-only, like the key. */
    extra_headers: readonly AssistantProviderHeader[];
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
    /**
     * Every header by name: a value sets it, null keeps the stored value. Headers left out are
     * removed; null removes them all.
     */
    extra_headers: Readonly<Record<string, string | null>> | null;
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
    crash_doctor_enabled: boolean;
    default_provider_id: number | null;
    ratio: number;
    free_tokens_monthly: number;
    warn_percent: number;
    max_tool_iterations: number;
    context_tokens: number;
    max_output_tokens: number;
    /** Whether conversations may be switched to "Allow everything". */
    allow_everything_enabled: boolean;
    /** Whether the assistant may look things up on the web. */
    web_search_enabled: boolean;
    /** `native` is the search built into the AI provider (OpenRouter only). */
    web_search_provider: AssistantSearchProvider;
    /** The search API key is write-only: the panel only says whether one is stored. */
    web_search_key_set: boolean;
}

export type AssistantSearchProvider = 'native' | 'brave' | 'tavily';

export interface AssistantSettingsInput extends Omit<AssistantSettings, 'web_search_key_set'> {
    /** A new Brave Search or Tavily key; left out or blank keeps the stored one. */
    web_search_key?: string | null;
    /** Removes the stored key. */
    clear_web_search_key?: boolean;
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

export const updateAssistantSettings = async (body: AssistantSettingsInput): Promise<AssistantSettings> =>
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

            if (/paused the assistant/i.test(message)) {
                return {
                    title: 'The assistant is paused',
                    description:
                        'You paused the assistant. Resume it to continue; nothing is spent while it is paused.',
                    wallet: false,
                };
            }

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

    return {
        id: optimisticId,
        role: 'user',
        content,
        notice: null,
        created_at: new Date().toISOString(),
        tool_calls: [],
    };
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
    decision: AssistantChoice;
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
                decision === 'deny' ? 'Unable to deny the action' : 'Unable to allow the action'
            );
            await queryClient.invalidateQueries({ queryKey: key, exact: true });
        },
    });
};

/** The stop button: the conversation comes back idle with "Stopped by you." in the thread. */
export const useStopAssistantConversation = (scope: AssistantScope, conversation: string) => {
    const queryClient = useQueryClient();
    const key = conversationKey(scope, conversation);

    return useMutation({
        mutationFn: () => stopAssistantConversation(scope, conversation),
        onMutate: async () => {
            // A poll answering after the stop must not bring back the running state.
            await queryClient.cancelQueries({ queryKey: key, exact: true });
        },
        onSuccess: async (updated) => {
            queryClient.setQueryData(key, updated);
            await queryClient.invalidateQueries({ queryKey: statusKey });
        },
        onError: async (error) => {
            notifyAssistantError(error, 'Unable to stop the assistant');
            await queryClient.invalidateQueries({ queryKey: key, exact: true });
        },
    });
};

export interface AssistantApprovalModeInput {
    mode: AssistantApprovalMode;
    confirmed?: boolean;
}

export const useSetAssistantApprovalMode = (scope: AssistantScope, conversation: string) => {
    const queryClient = useQueryClient();
    const key = conversationKey(scope, conversation);

    return useMutation({
        mutationFn: ({ mode, confirmed }: AssistantApprovalModeInput) =>
            setAssistantApprovalMode(scope, conversation, mode, confirmed),
        onSuccess: (updated) => {
            queryClient.setQueryData(key, updated);
        },
        onError: (error) => notifyAssistantError(error, 'Unable to change the approval mode'),
    });
};

/** The per-user "Pause the assistant" switch. Pausing also stops turns in progress. */
export const useSetAssistantPaused = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (paused: boolean) => setAssistantPaused(paused),
        onSuccess: async (status, paused) => {
            queryClient.setQueryData(statusKey, status);

            if (paused) {
                await queryClient.invalidateQueries({ queryKey: assistantKey });
            }

            toast.success(paused ? 'Assistant paused' : 'Assistant resumed');
        },
        onError: (error) => notifyHttpError(error, 'Unable to change the pause switch'),
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
        mutationFn: (body: AssistantSettingsInput) => updateAssistantSettings(body),
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
