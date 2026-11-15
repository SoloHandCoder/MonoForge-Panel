import { Coins } from 'lucide-react';
import ShopSlotPlaceholder from '@/components/admin/shop/ShopSlotPlaceholder';

/**
 * Slot for phase 3 Task P: the "Token packs & Tebex" sub-tab of the admin Shop tab. Task P replaces
 * this file with its Tebex settings and token pack editor; the route (/panel/shop/token-packs) and
 * the tab button already exist.
 */
export default function TokenPacksTab() {
    return (
        <ShopSlotPlaceholder
            icon={Coins}
            title='Token packs & Tebex'
            description='The token packs players buy through Tebex, and the Tebex keys, are set up here.'
        />
    );
}
