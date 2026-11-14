/* oxlint-disable rules/design-tokens -- the logo keeps its own steel and ember colours on every theme */
import { useId } from 'react';

interface Props {
    className?: string;
    title?: string;
}

/**
 * The MonoForge mark: a steel anvil cut as an M with an ember above it. Taken from the logo's `mark` group
 * (no background), so it sits on any surface. Gradient ids are unique per instance.
 */
export default function MonoForgeMark({ className, title = 'MonoForge' }: Props) {
    const uid = useId().replaceAll(':', '');
    const id = (name: string) => `${name}-${uid}`;
    const ref = (name: string) => `url(#${id(name)})`;

    return (
        <svg
            xmlns='http://www.w3.org/2000/svg'
            viewBox='70 170 830 700'
            role='img'
            aria-label={title}
            className={className}
        >
            <defs>
                <linearGradient id={id('steel')} x1='0' y1='0' x2='0' y2='1'>
                    <stop offset='0' stopColor='#f2f5f9' />
                    <stop offset='0.42' stopColor='#b9c3d2' />
                    <stop offset='1' stopColor='#5d6a80' />
                </linearGradient>
                <linearGradient id={id('heat')} x1='0' y1='0' x2='0' y2='1'>
                    <stop offset='0' stopColor='#ffb02e' stopOpacity='0.95' />
                    <stop offset='1' stopColor='#ff4d12' stopOpacity='0' />
                </linearGradient>
                <linearGradient id={id('ember')} x1='0' y1='0' x2='0' y2='1'>
                    <stop offset='0' stopColor='#fff3c4' />
                    <stop offset='0.45' stopColor='#ffb42e' />
                    <stop offset='1' stopColor='#ff5a14' />
                </linearGradient>
                <radialGradient id={id('glow')}>
                    <stop offset='0' stopColor='#ff8a1f' stopOpacity='0.75' />
                    <stop offset='0.5' stopColor='#ff5a14' stopOpacity='0.22' />
                    <stop offset='1' stopColor='#ff5a14' stopOpacity='0' />
                </radialGradient>
            </defs>
            <g transform='translate(512 540) scale(1.09) translate(-512 -475)'>
                <circle cx='512' cy='270' r='270' fill={ref('glow')} />
                <path
                    fill={ref('steel')}
                    d='M 112 300 L 398 300 L 512 540 L 626 300 L 864 300 L 864 382 L 772 436 L 772 640 L 846 768 L 628 768 L 628 722 L 512 628 L 396 722 L 396 768 L 178 768 L 252 640 L 252 436 C 205 400 150 350 112 300 Z'
                />
                <path fill={ref('heat')} d='M 398 300 L 512 540 L 626 300 L 604 300 L 512 494 L 420 300 Z' />
                <path fill={ref('ember')} d='M 512 164 L 572 244 L 512 324 L 452 244 Z' />
                <g fill='#ffc24a'>
                    <rect x='622' y='172' width='30' height='30' transform='rotate(45 637 187)' />
                    <rect x='376' y='150' width='24' height='24' transform='rotate(45 388 162)' />
                </g>
            </g>
        </svg>
    );
}
