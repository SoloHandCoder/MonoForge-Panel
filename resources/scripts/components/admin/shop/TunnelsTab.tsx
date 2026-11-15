import { Waypoints } from 'lucide-react';
import ShopSlotPlaceholder from '@/components/admin/shop/ShopSlotPlaceholder';

/**
 * Slot for phase 3 Task V: the "Tunnels" (playit.gg) sub-tab of the admin Shop tab. Task V replaces
 * this file with its tunnel status list and subdomain settings; the route (/panel/shop/tunnels) and
 * the tab button already exist.
 */
export default function TunnelsTab() {
    return (
        <ShopSlotPlaceholder
            icon={Waypoints}
            title='Tunnels'
            description='The playit.gg tunnel of every server, and the custom subdomain settings, are managed here.'
        />
    );
}
