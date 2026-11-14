import type { ReactNode } from 'react';
import ProgressBar from '@/components/elements/ProgressBar';
import { usageTone } from '@/components/admin/insights/usageFormat';
import { cn } from '@/lib/cn';

interface UsageMeterProps {
    label: string;
    /** The reading as text, such as `250% / 2100%`. */
    value: string;
    /** Percent of capacity in use; null draws the empty track. */
    percent: number | null;
    /** A second line under the meter, such as what is allocated. */
    detail?: ReactNode;
    className?: string;
}

/** A labelled reading over a meter that turns amber, then red, as it fills. */
export default function UsageMeter({ label, value, percent, detail, className }: UsageMeterProps) {
    return (
        <div className={cn('min-w-0', className)}>
            <div className='mb-1.5 flex items-baseline justify-between gap-3 text-sm'>
                <span className='text-muted-foreground'>{label}</span>
                <span className='truncate font-mono text-foreground tabular-nums'>{value}</span>
            </div>
            <ProgressBar value={percent} tone={usageTone(percent)} label={label} />
            {detail && <p className='mt-1 truncate text-xs text-muted-foreground'>{detail}</p>}
        </div>
    );
}
