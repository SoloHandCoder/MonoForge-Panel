import type { AdminAnnouncement, AnnouncementLevel } from '@/api/admin/announcements/queries';
import type { UiTone } from '@/themes/skins';

export const levelLabels = {
    info: 'Info',
    success: 'Success',
    warning: 'Warning',
    danger: 'Danger',
} satisfies Record<AnnouncementLevel, string>;

export const levelTones = {
    info: 'accent',
    success: 'success',
    warning: 'warning',
    danger: 'danger',
} satisfies Record<AnnouncementLevel, UiTone>;

type AnnouncementStatus = AdminAnnouncement['attributes']['status'];

export const statusLabels = {
    active: 'Active',
    scheduled: 'Scheduled',
    ended: 'Ended',
} satisfies Record<AnnouncementStatus, string>;

export const statusTones = {
    active: 'success',
    scheduled: 'info',
    ended: 'neutral',
} satisfies Record<AnnouncementStatus, UiTone>;
