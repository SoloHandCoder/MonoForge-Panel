import { Alert, ServerContentBlock } from '@/sdk';
import { Slot, View, type ServerConsoleModel, type ViewProps } from '@/sdk/theme';

const notices = {
    maintenance: 'The node of this server is currently under maintenance and all actions are unavailable.',
    installing: 'This server is currently running its installation process and most actions are unavailable.',
    transferring: 'This server is currently being transferred to another node and all actions are unavailable.',
} satisfies Record<NonNullable<ServerConsoleModel['notice']>, string>;

export default function ConsolePage({ model, active, className }: ViewProps<'page.server.console'>) {
    return (
        <ServerContentBlock title={active ? 'Console' : undefined} className={className}>
            {model.notice && (
                <Alert type='warning' className='mb-4'>
                    {notices[model.notice]}
                </Alert>
            )}
            <Slot name='server.console.before' data={model.server} />
            <div className='mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between'>
                <div className='min-w-0'>
                    <h1 className='font-header text-2xl leading-relaxed font-medium text-foreground line-clamp-1'>
                        {model.name}
                    </h1>
                    {model.description && (
                        <p className='text-sm text-muted-foreground line-clamp-2'>{model.description}</p>
                    )}
                </div>
                <View name='server.power' className='shrink-0' />
            </div>
            <div className='mb-4 grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_19rem] xl:grid-cols-[minmax(0,1fr)_21rem]'>
                <View name='server.terminal' className='min-w-0' />
                <aside aria-label='Server status' className='flex min-w-0 flex-col gap-3'>
                    <View name='server.stats' />
                    <View name='server.graphs' />
                </aside>
            </div>
            <Slot name='server.console.after' data={model.server} />
            {model.features.map((name) => (
                <View key={name} name={name} />
            ))}
        </ServerContentBlock>
    );
}
