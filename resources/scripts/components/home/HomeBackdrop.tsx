/* oxlint-disable rules/design-tokens -- the backdrop uses the brand's fixed violet and ember tints, like the sign-in page */
import type { CSSProperties } from 'react';
import { cn } from '@/lib/cn';

/** A small seeded PRNG, so every render (and every test) draws the same layout. */
function mulberry32(seed: number) {
    let a = seed;

    return () => {
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);

        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

type Style = CSSProperties & Record<`--${string}`, string>;

const BLOCK_TONES = [
    'border-[rgba(196,181,253,.45)] bg-linear-to-br from-[rgba(139,92,246,.45)] to-[rgba(139,92,246,.06)]',
    'border-[rgba(167,139,250,.35)] bg-linear-to-br from-[rgba(124,58,237,.35)] to-[rgba(76,29,149,.05)]',
    'border-[rgba(255,194,74,.45)] bg-linear-to-br from-[rgba(255,176,46,.35)] to-[rgba(255,90,20,.04)]',
] as const;

function blocks(count: number) {
    const random = mulberry32(0x666f7267);

    return Array.from({ length: count }, (_, index) => {
        const duration = 9 + random() * 13;
        const size = Math.round(8 + random() * 34);
        // One block in seven glows like an ember from the logo.
        const shade = random() > 0.5 ? 1 : 0;
        const tone = index % 7 === 3 ? 2 : shade;

        return {
            key: index,
            tone: BLOCK_TONES[tone],
            style: {
                left: `${(random() * 98).toFixed(1)}%`,
                width: `${size}px`,
                height: `${size}px`,
                animationDuration: `${duration.toFixed(1)}s`,
                animationDelay: `-${(random() * duration).toFixed(1)}s`,
                '--home-drift': `${Math.round((random() - 0.5) * 160)}px`,
                '--home-turn': `${Math.round(180 + random() * 270) * (random() > 0.5 ? 1 : -1)}deg`,
                '--home-block-opacity': (0.5 + random() * 0.5).toFixed(2),
            } satisfies Style,
        };
    });
}

function sparks(count: number) {
    const random = mulberry32(0x656d6272);

    return Array.from({ length: count }, (_, index) => {
        const duration = 2.4 + random() * 2.6;

        return {
            key: index,
            style: {
                animationDuration: `${duration.toFixed(1)}s`,
                animationDelay: `-${(random() * duration).toFixed(1)}s`,
                left: `${(35 + random() * 30).toFixed(1)}%`,
                '--home-drift': `${Math.round((random() - 0.5) * 140)}px`,
            } satisfies Style,
        };
    });
}

/** Sparks rising from the anvil's ember: placed inside the logo's box, starting at its top third. */
export function Sparks({ count = 14 }: { count?: number }) {
    return (
        <div aria-hidden='true' className='pointer-events-none absolute inset-x-0 top-[30%] h-0'>
            {sparks(count).map(({ key, style }) => (
                <span
                    key={key}
                    className='home-spark absolute size-1 rounded-full bg-[#ffc24a] opacity-0 shadow-[0_0_8px_2px_rgba(255,138,31,.7)]'
                    style={style}
                />
            ))}
        </div>
    );
}

/**
 * The homepage backdrop: the sign-in page's rising violet blocks, livelier (more of them, tumbling,
 * a few ember-coloured) and slowly drifting glows. Decorative only and hidden
 * from assistive tech; with reduced motion only the still glows remain.
 */
export default function HomeBackdrop({ count = 34, className }: { count?: number; className?: string }) {
    return (
        <div aria-hidden='true' className={cn('pointer-events-none absolute inset-0 overflow-hidden', className)}>
            <div className='home-orb absolute -top-40 -left-32 size-[34rem] rounded-full bg-[radial-gradient(circle,rgba(139,92,246,.35),transparent_65%)]' />
            <div
                className='home-orb absolute top-1/3 -right-40 size-[30rem] rounded-full bg-[radial-gradient(circle,rgba(91,33,182,.4),transparent_65%)]'
                style={{ animationDelay: '-8s', animationDuration: '28s' }}
            />
            <div
                className='home-orb absolute -bottom-48 left-1/4 size-[26rem] rounded-full bg-[radial-gradient(circle,rgba(255,138,31,.16),transparent_65%)]'
                style={{ animationDelay: '-15s', animationDuration: '32s' }}
            />
            <div className='home-pointer-glow absolute inset-0' />
            {blocks(count).map(({ key, tone, style }) => (
                <span
                    key={key}
                    className={cn('home-block absolute -bottom-12 rounded-sm border opacity-0', tone)}
                    style={style}
                />
            ))}
        </div>
    );
}
