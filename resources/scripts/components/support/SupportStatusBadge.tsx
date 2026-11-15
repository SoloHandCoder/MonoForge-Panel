import type { SupportTicketStatus } from '@/api/support/queries';
import Badge from '@/components/elements/Badge';
import { supportStatusLabels, supportStatusTones } from '@/components/support/supportStatus';

export default function SupportStatusBadge({ status }: { status: SupportTicketStatus }) {
    return (
        <Badge tone={supportStatusTones[status]} variant='soft' size='sm'>
            {supportStatusLabels[status]}
        </Badge>
    );
}
