/** @vitest-environment jsdom */

import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { AdminVersion } from '@/api/admin/version/queries';
import OverviewVersionCard from './OverviewVersionCard';

afterEach(cleanup);

const version: AdminVersion = {
    current: 'canary',
    latest: '1.12.0',
    is_latest: true,
    daemon: '1.11.13',
    discord: 'https://discord.gg/pterodactyl',
    donations: 'https://github.com/sponsors/pterodactyl',
};

describe('OverviewVersionCard', () => {
    it('renders the current version as a success alert', () => {
        render(<OverviewVersionCard version={version} />);

        expect(screen.getByRole('alert')).toHaveTextContent('Your panel is up-to-date.');
        expect(screen.getByRole('alert')).toHaveTextContent('You are running version canary.');
    });

    it('renders an available update as a warning alert', () => {
        render(<OverviewVersionCard version={{ ...version, is_latest: false }} />);

        expect(screen.getByRole('alert')).toHaveTextContent('A panel update is available.');
        expect(screen.getByRole('link', { name: '1.12.0' })).toHaveAttribute(
            'href',
            'https://github.com/SoloHandCoder/MonoForge-Panel/releases'
        );
        expect(screen.getByRole('link', { name: 'recent changes' })).toHaveAttribute(
            'href',
            'https://github.com/SoloHandCoder/MonoForge-Panel/commits'
        );
        for (const link of screen.getAllByRole('link')) {
            expect(link.getAttribute('href')).not.toMatch(/pterodactyl/i);
        }
    });
});
