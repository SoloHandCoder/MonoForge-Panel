import type { AdminVersion } from '@/api/admin/version/queries';
import { Alert } from '@/components/elements/alert';
import { REPOSITORY_URL } from './repository';

export default function OverviewVersionCard({ version }: { version: AdminVersion }) {
    return (
        <Alert
            type={version.is_latest ? 'success' : 'warning'}
            title={version.is_latest ? 'Your panel is up-to-date.' : 'A panel update is available.'}
            className='mb-6'
        >
            {version.is_latest ? (
                <>
                    You are running version <code className='font-mono'>{version.current}</code>.
                </>
            ) : (
                <>
                    You are running version <code className='font-mono'>{version.current}</code>; the latest available
                    is{' '}
                    <a
                        href={`${REPOSITORY_URL}/releases`}
                        target='_blank'
                        rel='noreferrer'
                        className='font-mono underline transition-colors hover:text-accent'
                    >
                        {version.latest}
                    </a>
                    . See the{' '}
                    <a
                        href={`${REPOSITORY_URL}/commits`}
                        target='_blank'
                        rel='noreferrer'
                        className='underline transition-colors hover:text-accent'
                    >
                        recent changes
                    </a>{' '}
                    before you upgrade.
                </>
            )}
        </Alert>
    );
}
