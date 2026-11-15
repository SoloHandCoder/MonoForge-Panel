import AdminContentBlock from '@/components/admin/AdminContentBlock';
import TunnelsTab from '@/components/admin/shop/tunnels/TunnelsTab';

/** The Tunnels page on its own route, until the Shop shell renders `TunnelsTab` as its sub-tab. */
export default function TunnelsContainer() {
    return (
        <AdminContentBlock
            title='Admin · Shop · Tunnels'
            heading='Tunnels'
            description='The playit.gg tunnels that give servers their public address, such as xyz.joinmc.link.'
        >
            <TunnelsTab />
        </AdminContentBlock>
    );
}
