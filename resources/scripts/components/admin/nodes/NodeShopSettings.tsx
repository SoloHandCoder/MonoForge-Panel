import { useAdminShopNodes, useSetShopNodeEnabled } from '@/api/admin/shop/queries';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import Switch from '@/components/ui/Switch';

/**
 * The node's "Available in the shop" switch, the same one as in Admin → Shop → Nodes. Hidden for
 * administrators who may not read the shop.
 */
export default function NodeShopSettings({ nodeId }: { nodeId: number }) {
    const { data } = useAdminShopNodes();
    const setEnabled = useSetShopNodeEnabled();
    const node = data?.find((item) => item.id === nodeId);

    if (!node) {
        return null;
    }

    return (
        <TitledGreyBox title='Shop' className='mt-6'>
            <Switch
                checked={node.shop_enabled}
                disabled={setEnabled.isPending}
                label='Available in the shop'
                description={`The store sells new servers on this node only while this is on and the node is public. ${node.shop_servers} shop ${node.shop_servers === 1 ? 'server runs' : 'servers run'} here; they keep running when it is off.`}
                onChange={(enabled) => setEnabled.mutate({ id: node.id, enabled })}
            />
        </TitledGreyBox>
    );
}
