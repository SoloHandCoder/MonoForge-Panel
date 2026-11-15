import { useAppForm, Form } from '@/components/form';
import {
    useAdminNodePlayit,
    useTestAdminNodePlayit,
    useUpdateAdminNodePlayit,
    READ_ONLY_KEY_MESSAGE,
    type NodePlayitSettings,
} from '@/api/admin/tunnels/queries';
import { Alert } from '@/components/elements/alert';
import Button from '@/components/elements/Button';
import Spinner from '@/components/elements/Spinner';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import { cleanPlayitValue } from '@/lib/playit';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function PlayitForm({ nodeId, settings }: { nodeId: number; settings: NodePlayitSettings }) {
    const update = useUpdateAdminNodePlayit(nodeId);
    const test = useTestAdminNodePlayit(nodeId);
    const form = useAppForm({
        defaultValues: { agentId: settings.agent_id ?? '', secret: '' },
        onSubmit: async ({ value }) => {
            // Lines pasted from playit.toml (`agent_id = "…"`) are cleaned here as on the server.
            const agentId = cleanPlayitValue(value.agentId, 'agent_id');

            try {
                await update.mutateAsync({
                    agent_id: agentId === '' ? null : agentId,
                    secret: cleanPlayitValue(value.secret, 'secret_key'),
                });
                form.reset({ agentId, secret: '' });
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });

    return (
        <Form form={form}>
            <TitledGreyBox title='playit.gg Tunnels' className='mt-6'>
                <p className='text-sm text-muted-foreground'>
                    With the playit.gg agent running on this node, every new server gets a playit tunnel and players see
                    its address instead of the node&apos;s IP. Leave the agent ID empty to turn this off.
                </p>
                {settings.read_only && (
                    <Alert type='warning' title='Read-only agent key' className='mt-4'>
                        {READ_ONLY_KEY_MESSAGE} Saving a different key lets the panel try creating tunnels again.
                    </Alert>
                )}
                <div className='mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2'>
                    <form.AppField
                        name='agentId'
                        validators={{
                            onChange: ({ value }) => {
                                const agentId = cleanPlayitValue(value, 'agent_id');

                                return agentId === '' || UUID.test(agentId) ? undefined : 'Enter the agent UUID.';
                            },
                        }}
                    >
                        {(field) => (
                            <field.TextField
                                type='text'
                                id='playit_agent_id'
                                label='Agent ID'
                                description='The agent UUID, shown on the agent page at playit.gg.'
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='secret'>
                        {(field) => (
                            <field.TextField
                                type='password'
                                id='playit_secret'
                                label='Agent Secret'
                                description={
                                    settings.secret_set
                                        ? 'A secret is stored. Leave blank to keep it.'
                                        : 'The secret_key value from the playit.toml the agent uses (often /etc/playit/playit.toml). No secret is stored yet.'
                                }
                            />
                        )}
                    </form.AppField>
                </div>
                <div className='mt-6 flex flex-wrap justify-end gap-3'>
                    <Button
                        type='button'
                        isSecondary
                        isLoading={test.isPending}
                        disabled={!settings.agent_id || !settings.secret_set}
                        onClick={() => test.mutate()}
                    >
                        Test Connection
                    </Button>
                    <form.AppForm>
                        <form.SubmitButton>Save Agent</form.SubmitButton>
                    </form.AppForm>
                </div>
            </TitledGreyBox>
        </Form>
    );
}

/** The node's playit.gg agent, saved apart from the node settings so the secret stays write-only. */
export default function NodePlayitSettings({ nodeId }: { nodeId: number }) {
    const { data } = useAdminNodePlayit(nodeId);

    return data ? <PlayitForm key={nodeId} nodeId={nodeId} settings={data} /> : <Spinner size='small' centered />;
}
