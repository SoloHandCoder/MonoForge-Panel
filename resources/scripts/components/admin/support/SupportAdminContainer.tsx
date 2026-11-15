import { useLocation, useNavigate } from '@tanstack/react-router';
import AdminContentBlock from '@/components/admin/AdminContentBlock';
import SettingsTabButton from '@/components/admin/settings/SettingsTabButton';
import CannedRepliesTab from '@/components/admin/support/CannedRepliesTab';
import SupportCategoriesTab from '@/components/admin/support/SupportCategoriesTab';
import { supportTabFromPath, supportTabs, type SupportTab } from '@/components/admin/support/supportTabs';
import TicketsTab from '@/components/admin/support/TicketsTab';

const tabContent = {
    tickets: () => <TicketsTab />,
    'canned-replies': () => <CannedRepliesTab />,
    categories: () => <SupportCategoriesTab />,
} satisfies Record<SupportTab, () => React.ReactNode>;

/** Admin → Support: the ticket queue, the canned replies and the ticket categories. */
export default function SupportAdminContainer() {
    const navigate = useNavigate();
    const location = useLocation();
    const tab = supportTabFromPath(location.pathname);

    return (
        <AdminContentBlock
            title='Admin · Support'
            heading='Support'
            description='Answer player tickets, keep notes only staff see, and manage canned replies and categories.'
        >
            <div
                className='mb-6 flex overflow-x-auto border-b border-border'
                role='tablist'
                aria-label='Support sections'
            >
                {supportTabs.map((item) => (
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
