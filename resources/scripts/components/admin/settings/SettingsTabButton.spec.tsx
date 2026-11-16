/** @vitest-environment jsdom */

import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SettingsTabButton from './SettingsTabButton';

describe('SettingsTabButton', () => {
    const original = Element.prototype.scrollIntoView;

    afterEach(() => {
        cleanup();
        Element.prototype.scrollIntoView = original;
    });

    it('scrolls the open tab into view so it shows in a sideways-scrolling strip', () => {
        const scrollIntoView = vi.fn();

        Element.prototype.scrollIntoView = scrollIntoView;

        const { rerender } = render(
            <SettingsTabButton active={false} onClick={vi.fn()}>
                Referrals
            </SettingsTabButton>
        );

        expect(scrollIntoView).not.toHaveBeenCalled();

        rerender(
            <SettingsTabButton active onClick={vi.fn()}>
                Referrals
            </SettingsTabButton>
        );

        expect(scrollIntoView).toHaveBeenCalledTimes(1);
        expect(scrollIntoView).toHaveBeenCalledWith({ block: 'nearest', inline: 'nearest' });
        expect(screen.getByRole('button', { name: 'Referrals' }).className).toContain('border-primary');
    });
});
