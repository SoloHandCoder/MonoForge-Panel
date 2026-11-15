import { useLocation, useNavigate } from '@tanstack/react-router';
import { adminAssistantScope, useAssistantProviders, useAssistantSettings } from '@/api/assistant';
import { Alert } from '@/components/elements/alert';
import AssistantChat from '@/components/assistant/AssistantChat';
import AdminContentBlock from '@/components/admin/AdminContentBlock';
import { assistantTabFromPath, assistantTabs, type AssistantTab } from '@/components/admin/assistant/assistantTabs';
import ProvidersTab from '@/components/admin/assistant/ProvidersTab';
import SettingsTab from '@/components/admin/assistant/SettingsTab';
import UsageTab from '@/components/admin/assistant/UsageTab';
import SettingsTabButton from '@/components/admin/settings/SettingsTabButton';

const suggestions = [
    'Which servers are suspended?',
    'How busy are the nodes right now?',
    'Summarise the shop this month',
    'Show recent failed logins',
] as const;

function ChatTab() {
    const { data: settings } = useAssistantSettings();
    const { data: providers } = useAssistantProviders();
    const off = !!settings && (!settings.enabled || !settings.admin_enabled);
    const noProvider = !!providers && !providers.data.some((provider) => provider.enabled);
    let blockedReason: string | null = null;

    if (off) {
        blockedReason = 'The admin assistant is turned off.';
    } else if (noProvider) {
        blockedReason = 'Add an AI provider first.';
    }

    return (
        <div className='space-y-4'>
            {off && (
                <Alert type='info' title='The admin assistant is turned off'>
                    Turn it on in the Settings tab to chat.
                </Alert>
            )}
            {!off && noProvider && (
                <Alert type='warning' title='No AI provider is set up'>
                    Add and enable a provider in the Providers tab before chatting.
                </Alert>
            )}
            <AssistantChat scope={adminAssistantScope} suggestions={suggestions} blockedReason={blockedReason} />
        </div>
    );
}

const tabContent = {
    chat: () => <ChatTab />,
    providers: () => <ProvidersTab />,
    settings: () => <SettingsTab />,
    usage: () => <UsageTab />,
} satisfies Record<AssistantTab, () => React.ReactNode>;

/** Admin → AI Assistant: the admin chat, the AI providers, the assistant settings and usage. */
export default function AssistantAdminContainer() {
    const navigate = useNavigate();
    const location = useLocation();
    const tab = assistantTabFromPath(location.pathname);

    return (
        <AdminContentBlock
            title='Admin · AI Assistant'
            heading='AI Assistant'
            description='Chat with the admin assistant, and set up the providers and limits both assistants use.'
        >
            <div
                className='mb-6 flex overflow-x-auto border-b border-border'
                role='tablist'
                aria-label='Assistant sections'
            >
                {assistantTabs.map((item) => (
                    <SettingsTabButton
                        key={item.key}
                        active={tab === item.key}
                        onClick={() => navigate({ to: item.path, replace: true, viewTransition: false })}
                    >
                        {item.label}
                    </SettingsTabButton>
                ))}
            </div>
            {tabContent[tab]()}
        </AdminContentBlock>
    );
}
