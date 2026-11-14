import { EthernetPort } from 'lucide-react';
import { cn } from '@/sdk';
import type { ViewProps } from '@/sdk/theme';

export default function ServerCardAddress({ model, className }: ViewProps<'dashboard.serverCard.address'>) {
    return (
        <div className={cn('flex items-center gap-2 text-sm text-muted-foreground', className)}>
            <EthernetPort size='1em' className='inline-block shrink-0' />
            <p className='truncate font-mono text-xs'>{model.address}</p>
        </div>
    );
}
