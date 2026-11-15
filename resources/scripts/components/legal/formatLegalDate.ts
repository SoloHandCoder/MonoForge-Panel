import { formatDate } from '@/lib/dates';

/** "20 November 2026", or nothing for a date that cannot be read. */
export function formatLegalDate(date: string): string {
    const parsed = new Date(date);

    return date === '' || Number.isNaN(parsed.getTime()) ? '' : formatDate(parsed, 'D MMMM YYYY');
}
