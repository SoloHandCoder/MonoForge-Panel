import { CircleAlert, CircleCheck, Info, TriangleAlert, X, type LucideIcon } from 'lucide-react';
import { cn, Icon } from '@/sdk';
import type { PanelNotice, PanelNoticeLevel } from '@/sdk/theme';

const levelStyles = {
    danger: {
        icon: CircleAlert,
        box: 'border-destructive/40 bg-destructive/10',
        accent: 'text-destructive',
        label: 'Error',
    },
    warning: { icon: TriangleAlert, box: 'border-warning/40 bg-warning/10', accent: 'text-warning', label: 'Warning' },
    info: { icon: Info, box: 'border-primary/40 bg-primary/10', accent: 'text-primary', label: 'Notice' },
    success: { icon: CircleCheck, box: 'border-success/40 bg-success/10', accent: 'text-success', label: 'Success' },
} satisfies Record<PanelNoticeLevel, { icon: LucideIcon; box: string; accent: string; label: string }>;

interface Props {
    notices: readonly PanelNotice[];
    onDismiss: (id: string) => void;
    className?: string;
}

/** Announcements and the user's notices, coloured by level, one under the other. */
export default function NoticeStack({ notices, onDismiss, className }: Props) {
    if (notices.length === 0) {
        return null;
    }

    return (
        <section aria-label='Notices' className={cn('flex flex-col gap-2', className)}>
            {notices.map((notice) => {
                const style = levelStyles[notice.level];

                return (
                    <div
                        key={notice.id}
                        role={notice.level === 'danger' ? 'alert' : 'status'}
                        data-level={notice.level}
                        className={cn(
                            'flex animate-fade-in items-start gap-3 rounded-lg border px-4 py-3 text-sm motion-reduce:animate-none',
                            style.box
                        )}
                    >
                        <Icon icon={style.icon} aria-hidden className={cn('mt-0.5 size-4 shrink-0', style.accent)} />
                        <div className='min-w-0 flex-1'>
                            <p className='font-medium text-foreground'>
                                <span className='sr-only'>{style.label}: </span>
                                {notice.title}
                            </p>
                            <p className='mt-0.5 whitespace-pre-line break-words text-muted-foreground'>
                                {notice.body}
                            </p>
                            {notice.action && (
                                <a
                                    href={notice.action.url}
                                    className={cn(
                                        'mt-2 inline-flex text-sm font-medium underline-offset-4 hover:underline',
                                        style.accent
                                    )}
                                >
                                    {notice.action.label}
                                </a>
                            )}
                        </div>
                        {notice.dismissible && (
                            <button
                                type='button'
                                onClick={() => onDismiss(notice.id)}
                                aria-label={`Dismiss ${notice.title}`}
                                className='-mr-1 shrink-0 rounded-md p-1 text-muted-foreground transition-colors duration-150 hover:bg-(--hover-row) hover:text-foreground'
                            >
                                <Icon icon={X} aria-hidden className='size-4' />
                            </button>
                        )}
                    </div>
                );
            })}
        </section>
    );
}
