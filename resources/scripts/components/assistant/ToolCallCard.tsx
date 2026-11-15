import { useId, useState } from 'react';
import { ChevronRight, LoaderCircle, ShieldAlert, TriangleAlert } from 'lucide-react';
import type { AssistantDecision, AssistantToolCall } from '@/api/assistant';
import Badge from '@/components/elements/Badge';
import Button from '@/components/elements/Button';
import Icon from '@/components/elements/Icon';
import { revealHidden } from '@/components/assistant/hiddenCharacters';
import { CopyButton } from '@/components/assistant/markdown';
import {
    confirmationWarning,
    isDestructiveTool,
    isSensitiveRead,
    sensitiveIcon,
    toolIcon,
    toolStatusLabels,
    toolStatusTones,
} from '@/components/assistant/toolMeta';
import { cn } from '@/lib/cn';

export interface ToolCallCardProps {
    call: AssistantToolCall;
    /** Shows Allow / Deny on a pending call; left out where nobody can decide. */
    onDecide?: (decision: AssistantDecision) => void;
    /** The decision being sent, while it is. */
    deciding?: AssistantDecision | null;
    /** A newer step of the assistant exists, so a call still pending here can no longer be decided. */
    superseded?: boolean;
}

const hasArguments = (call: AssistantToolCall) => Object.keys(call.arguments).length > 0;

function Section({ title, text }: { title: string; text: string }) {
    return (
        <div className='min-w-0'>
            <div className='mb-1 flex items-center justify-between'>
                <span className='text-xs font-medium tracking-wide text-muted-foreground uppercase'>{title}</span>
                <CopyButton text={text} label={`Copy ${title.toLowerCase()}`} />
            </div>
            <pre className='max-h-64 overflow-auto rounded-lg border border-border bg-sunken p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap text-foreground'>
                {revealHidden(text).text}
            </pre>
        </div>
    );
}

/** The details of a call, or its raw arguments for calls recorded before details existed. */
const detailsOf = (call: AssistantToolCall) =>
    call.details.length || !hasArguments(call)
        ? call.details
        : [{ label: 'Arguments', value: JSON.stringify(call.arguments, null, 2) }];

/** Whether the label or a detail of the call holds hidden characters. */
export const hasHiddenCharacters = (call: AssistantToolCall): boolean =>
    [call.label, ...detailsOf(call).map((detail) => detail.value)].some((text) => revealHidden(text).found);

/** What the call will do, every argument in full, as it will run. Long values wrap; nothing is cut. */
function ConfirmationDetails({ call }: { call: AssistantToolCall }) {
    const details = detailsOf(call);

    if (!details.length) {
        return null;
    }

    return (
        <dl data-testid='tool-call-details' className='grid gap-3 border-t border-inherit px-4 py-3'>
            {hasHiddenCharacters(call) && (
                <p
                    data-testid='hidden-characters'
                    role='alert'
                    className='flex items-start gap-2 text-sm font-medium text-destructive'
                >
                    <Icon icon={ShieldAlert} className='mt-0.5 size-4 shrink-0' aria-hidden />
                    <span>
                        This request contains hidden or direction-changing characters, shown below as{' '}
                        <code className='font-mono'>{'\\u{…}'}</code>. They can make text look different from what runs.
                        Only allow it if you expected them.
                    </span>
                </p>
            )}
            {details.map((detail, index) => (
                // oxlint-disable-next-line react/no-array-index-key -- details have no identity of their own.
                <div key={index} className='min-w-0'>
                    <dt className='mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase'>
                        {detail.label}
                    </dt>
                    <dd>
                        <pre className='rounded-lg border border-border bg-sunken p-3 font-mono text-xs leading-relaxed break-all whitespace-pre-wrap text-foreground'>
                            {revealHidden(detail.value).text}
                        </pre>
                    </dd>
                </div>
            ))}
        </dl>
    );
}

function ConfirmationBar({
    call,
    destructive,
    onDecide,
    deciding,
}: Required<Pick<ToolCallCardProps, 'call' | 'onDecide'>> & {
    destructive: boolean;
    deciding?: AssistantDecision | null;
}) {
    return (
        <div className='border-t border-inherit px-4 py-3'>
            <p className={cn('flex items-start gap-2 text-sm', destructive ? 'text-destructive' : 'text-foreground')}>
                <Icon
                    icon={destructive ? ShieldAlert : TriangleAlert}
                    className={cn('mt-0.5 size-4 shrink-0', destructive ? 'text-destructive' : 'text-warning')}
                    aria-hidden
                />
                {confirmationWarning(call)}
            </p>
            <div className='mt-3 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end'>
                <Button.Text
                    type='button'
                    isSecondary
                    disabled={!!deciding}
                    isLoading={deciding === 'deny'}
                    onClick={() => onDecide('deny')}
                    aria-label={`Deny: ${call.label}`}
                >
                    Deny
                </Button.Text>
                <Button
                    type='button'
                    color={destructive ? 'red' : 'primary'}
                    disabled={!!deciding}
                    isLoading={deciding === 'approve'}
                    onClick={() => onDecide('approve')}
                    aria-label={`Allow: ${call.label}`}
                >
                    Allow
                </Button>
            </div>
        </div>
    );
}

type CardTone = 'danger' | 'warning' | 'normal';

/** Red for a pending destructive call, amber for another pending one, plain otherwise. */
const cardTone = (call: AssistantToolCall): CardTone => {
    if (call.status !== 'pending' || !call.needs_confirmation) {
        return 'normal';
    }

    return isDestructiveTool(call.name) ? 'danger' : 'warning';
};

const cardClasses = {
    danger: 'border-destructive/50 bg-destructive/5',
    warning: 'border-warning/50 bg-warning/5',
    normal: 'border-border',
} satisfies Record<CardTone, string>;

const iconClasses = {
    danger: 'bg-destructive/15 text-destructive',
    warning: 'bg-warning/15 text-warning',
    normal: 'bg-primary/10 text-primary',
} satisfies Record<CardTone, string>;

function Details({ call, id }: { call: AssistantToolCall; id: string }) {
    return (
        <div id={id} className='grid animate-fade-in gap-3 border-t border-border px-3 py-3'>
            {hasArguments(call) && <Section title='Arguments' text={JSON.stringify(call.arguments, null, 2)} />}
            {!!call.result_preview && <Section title='Result' text={call.result_preview} />}
            {call.display?.hidden_from_assistant === true && (
                <p className='text-xs text-muted-foreground'>
                    Only you see this result in full. The assistant got a copy with secret values hidden.
                </p>
            )}
        </div>
    );
}

function CallIcon({ call, tone }: { call: AssistantToolCall; tone: CardTone }) {
    const busy = call.status === 'running' || call.status === 'approved';
    const ToolIcon = isSensitiveRead(call) ? sensitiveIcon : toolIcon(call.name);

    return (
        <span
            className={cn('flex size-8 shrink-0 items-center justify-center rounded-lg', iconClasses[tone])}
            aria-hidden
        >
            <Icon icon={busy ? LoaderCircle : ToolIcon} className={cn('size-4', busy && 'animate-spin')} />
        </span>
    );
}

/** The label (with hidden characters shown, and wrapped while it waits) and the tool name. */
function CallTitle({ call, awaiting }: { call: AssistantToolCall; awaiting: boolean }) {
    return (
        <div className='min-w-0 flex-1'>
            <p
                className={cn(
                    'text-sm font-medium text-foreground',
                    awaiting ? 'break-words whitespace-normal' : 'truncate'
                )}
            >
                {revealHidden(call.label).text}
            </p>
            <p className='truncate font-mono text-xs text-muted-foreground'>{call.name}</p>
        </div>
    );
}

function CallStatus({ call, expired }: { call: AssistantToolCall; expired: boolean }) {
    return (
        <Badge tone={expired ? 'neutral' : toolStatusTones[call.status]} variant='soft' size='sm' className='shrink-0'>
            {expired ? 'Expired' : toolStatusLabels[call.status]}
        </Badge>
    );
}

/** One tool call in the thread: what the assistant does, its arguments and result, and Allow / Deny while it waits. */
export default function ToolCallCard({ call, onDecide, deciding = null, superseded = false }: ToolCallCardProps) {
    const [open, setOpen] = useState(false);
    const detailsId = useId();
    const expired = superseded && call.status === 'pending' && call.needs_confirmation;
    const tone = expired ? 'normal' : cardTone(call);
    const destructive = isDestructiveTool(call.name);
    const expandable = hasArguments(call) || !!call.result_preview;
    const awaiting = tone !== 'normal';

    return (
        <div
            data-testid='tool-call'
            data-status={call.status}
            data-tone={tone}
            className={cn(
                'animate-fade-in overflow-hidden rounded-xl border bg-card transition-colors duration-200',
                cardClasses[tone]
            )}
        >
            <div className='flex items-center gap-3 px-3 py-2.5'>
                <CallIcon call={call} tone={tone} />
                <CallTitle call={call} awaiting={awaiting} />
                <CallStatus call={call} expired={expired} />
                {expandable && (
                    <button
                        type='button'
                        aria-expanded={open}
                        aria-controls={detailsId}
                        aria-label={open ? 'Hide details' : 'Show details'}
                        onClick={() => setOpen(!open)}
                        className='inline-flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors duration-150 hover:bg-(--hover-row) hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none'
                    >
                        <Icon
                            icon={ChevronRight}
                            className={cn('size-4 transition-transform duration-200', open && 'rotate-90')}
                            aria-hidden
                        />
                    </button>
                )}
            </div>
            {open && <Details call={call} id={detailsId} />}
            {awaiting && <ConfirmationDetails call={call} />}
            {awaiting && onDecide && (
                <ConfirmationBar call={call} destructive={destructive} onDecide={onDecide} deciding={deciding} />
            )}
            {expired && (
                <p className='border-t border-border px-4 py-3 text-sm text-muted-foreground'>
                    The assistant moved on to a newer step, so this request can no longer be allowed.
                </p>
            )}
        </div>
    );
}
