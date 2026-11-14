/* oxlint-disable rules/design-tokens -- the blocks use fixed violet tints from the brand */
import type { CSSProperties } from 'react';
import { cn } from '@/sdk';

interface Props {
    count?: number;
    className?: string;
}

/** A small seeded PRNG so every render (and every test) gets the same layout. */
function mulberry32(seed: number) {
    let a = seed;

    return () => {
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);

        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/**
 * See-through violet squares that rise and turn 180 degrees behind the content. Decorative only: hidden from
 * assistive tech, and the global reduced-motion rule stops the animation (the blocks then stay hidden).
 */
export default function FloatingBlocks({ count = 14, className }: Props) {
    const random = mulberry32(0x6d6f6e6f);
    const blocks = Array.from({ length: count }, (_, index) => {
        const duration = 8 + random() * 10;
        const size = `${Math.round(10 + random() * 26)}px`;

        return {
            key: index,
            style: {
                left: `${(random() * 96).toFixed(1)}%`,
                width: size,
                height: size,
                animationDuration: `${duration.toFixed(1)}s`,
                animationDelay: `-${(random() * duration).toFixed(1)}s`,
            } satisfies CSSProperties,
        };
    });

    return (
        <div
            aria-hidden='true'
            className={cn('pointer-events-none absolute inset-0 overflow-hidden motion-reduce:hidden', className)}
        >
            {blocks.map(({ key, style }) => (
                <span
                    key={key}
                    className='absolute -bottom-10 rounded-sm border border-[rgba(196,181,253,.35)] bg-linear-to-br from-[rgba(139,92,246,.35)] to-[rgba(139,92,246,.05)] opacity-0 animate-float-up'
                    style={style}
                />
            ))}
        </div>
    );
}
