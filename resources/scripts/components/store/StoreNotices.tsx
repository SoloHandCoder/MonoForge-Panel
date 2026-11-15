import { TriangleAlert } from 'lucide-react';
import Icon from '@/components/elements/Icon';
import { cn } from '@/lib/cn';

/** The owner's words for a sold-out shop; the purchase endpoint refuses with the same text. */
export const CAPACITY_MESSAGE = "We can't provide new servers right now — we've hit our limit!";

export function CapacityNotice({ className }: { className?: string }) {
    return (
        <p
            role='alert'
            className={cn(
                'flex items-start gap-2 rounded-xl border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm font-medium text-destructive',
                className
            )}
        >
            <Icon icon={TriangleAlert} className='mt-0.5 size-4 shrink-0' aria-hidden />
            {CAPACITY_MESSAGE}
        </p>
    );
}
