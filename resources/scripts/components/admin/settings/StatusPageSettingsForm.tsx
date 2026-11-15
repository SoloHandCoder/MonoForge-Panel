import {
    type AdminStatusPageSettings,
    STATUS_NAME_MAX_LENGTH,
    updateAdminStatusPageSettingsInput,
    useAdminStatusPageSettings,
    useUpdateAdminStatusPageSettings,
} from '@/api/admin/settings/status';
import { httpErrorToHuman } from '@/api/http';
import { useAppForm, Form } from '@/components/form';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import TitledGreyBox from '@/components/elements/TitledGreyBox';

export function StatusPageSettingsForm({ settings }: { settings: AdminStatusPageSettings }) {
    const update = useUpdateAdminStatusPageSettings();

    const form = useAppForm({
        defaultValues: {
            enabled: settings.enabled,
            nodes: settings.nodes.map((node) => ({ id: node.id, visible: node.visible, customName: node.customName })),
        },
        onSubmit: async ({ value }) => {
            try {
                await update.mutateAsync(updateAdminStatusPageSettingsInput(value));
                form.reset(value);
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });

    return (
        <Form form={form}>
            <TitledGreyBox title='Status page'>
                <form.AppField name='enabled'>
                    {(field) => (
                        <field.SwitchField
                            label='Show the public status page'
                            description='Anyone can see the panel and the nodes below, their uptime over 90 days, and warning or danger announcements. While it is off, nothing is checked.'
                        />
                    )}
                </form.AppField>
                <p className='mt-4 text-sm text-muted-foreground'>
                    <a
                        href={settings.publicUrl}
                        target='_blank'
                        rel='noopener'
                        className='font-medium text-accent underline underline-offset-2'
                    >
                        Open the status page
                    </a>
                </p>
            </TitledGreyBox>
            <TitledGreyBox title='Nodes' className='mt-6'>
                <p className='mb-4 text-sm text-muted-foreground'>
                    Visitors only ever see the display name, never the node&apos;s name, address or FQDN. A blank name
                    uses the default: the location short name and &ldquo;Node N&rdquo;.
                </p>
                {settings.nodes.length === 0 ? (
                    <p className='text-sm text-muted-foreground'>There are no nodes yet.</p>
                ) : (
                    <ul className='divide-y divide-border'>
                        {settings.nodes.map((node, index) => (
                            <li
                                key={node.id}
                                className='grid gap-3 py-4 first:pt-0 last:pb-0 md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] md:items-center'
                            >
                                <div className='min-w-0'>
                                    <p className='truncate text-sm font-medium text-foreground'>{node.name}</p>
                                    <p className='text-xs text-muted-foreground'>{node.location}</p>
                                </div>
                                <form.AppField name={`nodes[${index}].visible`}>
                                    {(field) => <field.SwitchField label='Shown' />}
                                </form.AppField>
                                <form.AppField name={`nodes[${index}].customName`}>
                                    {(field) => (
                                        <field.TextField
                                            type='text'
                                            aria-label={`Display name of ${node.name}`}
                                            placeholder={node.defaultName}
                                            maxLength={STATUS_NAME_MAX_LENGTH}
                                        />
                                    )}
                                </form.AppField>
                            </li>
                        ))}
                    </ul>
                )}
            </TitledGreyBox>
            <div className='mt-6 flex justify-end'>
                <form.AppForm>
                    <form.SubmitButton>Save Changes</form.SubmitButton>
                </form.AppForm>
            </div>
        </Form>
    );
}

/** The Status tab: loads its own settings, which live beside the panel-wide ones. */
export default function StatusPageSettings() {
    const { data, error, refetch } = useAdminStatusPageSettings();

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => refetch()} />;
    }

    return data ? <StatusPageSettingsForm settings={data} /> : <Spinner size='large' centered />;
}
