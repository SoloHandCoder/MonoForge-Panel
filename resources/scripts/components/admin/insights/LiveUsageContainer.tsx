import { httpErrorToHuman } from '@/api/http';
import { LIVE_USAGE_INTERVAL, useAdminLiveUsage } from '@/api/admin/insights/queries';
import AdminContentBlock from '@/components/admin/AdminContentBlock';
import NodeUsageCard from '@/components/admin/insights/NodeUsageCard';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';

function LiveUsageBody() {
    const { data, error, refetch } = useAdminLiveUsage();

    if (!data) {
        return error ? (
            <ServerError message={httpErrorToHuman(error)} onRetry={() => refetch()} />
        ) : (
            <Spinner size='large' centered />
        );
    }

    if (data.nodes.length === 0) {
        return <p className='text-sm text-muted-foreground'>There are no nodes yet.</p>;
    }

    return (
        <div className='grid grid-cols-1 gap-4 xl:grid-cols-2'>
            {data.nodes.map((node) => (
                <div key={node.id} className='animate-rise'>
                    <NodeUsageCard node={node} />
                </div>
            ))}
        </div>
    );
}

export default function LiveUsageContainer() {
    return (
        <AdminContentBlock
            title='Admin · Live usage'
            heading='Live usage'
            description={`CPU and memory across your nodes, refreshed every ${LIVE_USAGE_INTERVAL / 1000} seconds while this tab is open. CPU counts 100% per thread.`}
        >
            <LiveUsageBody />
        </AdminContentBlock>
    );
}
