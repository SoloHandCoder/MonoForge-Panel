import {
    type AdminServerPageDiscovery,
    updateAdminServerPageDiscoveryInput,
    useAdminServerPageDiscovery,
    useUpdateAdminServerPageDiscovery,
} from '@/api/admin/servers/pageDiscovery';
import Button from '@/components/elements/Button';
import TitledGreyBox from '@/components/elements/TitledGreyBox';

const descriptionClass = 'text-sm leading-relaxed text-card-foreground';

const listing = (page: AdminServerPageDiscovery): string => {
    if (page.hidden_from_discovery) {
        return 'Hidden from the discovery page by an administrator.';
    }

    return page.listed
        ? 'Listed on the server discovery page.'
        : 'Not listed on the discovery page (unpublished, or its owner turned the listing off).';
};

const summary = (page: AdminServerPageDiscovery | undefined, failed: boolean): string => {
    if (failed) {
        return 'The public page could not be loaded.';
    }

    if (!page) {
        return 'Loading the public page…';
    }

    if (!page.exists) {
        return 'This server has no public page.';
    }

    return `The public page /s/${page.slug ?? ''} is ${page.published ? 'published' : 'not published'}. ${listing(page)}`;
};

/**
 * The server's public page on Admin > Servers > Manage: whether it is listed on the server
 * discovery page, and a switch that takes it off (or puts it back) without unpublishing it.
 */
export default function ServerPageDiscoveryCard({ serverId }: { serverId: number }) {
    const page = useAdminServerPageDiscovery(serverId);
    const update = useUpdateAdminServerPageDiscovery(serverId);
    const data = page.data;

    const description = summary(data, page.isError);
    const hidden = data?.hidden_from_discovery === true;

    return (
        <TitledGreyBox
            title='Public Page Discovery'
            className='flex h-full flex-col'
            contentClassName='flex flex-1 flex-col p-0'
        >
            <div className='flex flex-1 flex-col gap-3 p-3'>
                <p className={descriptionClass}>{description}</p>
                <p className={descriptionClass}>
                    Hiding a page keeps it published at its address; only the discovery page stops listing it, and its
                    owner cannot undo it.
                </p>
            </div>
            <div className='flex items-center justify-end gap-3 border-t border-border p-3'>
                {data?.exists && data.url && (
                    <a
                        href={data.url}
                        target='_blank'
                        rel='noopener noreferrer'
                        className='text-sm text-accent underline'
                    >
                        Open page
                    </a>
                )}
                <Button
                    color={hidden ? 'primary' : 'red'}
                    className='whitespace-nowrap'
                    disabled={!data?.exists || update.isPending}
                    onClick={() => data && update.mutate(updateAdminServerPageDiscoveryInput(serverId, !hidden))}
                >
                    {hidden ? 'List again' : 'Hide from discovery'}
                </Button>
            </div>
        </TitledGreyBox>
    );
}
