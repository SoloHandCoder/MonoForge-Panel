/* oxlint-disable rules/design-tokens -- the card's hover border and glow are fixed brand violets */
import type { CSSProperties } from 'react';
import { Server as ServerIcon } from 'lucide-react';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
    NewLinkButton,
    PageHeading,
    Pagination,
    Spinner,
    Switch,
} from '@/sdk';
import ServerQuickActions from './ServerQuickActions';
import Welcome from './Welcome';
import { Slot, View, type ServerListItem, type ServerListModel, type ViewProps } from '@/sdk/theme';

const cardClass =
    'group relative animate-rise rounded-[18px] border border-border bg-card p-5 transition-[transform,box-shadow,border-color] duration-200 hover:-translate-y-1 hover:border-[#4c3d86] hover:shadow-[0_16px_36px_-16px_rgba(139,92,246,.6)] focus-within:border-[#4c3d86]';

function ServerRow({ item, index }: { item: ServerListItem; index: number }) {
    const { server } = item;
    const delay = `${Math.min(index * 0.08, 0.6).toFixed(2)}s`;

    return (
        <article
            data-part='server-card'
            aria-label={item.name}
            className={cardClass}
            style={{ '--rise-delay': delay } as CSSProperties}
        >
            <Slot name='dashboard.serverRow.before' data={server} />
            <View name='dashboard.serverCard' server={server} />
            <Slot name='dashboard.serverRow.metrics.after' data={server} />
            <ServerQuickActions
                item={item}
                className='relative z-10 mt-4 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100 pointer-coarse:opacity-100'
            />
            <Slot name='dashboard.serverRow.after' data={server} />
        </article>
    );
}

function NoServers({ model }: { model: ServerListModel }) {
    const others = model.scope === 'others';

    return (
        <Empty className='border bg-card'>
            <EmptyHeader>
                <EmptyMedia variant='icon'>
                    <ServerIcon />
                </EmptyMedia>
                <EmptyTitle>{others ? 'No other servers' : 'No servers yet'}</EmptyTitle>
                <EmptyDescription>
                    {others
                        ? 'There are no servers owned by other users.'
                        : 'Servers you own or are invited to will appear here.'}
                </EmptyDescription>
            </EmptyHeader>
            {model.canCreateServers && (
                <EmptyContent>
                    <NewLinkButton to='/panel/servers/new'>New server</NewLinkButton>
                </EmptyContent>
            )}
        </Empty>
    );
}

export default function ServerList({ model, className }: ViewProps<'dashboard.serverList'>) {
    const others = model.scope === 'others';
    const { pagination } = model;

    return (
        <div className={className}>
            <Welcome model={model} />
            <PageHeading
                title='Servers'
                className='mb-4'
                actions={
                    model.canViewOthers && (
                        <Switch
                            checked={others}
                            onChange={() => model.actions.setScope(others ? 'mine' : 'others')}
                            label={others ? "Showing others' servers" : 'Showing your servers'}
                            controlPosition='end'
                            className='gap-2'
                        />
                    )
                }
            />
            <Slot name='dashboard.before' />
            {pagination ? (
                <Pagination
                    data={{
                        data: [...model.servers],
                        meta: {
                            pagination: {
                                total: pagination.total,
                                count: pagination.count,
                                per_page: pagination.perPage,
                                current_page: pagination.currentPage,
                                total_pages: pagination.totalPages,
                            },
                        },
                    }}
                    onPageSelect={model.actions.setPage}
                >
                    {({ items }) =>
                        items.length > 0 ? (
                            <div className='grid grid-cols-1 gap-4 lg:grid-cols-2'>
                                {items.map((item, index) => (
                                    <ServerRow key={item.uuid} item={item} index={index} />
                                ))}
                            </div>
                        ) : (
                            <NoServers model={model} />
                        )
                    }
                </Pagination>
            ) : (
                <Spinner centered size='large' />
            )}
            <Slot name='dashboard.after' />
        </div>
    );
}
