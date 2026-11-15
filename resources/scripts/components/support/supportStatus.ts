import type { SupportTicketStatus } from '@/api/support/queries';
import type { UiTone } from '@/themes/skins';

export const supportStatusLabels = {
    open: 'Open',
    answered: 'Answered',
    waiting_staff: 'Waiting for staff',
    closed: 'Closed',
} satisfies Record<SupportTicketStatus, string>;

export const supportStatusTones = {
    open: 'info',
    answered: 'success',
    waiting_staff: 'warning',
    closed: 'neutral',
} satisfies Record<SupportTicketStatus, UiTone>;

export const supportStatuses: readonly SupportTicketStatus[] = ['open', 'answered', 'waiting_staff', 'closed'];
