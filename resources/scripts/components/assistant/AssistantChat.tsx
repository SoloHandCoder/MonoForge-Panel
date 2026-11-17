import { useEffect, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Bot, CircleStop, MessageSquarePlus, Pause, Play, SendHorizontal, Sparkles, Trash2, X } from 'lucide-react';
import {
    assistantErrorText,
    assistantStatusQueryKey,
    useAssistantConversation,
    useAssistantConversations,
    useAssistantStatus,
    useCreateAssistantConversation,
    useDecideAssistantToolCall,
    useDeleteAssistantConversation,
    useSendAssistantMessage,
    useSetAssistantApprovalMode,
    useSetAssistantPaused,
    useStopAssistantConversation,
    useSyncConversationSummary,
    type AssistantApprovalMode,
    type AssistantChoice,
    type AssistantConversation,
    type AssistantConversationStatus,
    type AssistantConversationSummary,
    type AssistantMessage,
    type AssistantScope,
} from '@/api/assistant';
import Markdown from '@/components/assistant/markdown';
import ToolCallCard from '@/components/assistant/ToolCallCard';
import { Alert } from '@/components/elements/alert';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import Icon from '@/components/elements/Icon';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import { fromNow } from '@/lib/dates';
import { cn } from '@/lib/cn';

export interface AssistantChatProps {
    scope: AssistantScope;
    /** Example prompts on an empty chat. */
    suggestions?: readonly string[];
    /** Why the composer is off, e.g. an empty balance; null leaves it on. */
    blockedReason?: string | null;
    /** Shown below the conversation list, such as the usage meter. */
    sidebar?: ReactNode;
    /** The conversation to open first, such as the crash doctor's diagnosis. */
    initialConversation?: string | null;
}

const statusDots = {
    idle: 'bg-muted-foreground/40',
    running: 'bg-primary animate-pulse',
    awaiting_approval: 'bg-warning',
    error: 'bg-destructive',
} satisfies Record<AssistantConversationStatus, string>;

const statusNames = {
    idle: 'Idle',
    running: 'Working',
    awaiting_approval: 'Needs your approval',
    error: 'Error',
} satisfies Record<AssistantConversationStatus, string>;

function ConversationList({
    conversations,
    active,
    onSelect,
}: {
    conversations: readonly AssistantConversationSummary[];
    active: string | null;
    onSelect: (uuid: string | null) => void;
}) {
    return (
        <nav aria-label='Conversations' className='flex min-h-0 flex-col gap-2'>
            <Button
                type='button'
                className='w-full justify-center'
                aria-pressed={active === null}
                onClick={() => onSelect(null)}
            >
                <Icon icon={MessageSquarePlus} className='size-4' aria-hidden />
                New chat
            </Button>
            <ul className='flex gap-2 overflow-x-auto pb-1 lg:max-h-[60vh] lg:flex-col lg:gap-1 lg:overflow-x-visible lg:overflow-y-auto lg:pb-0'>
                {conversations.map((conversation) => {
                    const selected = conversation.uuid === active;

                    return (
                        <li key={conversation.uuid} className='shrink-0 lg:shrink'>
                            <button
                                type='button'
                                aria-current={selected || undefined}
                                onClick={() => onSelect(conversation.uuid)}
                                className={cn(
                                    'flex w-56 items-center gap-2.5 rounded-lg border px-3 py-2 text-left transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:w-full',
                                    selected
                                        ? 'border-primary/40 bg-primary/10'
                                        : 'border-transparent hover:bg-(--hover-row) max-lg:border-border'
                                )}
                            >
                                <span
                                    className={cn('size-2 shrink-0 rounded-full', statusDots[conversation.status])}
                                    title={statusNames[conversation.status]}
                                    aria-hidden
                                />
                                <span className='min-w-0 flex-1'>
                                    <span className='block truncate text-sm text-foreground'>
                                        {conversation.title || 'New conversation'}
                                    </span>
                                    <span className='block truncate text-xs text-muted-foreground'>
                                        <span className='sr-only'>{statusNames[conversation.status]}, </span>
                                        {conversation.mode === 'crash_doctor' && 'Crash doctor · '}
                                        {conversation.updated_at ? fromNow(conversation.updated_at) : ''}
                                    </span>
                                </span>
                            </button>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

function Avatar() {
    return (
        <span
            className='flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/15 text-primary'
            aria-hidden
        >
            <Icon icon={Bot} className='size-4' />
        </span>
    );
}

function TypingIndicator() {
    return (
        <div className='flex animate-fade-in items-center gap-3' role='status' aria-label='The assistant is working'>
            <Avatar />
            <span className='flex items-center gap-1 rounded-2xl rounded-tl-md border border-border bg-card px-4 py-3'>
                {[0, 150, 300].map((delay) => (
                    <span
                        key={delay}
                        className='size-1.5 animate-bounce rounded-full bg-muted-foreground'
                        style={{ animationDelay: `${delay}ms` }}
                    />
                ))}
            </span>
        </div>
    );
}

function StoppedNotice() {
    return (
        <p
            data-testid='message-stopped'
            className='flex animate-fade-in items-center justify-center gap-1.5 text-xs text-muted-foreground'
        >
            <Icon icon={CircleStop} className='size-3.5' aria-hidden />
            Stopped by you
        </p>
    );
}

function MessageItem({
    message,
    onDecide,
    deciding,
    superseded,
}: {
    message: AssistantMessage;
    onDecide?: (toolCall: number, decision: AssistantChoice) => void;
    deciding: { toolCall: number; decision: AssistantChoice } | null;
    /** A newer assistant message exists, so this one's pending calls can no longer be decided. */
    superseded: boolean;
}) {
    if (message.notice === 'stopped') {
        return <StoppedNotice />;
    }

    if (message.role === 'user') {
        return (
            <div className='flex animate-fade-in justify-end' data-testid='message-user'>
                <p className='max-w-[85%] rounded-2xl rounded-br-md bg-primary px-4 py-2.5 text-sm leading-relaxed break-words whitespace-pre-wrap text-primary-foreground sm:max-w-[75%]'>
                    {message.content}
                </p>
            </div>
        );
    }

    return (
        <div className='flex animate-fade-in gap-3' data-testid='message-assistant'>
            <Avatar />
            <div className='min-w-0 flex-1 space-y-2'>
                {!!message.content?.trim() && (
                    <div className='rounded-2xl rounded-tl-md border border-border bg-card px-4 py-3 text-foreground'>
                        <Markdown source={message.content} />
                    </div>
                )}
                {message.tool_calls.map((call) => (
                    <ToolCallCard
                        key={call.id}
                        call={call}
                        onDecide={!superseded && onDecide ? (decision) => onDecide(call.id, decision) : undefined}
                        deciding={deciding?.toolCall === call.id ? deciding.decision : null}
                        superseded={superseded}
                    />
                ))}
            </div>
        </div>
    );
}

function Composer({
    disabled,
    placeholder,
    sending,
    onSend,
    onStop,
    stopping = false,
}: {
    disabled: boolean;
    placeholder: string;
    sending: boolean;
    onSend: (message: string) => void;
    /** While the assistant works, the send button becomes a Stop button. */
    onStop?: () => void;
    stopping?: boolean;
}) {
    const [value, setValue] = useState('');
    const textarea = useRef<HTMLTextAreaElement>(null);
    const canSend = !disabled && !sending && value.trim() !== '';

    useEffect(() => {
        const element = textarea.current;

        if (element) {
            element.style.height = 'auto';
            element.style.height = `${Math.min(element.scrollHeight, 192)}px`;
        }
    }, [value]);

    const send = () => {
        if (canSend) {
            onSend(value.trim());
            setValue('');
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
            event.preventDefault();
            send();
        }
    };

    return (
        <form
            className='flex items-end gap-2 border-t border-border bg-card p-3'
            onSubmit={(event) => {
                event.preventDefault();
                send();
            }}
        >
            <label htmlFor='assistant-composer' className='sr-only'>
                Message the assistant
            </label>
            <textarea
                ref={textarea}
                id='assistant-composer'
                rows={1}
                value={value}
                disabled={disabled}
                placeholder={placeholder}
                onChange={(event) => setValue(event.currentTarget.value)}
                onKeyDown={onKeyDown}
                aria-describedby='assistant-composer-hint'
                className='max-h-48 min-h-10 flex-1 resize-none rounded-xl border border-input-border bg-input px-3.5 py-2.5 text-sm text-foreground transition-colors duration-150 placeholder:text-muted-foreground focus:border-primary focus:outline-none disabled:cursor-not-allowed disabled:opacity-60'
            />
            <span id='assistant-composer-hint' className='sr-only'>
                Enter sends, Shift and Enter adds a new line.
            </span>
            {onStop ? (
                <Button
                    type='button'
                    color='red'
                    className='size-10 shrink-0 p-0'
                    disabled={stopping}
                    isLoading={stopping}
                    onClick={onStop}
                    aria-label='Stop the assistant'
                    title='Stop the assistant (Esc)'
                    aria-keyshortcuts='Escape'
                >
                    <Icon icon={X} className='size-4' aria-hidden />
                </Button>
            ) : (
                <Button
                    type='submit'
                    className='size-10 shrink-0 p-0'
                    disabled={!canSend}
                    isLoading={sending}
                    aria-label='Send'
                >
                    <Icon icon={SendHorizontal} className='size-4' aria-hidden />
                </Button>
            )}
        </form>
    );
}

function EmptyThread({ suggestions, onPick }: { suggestions: readonly string[]; onPick: (text: string) => void }) {
    return (
        <div className='flex flex-1 animate-rise flex-col items-center justify-center gap-4 px-4 py-10 text-center'>
            <span className='flex size-12 items-center justify-center rounded-2xl bg-primary/15 text-primary'>
                <Icon icon={Sparkles} className='size-6' aria-hidden />
            </span>
            <div>
                <p className='font-header text-lg font-semibold text-foreground'>How can I help?</p>
                <p className='mt-1 max-w-sm text-sm text-muted-foreground'>
                    The assistant shows every action it takes and asks before anything sensitive.
                </p>
            </div>
            {suggestions.length > 0 && (
                <div className='flex max-w-xl flex-wrap justify-center gap-2'>
                    {suggestions.map((suggestion) => (
                        <button
                            key={suggestion}
                            type='button'
                            onClick={() => onPick(suggestion)}
                            className='rounded-full border border-border bg-card px-3.5 py-1.5 text-sm text-foreground transition-colors duration-150 hover:border-primary/50 hover:bg-(--hover-row) focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none'
                        >
                            {suggestion}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

/** Scrolls to the newest message when something arrives, unless the reader scrolled up. */
function useStickToBottom(dependency: string) {
    const container = useRef<HTMLDivElement>(null);
    const stuck = useRef(true);

    useEffect(() => {
        const element = container.current;

        if (element && stuck.current) {
            element.scrollTo?.({ top: element.scrollHeight, behavior: 'smooth' });
        }
    }, [dependency]);

    const onScroll = () => {
        const element = container.current;

        if (element) {
            stuck.current = element.scrollHeight - element.scrollTop - element.clientHeight < 80;
        }
    };

    return { container, onScroll };
}

/** Esc stops the assistant while it works, unless a dialog is open (Esc closes that). */
function useEscapeToStop(active: boolean, stop: () => void) {
    const latest = useRef(stop);

    latest.current = stop;

    useEffect(() => {
        const onKeyDown = (event: globalThis.KeyboardEvent) => {
            if (event.key === 'Escape' && !event.defaultPrevented && !document.querySelector('[role="dialog"]')) {
                latest.current();
            }
        };

        if (active) {
            window.addEventListener('keydown', onKeyDown);
        }

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [active]);
}

/** The per-user pause switch: while paused, no turn starts or continues. */
function PauseToggle({ paused }: { paused: boolean }) {
    const setPaused = useSetAssistantPaused();

    return (
        <button
            type='button'
            aria-pressed={paused}
            disabled={setPaused.isPending}
            onClick={() => setPaused.mutate(!paused)}
            title={
                paused ? 'Resume the assistant' : 'Pause the assistant: nothing starts or is spent until you resume it'
            }
            className={cn(
                'inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md border px-2.5 text-xs font-medium transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-60',
                paused
                    ? 'border-warning/50 bg-warning/10 text-warning'
                    : 'border-border text-muted-foreground hover:bg-(--hover-row) hover:text-foreground'
            )}
        >
            <Icon icon={paused ? Play : Pause} className='size-3.5' aria-hidden />
            {paused ? 'Resume' : 'Pause'}
            <span className='sr-only'> the assistant</span>
        </button>
    );
}

function PausedBanner() {
    const setPaused = useSetAssistantPaused();

    return (
        <div
            role='status'
            data-testid='assistant-paused'
            className='flex flex-wrap items-center gap-3 border-b border-warning/40 bg-warning/10 px-4 py-2.5 text-sm text-foreground'
        >
            <Icon icon={Pause} className='size-4 shrink-0 text-warning' aria-hidden />
            <span className='min-w-0 flex-1'>
                The assistant is paused. Nothing starts and nothing is spent until you resume it.
            </span>
            <Button type='button' size='small' isLoading={setPaused.isPending} onClick={() => setPaused.mutate(false)}>
                Resume
            </Button>
        </div>
    );
}

const approvalModes = [
    { mode: 'ask', label: 'Ask before changes' },
    { mode: 'allow_everything', label: 'Allow everything' },
] as const satisfies readonly { mode: AssistantApprovalMode; label: string }[];

/** The selected mode is tinted: red for "Allow everything", the primary colour for asking. */
const modeButtonClass = (mode: AssistantApprovalMode, selected: boolean): string => {
    if (!selected) {
        return 'text-muted-foreground hover:text-foreground';
    }

    return mode === 'allow_everything' ? 'bg-destructive/15 text-destructive' : 'bg-primary/15 text-primary';
};

/** "Ask before changes" or "Allow everything", with a warning before the latter. */
function ApprovalModeSwitch({ scope, conversation }: { scope: AssistantScope; conversation: AssistantConversation }) {
    const setMode = useSetAssistantApprovalMode(scope, conversation.uuid);
    const [warning, setWarning] = useState(false);
    const current = conversation.approval_mode;

    const choose = (mode: AssistantApprovalMode) => {
        if (mode === current || setMode.isPending) {
            return;
        }

        if (mode === 'allow_everything') {
            setWarning(true);
        } else {
            setMode.mutate({ mode });
        }
    };

    return (
        <>
            <div
                role='group'
                aria-label='When the assistant asks before acting'
                className='inline-flex shrink-0 rounded-md border border-border p-0.5'
            >
                {approvalModes.map(({ mode, label }) => {
                    const unavailable = mode === 'allow_everything' && !conversation.allow_everything_available;

                    return (
                        <button
                            key={mode}
                            type='button'
                            aria-pressed={current === mode}
                            disabled={unavailable || setMode.isPending}
                            title={unavailable ? 'An administrator has turned off "Allow everything".' : undefined}
                            onClick={() => choose(mode)}
                            className={cn(
                                'rounded px-2 py-1 text-xs font-medium transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50',
                                modeButtonClass(mode, current === mode)
                            )}
                        >
                            {label}
                        </button>
                    );
                })}
            </div>
            <Dialog.Confirm
                open={warning}
                onClose={() => setWarning(false)}
                title='Allow everything?'
                confirm='Allow everything'
                pending={setMode.isPending}
                onConfirmed={() =>
                    setMode.mutate(
                        { mode: 'allow_everything', confirmed: true },
                        { onSettled: () => setWarning(false) }
                    )
                }
            >
                <div className='space-y-2 text-sm text-muted-foreground'>
                    <p>
                        The assistant will no longer ask before it acts in this conversation. It can edit and delete
                        files, run console commands, change startup settings and restart the server on its own.
                    </p>
                    <p>
                        A few actions still ask every time, such as deleting many files or a world folder. You can stop
                        the assistant at any moment with the X button or Esc, and switch back to &ldquo;Ask before
                        changes&rdquo; here.
                    </p>
                </div>
            </Dialog.Confirm>
        </>
    );
}

/** What the composer says while it cannot be used, or what to do next. */
const threadPlaceholder = (status: AssistantConversationStatus, blockedReason: string | null): string => {
    if (blockedReason) {
        return blockedReason;
    }

    if (status === 'awaiting_approval') {
        return 'Allow or deny the action above, or stop the assistant.';
    }

    return status === 'running' ? 'The assistant is working… Esc stops it.' : 'Ask a follow-up…';
};

function ThreadStatus({ conversation }: { conversation: AssistantConversation }) {
    const busy = conversation.status === 'running' || conversation.status === 'awaiting_approval';

    return (
        <p className='flex items-center gap-1.5 text-xs text-muted-foreground'>
            <span className={cn('size-1.5 rounded-full', statusDots[conversation.status])} aria-hidden />
            {statusNames[conversation.status]}
            {conversation.allow_until_next_message && busy && (
                <span className='truncate'> · Allowing until your next message</span>
            )}
        </p>
    );
}

const threadSignature = (conversation: AssistantConversation | undefined): string =>
    conversation
        ? `${conversation.uuid}:${conversation.messages.length}:${conversation.status}:${conversation.messages
              .flatMap((message) => message.tool_calls.map((call) => call.status))
              .join(',')}`
        : '';

function Thread({
    scope,
    uuid,
    conversation,
    blockedReason,
    paused,
    onDeleted,
}: {
    scope: AssistantScope;
    uuid: string;
    conversation: AssistantConversation;
    blockedReason: string | null;
    paused: boolean;
    onDeleted: () => void;
}) {
    const send = useSendAssistantMessage(scope, uuid);
    const decide = useDecideAssistantToolCall(scope, uuid);
    const stop = useStopAssistantConversation(scope, uuid);
    const remove = useDeleteAssistantConversation(scope);
    const { container, onScroll } = useStickToBottom(threadSignature(conversation));
    const running = conversation.status === 'running';
    const awaiting = conversation.status === 'awaiting_approval';
    const busy = running || awaiting;
    const deciding = decide.isPending && decide.variables ? decide.variables : null;
    const onStop = () => {
        if (!stop.isPending) {
            stop.mutate();
        }
    };

    useEscapeToStop(busy, onStop);
    // Only the latest step can be decided; the server refuses older pending calls.
    const latestAssistant = [...conversation.messages].reverse().find((message) => message.role === 'assistant')?.id;
    const onDecide = awaiting
        ? (toolCall: number, decision: AssistantChoice) => decide.mutate({ toolCall, decision })
        : undefined;

    const placeholder = threadPlaceholder(conversation.status, blockedReason);

    return (
        <>
            <header className='flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border px-4 py-3'>
                <div className='min-w-0 flex-1 basis-40'>
                    <h2 className='truncate text-sm font-semibold text-foreground'>
                        {conversation.title || 'New conversation'}
                    </h2>
                    <ThreadStatus conversation={conversation} />
                </div>
                <ApprovalModeSwitch scope={scope} conversation={conversation} />
                <PauseToggle paused={paused} />
                <Dialog.ConfirmTrigger
                    title='Delete conversation'
                    confirm='Delete'
                    onConfirmed={async (_event, close) => {
                        try {
                            await remove.mutateAsync(uuid);
                            close();
                            onDeleted();
                        } catch {
                            // Error toast is handled by the mutation.
                        }
                    }}
                    trigger={({ onClick }) => (
                        <button
                            type='button'
                            onClick={onClick}
                            aria-label='Delete conversation'
                            title='Delete conversation'
                            className='inline-flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors duration-150 hover:bg-destructive/10 hover:text-destructive focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none'
                        >
                            <Icon icon={Trash2} className='size-4' aria-hidden />
                        </button>
                    )}
                >
                    <p className='text-sm text-muted-foreground'>
                        This deletes the conversation and its history. Actions the assistant already took stay done.
                    </p>
                </Dialog.ConfirmTrigger>
            </header>
            {paused && <PausedBanner />}
            <div
                ref={container}
                onScroll={onScroll}
                className='flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 py-5'
                aria-live='polite'
                aria-busy={running}
                data-testid='assistant-thread'
            >
                {conversation.messages.map((message) => (
                    <MessageItem
                        key={message.id}
                        message={message}
                        onDecide={onDecide}
                        deciding={deciding}
                        superseded={message.role === 'assistant' && message.id !== latestAssistant}
                    />
                ))}
                {running && <TypingIndicator />}
                {conversation.status === 'idle' && !!conversation.error && (
                    <Alert type='warning' title='The assistant was busy'>
                        {conversation.error}
                    </Alert>
                )}
                {conversation.status === 'error' && (
                    <Alert type='danger' title='The assistant stopped'>
                        {conversation.error ?? 'Something went wrong. Send a message to try again.'}
                    </Alert>
                )}
            </div>
            <Composer
                disabled={busy || !!blockedReason}
                sending={send.isPending}
                placeholder={placeholder}
                onSend={(message) => send.mutate(message)}
                onStop={busy ? onStop : undefined}
                stopping={stop.isPending}
            />
        </>
    );
}

function NewThread({
    scope,
    suggestions,
    blockedReason,
    paused,
    onCreated,
}: {
    scope: AssistantScope;
    suggestions: readonly string[];
    blockedReason: string | null;
    paused: boolean;
    onCreated: (uuid: string) => void;
}) {
    const create = useCreateAssistantConversation(scope);
    const start = (message: string) => {
        if (!blockedReason) {
            create.mutate(message, { onSuccess: (conversation) => onCreated(conversation.uuid) });
        }
    };

    return (
        <>
            <header className='flex items-center gap-3 border-b border-border px-4 py-3'>
                <h2 className='min-w-0 flex-1 truncate text-sm font-semibold text-foreground'>New conversation</h2>
                <PauseToggle paused={paused} />
            </header>
            {paused && <PausedBanner />}
            {create.isPending ? (
                <div className='flex flex-1 flex-col gap-4 px-4 py-5'>
                    <div className='flex justify-end'>
                        <p className='max-w-[75%] rounded-2xl rounded-br-md bg-primary px-4 py-2.5 text-sm whitespace-pre-wrap text-primary-foreground'>
                            {create.variables}
                        </p>
                    </div>
                    <TypingIndicator />
                </div>
            ) : (
                <EmptyThread suggestions={blockedReason ? [] : suggestions} onPick={start} />
            )}
            <Composer
                disabled={!!blockedReason}
                sending={create.isPending}
                placeholder={blockedReason ?? 'Ask the assistant…'}
                onSend={start}
            />
        </>
    );
}

/** Keeps the cached list in step with the open conversation, and refreshes the allowance when a turn ends. */
function useConversationSideEffects(scope: AssistantScope, conversation: AssistantConversation | undefined) {
    const sync = useSyncConversationSummary(scope);
    const queryClient = useQueryClient();
    const previous = useRef<AssistantConversationStatus | null>(null);
    const status = conversation?.status;
    const title = conversation?.title;
    const uuid = conversation?.uuid;

    useEffect(() => {
        if (uuid === undefined || status === undefined || title === undefined) {
            return;
        }

        sync({ uuid, status, title });

        if (previous.current === 'running' && status !== 'running') {
            void queryClient.invalidateQueries({ queryKey: assistantStatusQueryKey });
        }

        previous.current = status;
        // `sync` is recreated each render; the effect only needs to follow the conversation.
        // oxlint-disable-next-line react/exhaustive-deps
    }, [uuid, status, title, queryClient]);
}

/** The assistant: a conversation list beside the open chat thread and its composer. */
const noSuggestions: readonly string[] = [];

export default function AssistantChat({
    scope,
    suggestions = noSuggestions,
    blockedReason = null,
    sidebar,
    initialConversation = null,
}: AssistantChatProps) {
    const [active, setActive] = useState<string | null>(initialConversation);
    const list = useAssistantConversations(scope);
    const conversation = useAssistantConversation(scope, active);
    const paused = useAssistantStatus().data?.paused ?? false;
    const blocked = paused ? 'You paused the assistant. Resume it to chat.' : blockedReason;

    useConversationSideEffects(scope, conversation.data);

    if (list.error && !list.data) {
        return <ServerError message={assistantErrorText(list.error)} onRetry={() => void list.refetch()} />;
    }

    const renderThread = () => {
        if (active === null) {
            return (
                <NewThread
                    scope={scope}
                    suggestions={suggestions}
                    blockedReason={blocked}
                    paused={paused}
                    onCreated={setActive}
                />
            );
        }

        if (conversation.error && !conversation.data) {
            return (
                <div className='flex flex-1 items-center justify-center p-6'>
                    <ServerError
                        message={assistantErrorText(conversation.error)}
                        onRetry={() => void conversation.refetch()}
                    />
                </div>
            );
        }

        return conversation.data ? (
            <Thread
                key={active}
                scope={scope}
                uuid={active}
                conversation={conversation.data}
                blockedReason={blocked}
                paused={paused}
                onDeleted={() => setActive(null)}
            />
        ) : (
            <div className='flex flex-1 items-center justify-center'>
                <Spinner size='large' />
            </div>
        );
    };

    return (
        <div className='grid min-w-0 gap-4 lg:grid-cols-[16rem_minmax(0,1fr)]'>
            <aside className='flex min-w-0 flex-col gap-4'>
                {list.data ? (
                    <ConversationList conversations={list.data.data} active={active} onSelect={setActive} />
                ) : (
                    <Spinner size='small' centered />
                )}
                {sidebar}
            </aside>
            <section
                aria-label='Chat'
                className='flex h-[min(75vh,52rem)] min-h-[28rem] min-w-0 flex-col overflow-hidden rounded-2xl border border-border bg-background'
            >
                {renderThread()}
            </section>
        </div>
    );
}
