/* oxlint-disable rules/design-tokens -- the welcome area uses the brand's fixed violet gradient */
import { bytesToString, cn } from '@/sdk';
import type { ServerListModel } from '@/sdk/theme';
import FloatingBlocks from '../brand/FloatingBlocks';

export interface WelcomeStats {
    online: number;
    total: number;
    /** Memory of the servers with live stats; null until one has them. A null limit means unlimited. */
    memory: { used: number; limit: number | null } | null;
}

/** What the tiles show: servers running on the page, and the memory of every server with live stats. */
export function welcomeStats({ servers, totals }: ServerListModel): WelcomeStats {
    const ready = servers.flatMap(({ state }) => (state.kind === 'ready' ? [state.memory] : []));
    const used = ready.reduce((sum, { value }) => sum + value, 0);
    // One server without a limit makes the total unlimited.
    const limit = ready.some((each) => each.limit === 0) ? null : ready.reduce((sum, each) => sum + each.limit, 0);

    return {
        online: totals.runningOnPage,
        total: servers.length,
        memory: ready.length > 0 ? { used, limit } : null,
    };
}

const isLoaded = (model: ServerListModel) => !model.loading && model.pagination !== null;

/** The greeting after the username: only what the loaded list shows to be true. */
function greeting(model: ServerListModel, { online, total }: WelcomeStats): string {
    if (model.scope === 'others') {
        return ", here are other users' servers.";
    }

    if (!isLoaded(model) || online === 0) {
        return ', welcome back.';
    }

    return online === total ? ', your worlds are running.' : `, ${online} of your ${total} servers are online.`;
}

function Tile({ label, className, children }: { label: string; className?: string; children: React.ReactNode }) {
    return (
        <div className='min-w-40 rounded-[14px] border border-white/10 bg-white/5 px-4 py-3 backdrop-blur-sm'>
            <p className={cn('text-2xl font-semibold tabular-nums', className)}>{children}</p>
            <p className='text-xs text-muted-foreground'>{label}</p>
        </div>
    );
}

export default function Welcome({ model }: { model: ServerListModel }) {
    const stats = welcomeStats(model);
    const { online, total, memory } = stats;

    return (
        <section
            data-part='welcome'
            className='relative mb-6 overflow-hidden rounded-[18px] border border-border bg-[radial-gradient(120%_120%_at_80%_0%,#241a44,#0e0d15_60%)] px-6 py-7'
        >
            <FloatingBlocks />
            <div className='relative'>
                <p className='text-xs font-medium uppercase tracking-widest text-[#a78bfa]'>Welcome back</p>
                {/* Not a heading: the page's one h1 is the "Servers" heading below. */}
                <p className='mt-1 text-2xl font-semibold text-foreground sm:text-3xl'>
                    Hey{' '}
                    <span className='animate-pulse bg-linear-to-r from-[#a78bfa] via-[#e9d5ff] to-[#7c3aed] bg-clip-text text-transparent [animation-duration:4s]'>
                        {model.username}
                    </span>
                    {greeting(model, stats)}
                </p>
                {model.scope !== 'others' && isLoaded(model) && (
                    <div className='mt-5 flex flex-wrap gap-3'>
                        <Tile label='servers online' className='text-[var(--status-online)]'>
                            {online} / {total}
                        </Tile>
                        {memory && (
                            <Tile label='memory in use'>
                                {bytesToString(memory.used)} /{' '}
                                {memory.limit === null ? '∞' : bytesToString(memory.limit)}
                            </Tile>
                        )}
                    </div>
                )}
            </div>
        </section>
    );
}
