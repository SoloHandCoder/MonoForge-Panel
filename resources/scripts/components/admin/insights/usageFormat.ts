import { bytesToString } from '@/lib/formatters';
import type { UiTone } from '@/themes/skins';

/** The share of `capacity` in use, from 0 to 100, or null when there is no capacity to measure against. */
export function usagePercent(used: number | null, capacity: number | null): number | null {
    if (used === null || capacity === null || capacity <= 0) {
        return null;
    }

    return Math.min(100, Math.max(0, (used / capacity) * 100));
}

/** Calm by default, amber past three quarters and red past nine tenths. */
export function usageTone(percent: number | null): UiTone {
    if (percent === null) {
        return 'neutral';
    }

    if (percent >= 90) {
        return 'danger';
    }

    return percent >= 75 ? 'warning' : 'accent';
}

const percentText = (value: number): string => `${Math.round(value)}%`;

/** CPU where 100% is one thread, such as `250% / 2100%`; the limit is left out when unknown or unlimited. */
export function cpuText(used: number, capacity: number | null): string {
    return capacity === null ? percentText(used) : `${percentText(used)} / ${percentText(capacity)}`;
}

/** Bytes against a limit, such as `1.5 GiB / 4 GiB`, or `1.5 GiB / ∞` when unlimited. */
export function bytesText(used: number | null, limit: number | null): string {
    const value = used === null ? '—' : bytesToString(used);

    return `${value} / ${limit === null ? '∞' : bytesToString(limit)}`;
}

/** How a Wings power state reads on its badge. */
export interface StateBadge {
    label: string;
    tone: UiTone;
}

/** A Wings power state as a short label and the tone its badge takes. */
export function stateBadge(state: string): StateBadge {
    switch (state) {
        case 'running':
            return { label: 'Running', tone: 'success' };
        case 'starting':
            return { label: 'Starting', tone: 'warning' };
        case 'stopping':
            return { label: 'Stopping', tone: 'warning' };
        case 'offline':
            return { label: 'Offline', tone: 'neutral' };
        default:
            return { label: 'Unknown', tone: 'neutral' };
    }
}
