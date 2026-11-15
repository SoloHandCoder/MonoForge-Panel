import { EthernetPort } from 'lucide-react';
import { cn, CopyOnClick } from '@/sdk';
import type { ViewProps } from '@/sdk/theme';

export default function ServerCardAddress({ model, className }: ViewProps<'dashboard.serverCard.address'>) {
    return (
        <div className={cn('flex items-center gap-2 text-sm text-muted-foreground', className)}>
            <EthernetPort size='1em' className='inline-block shrink-0' />
            {model.address ? (
                // Above the card's stretched link, so a click copies instead of opening the server.
                <CopyOnClick text={model.address}>
                    <p className='relative z-10 truncate font-mono text-xs'>{model.address}</p>
                </CopyOnClick>
            ) : (
                <p className='truncate font-mono text-xs'>{model.addressPending ? 'Address pending' : model.address}</p>
            )}
        </div>
    );
}
