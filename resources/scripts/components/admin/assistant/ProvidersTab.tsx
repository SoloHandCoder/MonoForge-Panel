import { useState } from 'react';
import { Bot, KeyRound, Pencil, Plug, Plus, Trash2 } from 'lucide-react';
import {
    useAssistantProviders,
    useDeleteAssistantProvider,
    useTestAssistantProvider,
    type AssistantProvider,
} from '@/api/assistant';
import { httpErrorToHuman } from '@/api/http';
import { withServerPresets, type ProviderPresetMap } from '@/components/admin/assistant/assistantTabs';
import ProviderFormDialog from '@/components/admin/assistant/ProviderFormDialog';
import Badge from '@/components/elements/Badge';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import Icon from '@/components/elements/Icon';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import { formatTokens } from '@/api/account/wallet/queries';

type Editing = { readonly provider?: AssistantProvider } | null;

function ProviderCard({
    provider,
    presets,
    isDefault,
    onEdit,
}: {
    provider: AssistantProvider;
    presets: ProviderPresetMap;
    isDefault: boolean;
    onEdit: () => void;
}) {
    const test = useTestAssistantProvider();
    const remove = useDeleteAssistantProvider();

    return (
        <li
            className='flex animate-rise flex-col gap-4 rounded-xl border border-border bg-card p-4 sm:flex-row sm:items-center'
            data-testid='assistant-provider'
        >
            <span className='flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary'>
                <Icon icon={Bot} className='size-5' aria-hidden />
            </span>
            <div className='min-w-0 flex-1'>
                <div className='flex flex-wrap items-center gap-2'>
                    <p className='truncate font-medium text-foreground'>{provider.name}</p>
                    <Badge tone='accent' variant='soft' size='sm'>
                        {presets[provider.preset].label}
                    </Badge>
                    {isDefault && (
                        <Badge tone='success' variant='soft' size='sm'>
                            Default
                        </Badge>
                    )}
                    {!provider.enabled && (
                        <Badge tone='neutral' variant='soft' size='sm'>
                            Disabled
                        </Badge>
                    )}
                    <Badge tone={provider.has_key ? 'success' : 'neutral'} variant='soft' size='sm'>
                        <Icon icon={KeyRound} className='mr-1 inline size-3' aria-hidden />
                        {provider.has_key ? 'Key stored' : 'No key'}
                    </Badge>
                </div>
                <p className='mt-1 truncate font-mono text-xs text-muted-foreground'>{provider.model}</p>
                <p className='truncate text-xs text-muted-foreground'>
                    {provider.base_url} · {formatTokens(provider.context_window)} context
                    {!provider.supports_tools && ' · no tools'}
                </p>
            </div>
            <div className='flex shrink-0 flex-wrap gap-2'>
                <Button
                    type='button'
                    size='small'
                    isSecondary
                    isLoading={test.isPending}
                    onClick={() => test.mutate(provider.id)}
                    aria-label={`Test ${provider.name}`}
                >
                    <Icon icon={Plug} className='size-4' aria-hidden />
                    Test
                </Button>
                <Button type='button' size='small' isSecondary onClick={onEdit} aria-label={`Edit ${provider.name}`}>
                    <Icon icon={Pencil} className='size-4' aria-hidden />
                    Edit
                </Button>
                <Dialog.ConfirmTrigger
                    title='Delete provider'
                    confirm='Delete provider'
                    onConfirmed={async (_event, close) => {
                        try {
                            await remove.mutateAsync(provider.id);
                            close();
                        } catch {
                            // Error toast is handled by the mutation.
                        }
                    }}
                    trigger={({ onClick }) => (
                        <Button
                            type='button'
                            size='small'
                            color='red'
                            isSecondary
                            onClick={onClick}
                            aria-label={`Delete ${provider.name}`}
                        >
                            <Icon icon={Trash2} className='size-4' aria-hidden />
                        </Button>
                    )}
                >
                    <p className='text-sm text-muted-foreground'>
                        Conversations that used <strong className='text-foreground'>{provider.name}</strong> switch to
                        the default provider. Its stored key is deleted.
                    </p>
                </Dialog.ConfirmTrigger>
            </div>
        </li>
    );
}

/** The AI providers: OpenAI-compatible endpoints with presets for ClinePass, Ollama and others. */
export default function ProvidersTab() {
    const { data, error, refetch } = useAssistantProviders();
    const [editing, setEditing] = useState<Editing>(null);
    const presets = withServerPresets(data?.meta.presets);

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => void refetch()} />;
    }

    return (
        <>
            <div className='mb-4 flex flex-wrap items-center justify-between gap-3'>
                <p className='text-sm text-muted-foreground'>
                    Keys are stored encrypted and are never sent back to the browser.
                </p>
                <Button type='button' size='small' onClick={() => setEditing({})}>
                    <Icon icon={Plus} className='size-4' aria-hidden />
                    Add provider
                </Button>
            </div>
            {data ? (
                <ul className='grid gap-3'>
                    {data.data.map((provider) => (
                        <ProviderCard
                            key={provider.id}
                            provider={provider}
                            presets={presets}
                            isDefault={provider.id === data.meta.default_provider_id}
                            onEdit={() => setEditing({ provider })}
                        />
                    ))}
                    {data.data.length === 0 && (
                        <li className='rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground'>
                            No providers yet. Add ClinePass or a local Ollama to get started.
                        </li>
                    )}
                </ul>
            ) : (
                <Spinner size='large' centered />
            )}
            {editing && (
                <ProviderFormDialog
                    key={editing.provider?.id ?? 'new'}
                    open
                    provider={editing.provider}
                    presets={presets}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}
