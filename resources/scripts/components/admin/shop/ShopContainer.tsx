import { useLocation, useNavigate } from '@tanstack/react-router';
import AdminContentBlock from '@/components/admin/AdminContentBlock';
import SettingsTabButton from '@/components/admin/settings/SettingsTabButton';
import AddonsTab from '@/components/admin/shop/addons/AddonsTab';
import CouponsTab from '@/components/admin/shop/coupons/CouponsTab';
import LimitsTab from '@/components/admin/shop/limits/LimitsTab';
import MultipliersTab from '@/components/admin/shop/multipliers/MultipliersTab';
import NodesTab from '@/components/admin/shop/nodes/NodesTab';
import PeriodsTab from '@/components/admin/shop/periods/PeriodsTab';
import PlansTab from '@/components/admin/shop/plans/PlansTab';
import ReferralsTab from '@/components/admin/shop/referrals/ReferralsTab';
import { shopTabFromPath, shopTabs, type ShopTab } from '@/components/admin/shop/shopTabs';
import TebexShopTab from '@/components/admin/shop/TebexShopTab';
import TunnelsTab from '@/components/admin/shop/tunnels/TunnelsTab';

const tabContent = {
    plans: () => <PlansTab />,
    periods: () => <PeriodsTab />,
    multipliers: () => <MultipliersTab />,
    addons: () => <AddonsTab />,
    limits: () => <LimitsTab />,
    nodes: () => <NodesTab />,
    'token-packs': () => <TebexShopTab />,
    tunnels: () => <TunnelsTab />,
    coupons: () => <CouponsTab />,
    referrals: () => <ReferralsTab />,
} satisfies Record<ShopTab, () => React.ReactNode>;

/** The admin Shop tab: a sub-tab bar over the catalog, pricing and limits pages. */
export default function ShopContainer() {
    const navigate = useNavigate();
    const location = useLocation();
    const tab = shopTabFromPath(location.pathname);

    return (
        <AdminContentBlock
            title='Admin · Shop'
            heading='Shop'
            description='What players can buy with tokens, what it costs, and how much the store may sell.'
        >
            <div className='mb-6 flex overflow-x-auto border-b border-border' role='tablist' aria-label='Shop sections'>
                {shopTabs.map((item) => (
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
