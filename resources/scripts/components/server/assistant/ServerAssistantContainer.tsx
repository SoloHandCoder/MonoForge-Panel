import { serverAssistantScope, useAssistantStatus, type AssistantStatus } from '@/api/assistant';
import { useCurrentServerUuid } from '@/api/server/queries';
import AssistantChat from '@/components/assistant/AssistantChat';
import { AssistantUsageMeter, AssistantWarningBanner } from '@/components/assistant/AssistantUsage';
import { Alert } from '@/components/elements/alert';
import PageHeading from '@/components/elements/PageHeading';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Spinner from '@/components/elements/Spinner';
import { useAssistantSearch } from '@/router/pageSearch';

const suggestions = [
    'Why did my server crash?',
    'Show me the last console lines',
    'What uses the most disk space?',
    'Explain my startup settings',
] as const;

/** Why the composer is off, or null while the user may chat. */
export const assistantBlockedReason = (status: AssistantStatus | undefined): string | null => {
    if (!status) {
        return null;
    }

    if (!status.enabled || !status.server_enabled) {
        return 'The assistant is turned off.';
    }

    return status.warning === 'no_balance' ? 'Buy tokens to keep chatting.' : null;
};

/** The server page's AI assistant, at /server/$id/assistant. */
export default function ServerAssistantContainer() {
    const uuid = useCurrentServerUuid();
    const status = useAssistantStatus();
    const { conversation } = useAssistantSearch();

    return (
        <ServerContentBlock title='Assistant'>
            <PageHeading
                title='Assistant'
                description='Ask about your server. It can read the console and files, and asks before it changes anything.'
            />
            <div className='mt-6 space-y-4'>
                {status.data && (!status.data.enabled || !status.data.server_enabled) && (
                    <Alert type='info' title='The assistant is turned off'>
                        An administrator has turned off the server assistant.
                    </Alert>
                )}
                <AssistantWarningBanner warning={status.data?.warning ?? null} />
                {uuid ? (
                    <AssistantChat
                        key={conversation ?? 'new'}
                        initialConversation={conversation ?? null}
                        scope={serverAssistantScope(uuid)}
                        suggestions={suggestions}
                        blockedReason={assistantBlockedReason(status.data)}
                        sidebar={status.data && <AssistantUsageMeter status={status.data} />}
                    />
                ) : (
                    <Spinner size='large' centered />
                )}
            </div>
        </ServerContentBlock>
    );
}
