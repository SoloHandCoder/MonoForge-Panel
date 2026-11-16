import { useEffect, useRef } from 'react';
import { cn } from '@/lib/cn';

interface Props {
    active: boolean;
    onClick: () => void;
    children: string;
}

export default function SettingsTabButton({ active, onClick, children }: Props) {
    const ref = useRef<HTMLButtonElement>(null);

    // On a phone the tab strip scrolls sideways: keep the open tab in view, so opening
    // /panel/shop/referrals directly does not show a strip with no tab underlined.
    useEffect(() => {
        if (active) {
            ref.current?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
        }
    }, [active]);

    return (
        <button
            ref={ref}
            type='button'
            onClick={onClick}
            className={cn(
                'shrink-0 whitespace-nowrap px-4 py-2 text-sm uppercase tracking-wide border-b-2 transition-colors duration-150',
                active ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground'
            )}
        >
            {children}
        </button>
    );
}
