import type { PublicServerStatus } from '@/api/public/serverPage';

/** How each server status is shown on the public pages and the discovery cards. */
export const statusStyles = {
    online: { label: 'Online', dot: 'bg-success', text: 'text-success', ping: true },
    starting: { label: 'Starting', dot: 'bg-warning', text: 'text-warning', ping: true },
    sleeping: { label: 'Sleeping', dot: 'bg-primary', text: 'text-primary', ping: false },
    offline: { label: 'Offline', dot: 'bg-destructive', text: 'text-destructive', ping: false },
    unknown: { label: 'Status unknown', dot: 'bg-muted-foreground', text: 'text-muted-foreground', ping: false },
} satisfies Record<PublicServerStatus, { label: string; dot: string; text: string; ping: boolean }>;
