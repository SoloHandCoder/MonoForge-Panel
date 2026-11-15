import { useAppForm, Form } from '@/components/form';
import {
    useAdminNodePlayit,
    useTestAdminNodePlayit,
    useUpdateAdminNodePlayit,
    type NodePlayitSettings,
} from '@/api/admin/tunnels/queries';
import Button from '@/components/elements/Button';
import Spinner from '@/components/elements/Spinner';
import TitledGreyBox from '@/components/elements/TitledGreyBox';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function PlayitForm({ nodeId, settings }: { nodeId: number; settings: NodePlayitSettings }) {
    const update = useUpdateAdminNodePlayit(nodeId);
    const test = useTestAdminNodePlayit(nodeId);
    const form = useAppForm({
        defaultValues: { agentId: settings.agent_id ?? '', secret: '' },
        onSubmit: async ({ value }) => {
            const agentId = value.agentId.trim();

            try {
                await update.mutateAsync({ agent_id: agentId === '' ? null : agentId, secret: value.secret });
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
                <div className='mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2'>
                    <form.AppField
                        name='agentId'
                        validators={{
                            onChange: ({ value }) =>
                                value.trim() === '' || UUID.test(value.trim()) ? undefined : 'Enter the agent UUID.',
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
                                        : 'The secret key from the agent (playit.toml). No secret is stored yet.'
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
