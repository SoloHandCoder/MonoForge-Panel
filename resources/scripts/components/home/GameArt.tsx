/* oxlint-disable rules/design-tokens -- the game art is pixel art with its own fixed palette */
import { cn } from '@/lib/cn';

type Pixel = readonly [x: number, y: number, color: string];

/** Draws a list of pixels as one SVG; `size` is the grid's width and height in pixels. */
function PixelArt({ pixels, size, className }: { pixels: readonly Pixel[]; size: number; className?: string }) {
    return (
        <svg
            xmlns='http://www.w3.org/2000/svg'
            viewBox={`0 0 ${size} ${size}`}
            aria-hidden='true'
            className={cn('[shape-rendering:crispEdges]', className)}
        >
            {pixels.map(([x, y, color]) => (
                <rect key={`${x}-${y}`} x={x} y={y} width='1' height='1' fill={color} />
            ))}
        </svg>
    );
}

const GRASS = ['#5fbf3a', '#4ea52f', '#73d14b'] as const;
const DIRT = ['#8a5a32', '#77492a', '#9c6b3e'] as const;

/** A grass-topped block, the classic block-game cube, drawn as an 8x8 front face with a grass lip. */
function blockPixels(): Pixel[] {
    const pixels: Pixel[] = [];

    for (let y = 0; y < 12; y++) {
        for (let x = 0; x < 12; x++) {
            const noise = (x * 7 + y * 13) % 3;
            const grassDepth = y < 3 || (y === 3 && (x * 5) % 3 !== 0);

            pixels.push([x + 2, y + 2, grassDepth ? GRASS[noise] : DIRT[noise]]);
        }
    }

    return pixels;
}

/** A pixel tree on a patch of ground with a sword stuck beside it, for 2D sandbox adventures. */
function treePixels(): Pixel[] {
    const pixels: Pixel[] = [];
    const leaves = ['#3f9a3a', '#2f7d2e', '#58b84a'] as const;

    // Canopy: a rough circle.
    for (let y = 1; y < 8; y++) {
        for (let x = 1; x < 10; x++) {
            const dx = x - 5;
            const dy = y - 4;

            if (dx * dx + dy * dy * 1.4 <= 17) {
                pixels.push([x, y, leaves[(x * 3 + y * 5) % 3]]);
            }
        }
    }

    // Trunk.
    for (let y = 8; y < 13; y++) {
        pixels.push([4, y, '#7a4b28'], [5, y, '#8f5b31']);
    }

    // Sword: blade, guard and grip.
    for (let y = 4; y < 10; y++) {
        pixels.push([12, y, y % 2 === 0 ? '#dfe6ee' : '#b9c3d2']);
    }

    pixels.push(
        [11, 10, '#ffb02e'],
        [12, 10, '#ffc24a'],
        [13, 10, '#ffb02e'],
        [12, 11, '#6b4a2b'],
        [12, 12, '#6b4a2b']
    );
    // Ground.
    for (let x = 0; x < 16; x++) {
        pixels.push([x, 13, GRASS[x % 3]], [x, 14, DIRT[x % 3]], [x, 15, DIRT[(x + 1) % 3]]);
    }

    return pixels;
}

const BLOCK = blockPixels();
const TREE = treePixels();

export function BlockArt({ className }: { className?: string }) {
    return <PixelArt pixels={BLOCK} size={16} className={className} />;
}

export function TreeArt({ className }: { className?: string }) {
    return <PixelArt pixels={TREE} size={16} className={className} />;
}
