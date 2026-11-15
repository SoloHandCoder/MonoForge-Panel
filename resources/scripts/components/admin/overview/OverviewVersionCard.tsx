import { useState, type ReactNode } from 'react';
import type { AdminVersion } from '@/api/admin/version/queries';
import {
    isUpdateInProgress,
    useAdminUpdateStatus,
    useCheckAdminUpdates,
    useApplyAdminUpdate,
    type AdminUpdateStatus,
} from '@/api/admin/updates/queries';
import { Alert } from '@/components/elements/alert';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import { REPOSITORY_URL } from './repository';

const STEP_LABELS = {
    downloading: 'Downloading the new build',
    extracting: 'Unpacking the new build',
    maintenance: 'Entering maintenance mode',
    backing_up: 'Backing up the code and database',
    installing: 'Copying the new build into place',
    migrating: 'Running database migrations',
    finishing: 'Clearing caches and restarting the queue',
    restoring: 'Restoring the previous code',
} as const;

const POLL_INTERVAL = 3000;

const stepLabel = (step: string | null): string => {
    const label = Object.entries(STEP_LABELS).find(([key]) => key === step);

    return label ? label[1] : 'Working';
};

const BuildCode = ({ children }: { children: string }) => <code className='font-mono'>{children}</code>;

const ExternalLink = ({ href, className, children }: { href: string; className: string; children: ReactNode }) => (
    <a href={href} target='_blank' rel='noopener noreferrer' className={className}>
        {children}
    </a>
);

/** Looks up the latest build on GitHub now; the result is shown as a toast. */
function CheckNowButton() {
    const check = useCheckAdminUpdates();

    return (
        <Button size='small' isSecondary className='mt-3' isLoading={check.isPending} onClick={() => check.mutate({})}>
            {check.isPending ? 'Checking…' : 'Check now'}
        </Button>
    );
}

function UpdateButton({ status }: { status: AdminUpdateStatus }) {
    const applyUpdate = useApplyAdminUpdate();

    return (
        <Dialog.ConfirmTrigger
            title='Install the MonoForge update?'
            confirm='Update now'
            pending={applyUpdate.isPending}
            trigger={({ onClick }) => (
                <Button size='small' className='mt-3' onClick={onClick}>
                    Update now
                </Button>
            )}
            onConfirmed={(_event, close) => applyUpdate.mutateAsync({}).then(close)}
        >
            The panel will install build <BuildCode>{status.latest?.version ?? ''}</BuildCode> within a minute. It goes
            into maintenance mode for a short while, so players will briefly see a maintenance page. The current code
            and database are backed up first and the previous code is restored automatically if anything fails.
        </Dialog.ConfirmTrigger>
    );
}

function LastFailure({ status }: { status: AdminUpdateStatus }) {
    if (status.status.state !== 'failed' || !status.status.error) {
        return null;
    }

    return (
        <p className='mt-2 text-sm'>
            The last update failed: <span className='break-words'>{status.status.error}</span>
        </p>
    );
}

function ProgressAlert({ status }: { status: AdminUpdateStatus }) {
    const waiting = status.status.state === 'queued';

    return (
        <Alert type='info' title={`Updating MonoForge to build ${status.status.to ?? ''}`} className='mb-6'>
            <span aria-live='polite'>
                {waiting
                    ? 'Waiting for the panel scheduler to start the update (it runs every minute).'
                    : `${stepLabel(status.status.step)}…`}{' '}
                The panel may be unavailable for a moment while it is in maintenance mode.
            </span>
        </Alert>
    );
}

function UpdatedAlert({ status }: { status: AdminUpdateStatus }) {
    return (
        <Alert type='success' title={`MonoForge updated to build ${status.status.to ?? ''}`} className='mb-6'>
            Reload the page to use the new build.
            <div>
                <Button size='small' className='mt-3' onClick={() => window.location.reload()}>
                    Reload
                </Button>
            </div>
        </Alert>
    );
}

function AvailableAlert({
    status,
    latest,
}: {
    status: AdminUpdateStatus;
    latest: NonNullable<AdminUpdateStatus['latest']>;
}) {
    const failed = status.status.state === 'failed';

    return (
        <Alert type={failed ? 'danger' : 'warning'} title={`Update available (${latest.version})`} className='mb-6'>
            You are running MonoForge build <BuildCode>{status.current_version}</BuildCode>; build{' '}
            <ExternalLink href={latest.url} className='font-mono underline transition-colors hover:text-accent'>
                {latest.version}
            </ExternalLink>{' '}
            is available. See the{' '}
            <ExternalLink href={`${REPOSITORY_URL}/commits`} className='underline transition-colors hover:text-accent'>
                recent changes
            </ExternalLink>
            .
            <LastFailure status={status} />
            <div className='flex flex-wrap gap-2'>
                <UpdateButton status={status} />
                <CheckNowButton />
            </div>
        </Alert>
    );
}

function CurrentAlert({ status }: { status: AdminUpdateStatus }) {
    if (status.mode === 'off') {
        return (
            <Alert type='info' title={`MonoForge build ${status.current_version}`} className='mb-6'>
                Update checks are turned off. Turn them on in the panel settings to hear about new builds.
                <LastFailure status={status} />
            </Alert>
        );
    }

    if (!status.latest || status.check === 'unknown') {
        return (
            <Alert
                type='info'
                title={`MonoForge build ${status.current_version} — latest build unknown`}
                className='mb-6'
            >
                {status.latest
                    ? `Build ${status.latest.version} is out, but GitHub could not confirm it is newer than this one, so it is not offered. It will be checked again shortly.`
                    : 'The latest build could not be fetched from GitHub. It will be checked again shortly.'}
                <LastFailure status={status} />
                <div>
                    <CheckNowButton />
                </div>
            </Alert>
        );
    }

    return (
        <Alert type='success' title={`MonoForge build ${status.current_version} — up to date`} className='mb-6'>
            {status.mode === 'auto'
                ? 'New builds are installed automatically every hour.'
                : 'The panel checks GitHub for new builds every day.'}
            <LastFailure status={status} />
            <div>
                <CheckNowButton />
            </div>
        </Alert>
    );
}

function VersionFallback({ version }: { version: AdminVersion }) {
    return (
        <Alert
            type={version.is_latest ? 'success' : 'warning'}
            title={version.is_latest ? 'MonoForge is up to date.' : `Update available (${version.latest})`}
            className='mb-6'
        >
            You are running MonoForge build <BuildCode>{version.current}</BuildCode>.
        </Alert>
    );
}

/**
 * The MonoForge build the panel runs, whether a newer one is out, and the progress of an update.
 */
export default function OverviewVersionCard({ version }: { version: AdminVersion }) {
    // Polls while an update is queued or running; requests that fail during maintenance keep the last status.
    const { data: status } = useAdminUpdateStatus({
        refetchInterval: (query) => (isUpdateInProgress(query.state.data?.status.state) ? POLL_INTERVAL : false),
    });
    // Set once this page has seen an update in progress, so finishing it can offer a reload.
    const [watched, setWatched] = useState(false);
    const inProgress = isUpdateInProgress(status?.status.state);

    if (inProgress && !watched) {
        setWatched(true);
    }

    if (!status) {
        return <VersionFallback version={version} />;
    }

    if (inProgress) {
        return <ProgressAlert status={status} />;
    }

    if (watched && status.status.state === 'success') {
        return <UpdatedAlert status={status} />;
    }

    if (status.mode !== 'off' && status.update_available && status.latest) {
        return <AvailableAlert status={status} latest={status.latest} />;
    }

    return <CurrentAlert status={status} />;
}
