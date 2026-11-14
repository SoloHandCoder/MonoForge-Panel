/** @vitest-environment jsdom */
/* oxlint-disable rules/classic-theme-imports -- the spec also covers the shared PageFooter */
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import PageFooter from '@/components/elements/PageFooter';
import AuthCard from '@/themes/classic/views/auth/AuthCard';
import FloatingBlocks from './FloatingBlocks';
import MonoForgeMark from './MonoForgeMark';

afterEach(cleanup);

describe('MonoForgeMark', () => {
    it('is an image named MonoForge by default', () => {
        render(<MonoForgeMark />);
        expect(screen.getByRole('img', { name: 'MonoForge' })).toBeTruthy();
    });

    it('accepts a custom title and class', () => {
        render(<MonoForgeMark title='Forge' className='w-8' />);
        expect(screen.getByRole('img', { name: 'Forge' }).getAttribute('class')).toContain('w-8');
    });

    it('gives each instance its own gradient ids', () => {
        const { container } = render(
            <>
                <MonoForgeMark />
                <MonoForgeMark />
            </>
        );
        const ids = [...container.querySelectorAll('linearGradient, radialGradient')].map((g) => g.id);

        expect(ids.length).toBeGreaterThan(0);
        expect(new Set(ids).size).toBe(ids.length);
    });
});

describe('FloatingBlocks', () => {
    it('renders count blocks in an aria-hidden layer', () => {
        const { container } = render(<FloatingBlocks count={5} />);
        const layer = container.firstElementChild as HTMLElement;

        expect(layer.getAttribute('aria-hidden')).toBe('true');
        expect(layer.children).toHaveLength(5);
    });

    it('defaults to 14 blocks and is deterministic', () => {
        const a = render(<FloatingBlocks />).container.innerHTML;

        cleanup();
        const b = render(<FloatingBlocks />).container.innerHTML;

        expect(a).toBe(b);
        cleanup();
        expect(render(<FloatingBlocks />).container.firstElementChild?.children).toHaveLength(14);
    });
});

describe('credits', () => {
    it('PageFooter carries no Pterodactyl branding', () => {
        const { container } = render(<PageFooter />);

        expect(container.textContent).not.toMatch(/Pterodactyl/);
        expect(container.textContent).toMatch(/MonoForge/);
    });

    it('AuthCard credits MonoForge only', () => {
        const { container } = render(
            <AuthCard title='Login' submit={() => {}}>
                <span>body</span>
            </AuthCard>
        );

        expect(container.textContent).not.toMatch(/Pterodactyl/);
        expect(container.textContent).toMatch(/MonoForge/);
    });
});
