import { MODPACK_STEPS, modpackRunning, type ModpackProgress } from '@/api/server/plugins/queries';
import { Alert } from '@/components/elements/alert';
import ProgressBar from '@/components/elements/ProgressBar';

/** Rough share of the install each step stands for, so the bar moves steadily. */
const STEP_PERCENT = new Map<string, number>([
    ['queued', 0],
    ['starting', 2],
    ['backup', 5],
    ['download', 15],
    ['extract', 20],
    ['files', 25],
    ['overrides', 85],
    ['install', 90],
    ['cleanup', 97],
    ['done', 100],
]);

export function modpackPercent(progress: ModpackProgress): number {
    if (progress.status === 'completed') {
        return 100;
    }

    if (progress.step === 'files' && progress.files_total > 0) {
        return Math.round(25 + (60 * progress.files_done) / progress.files_total);
    }

    return STEP_PERCENT.get(progress.step) ?? 0;
}

/** The latest modpack install of the server: its progress while it runs, then how it ended. */
export default function ModpackProgressPanel({ progress }: { progress: ModpackProgress | undefined }) {
    if (!progress || progress.status === 'idle') {
        return null;
    }

    const title = `${progress.project_title} ${progress.version_name}`;

    if (progress.status === 'failed') {
        return (
            <Alert type='danger' title={`${title} was not installed`}>
                {progress.message ?? 'The install stopped.'}
            </Alert>
        );
    }

    if (progress.status === 'completed') {
        return (
            <Alert type='success' title={`${title} is installed`}>
                {progress.backup === 'created' ? 'A backup was made first. ' : ''}Start the server to play it.
            </Alert>
        );
    }

    const step = MODPACK_STEPS.get(progress.step) ?? progress.step;

    return (
        <section aria-label='Modpack install' className='rounded-lg bg-card p-4 shadow-sm'>
            <p className='font-medium text-foreground'>Installing {title}</p>
            <p className='mt-1 text-sm text-muted-foreground' role='status' aria-live='polite'>
                {step}
                {progress.step === 'files' && progress.files_total > 0
                    ? ` (${progress.files_done} of ${progress.files_total})`
                    : ''}
                {modpackRunning(progress) ? '…' : ''}
            </p>
            <ProgressBar
                value={modpackPercent(progress)}
                tone='accent'
                label={`Installing ${title}`}
                className='mt-3'
            />
            <p className='mt-2 text-xs text-muted-foreground'>You can leave this page; the install keeps running.</p>
        </section>
    );
}
