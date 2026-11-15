import type { LucideIcon } from 'lucide-react';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';

interface Props {
    icon: LucideIcon;
    title: string;
    description: string;
}

/** What a Shop sub-tab shows until the feature that owns it is installed. */
export default function ShopSlotPlaceholder({ icon: SlotIcon, title, description }: Props) {
    return (
        <Empty>
            <EmptyHeader>
                <EmptyMedia variant='icon'>
                    <SlotIcon />
                </EmptyMedia>
                <EmptyTitle>{title}</EmptyTitle>
                <EmptyDescription>{description} This section is not available in this build yet.</EmptyDescription>
            </EmptyHeader>
        </Empty>
    );
}
