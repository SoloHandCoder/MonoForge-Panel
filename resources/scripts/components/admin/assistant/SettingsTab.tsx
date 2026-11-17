import {
    useAssistantProviders,
    useAssistantSettings,
    useUpdateAssistantSettings,
    type AssistantProvider,
    type AssistantSearchProvider,
    type AssistantSettings,
} from '@/api/assistant';
import { httpErrorToHuman } from '@/api/http';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import { Form, useAppForm } from '@/components/form';

const NO_PROVIDER = 0;

interface SettingsFormValues extends Omit<AssistantSettings, 'default_provider_id' | 'web_search_key_set'> {
    /** 0 is "none", since the select cannot hold null. */
    default_provider_id: number;
    /** A new search API key; blank keeps the stored one. */
    web_search_key: string;
    clear_web_search_key: boolean;
}

const searchProviderOptions = [
    { value: 'native', label: 'The AI provider (OpenRouter only)' },
    { value: 'brave', label: 'Brave Search API' },
    { value: 'tavily', label: 'Tavily API' },
] satisfies { value: AssistantSearchProvider; label: string }[];

/** The form starts from the stored settings; whether a key is stored is shown, not sent back. */
const formValues = ({ web_search_key_set: _keyStored, ...settings }: AssistantSettings): SettingsFormValues => ({
    ...settings,
    default_provider_id: settings.default_provider_id ?? NO_PROVIDER,
    web_search_key: '',
    clear_web_search_key: false,
});

const atLeast = (minimum: number) => ({
    onChange: ({ value }: { value: number | null }) =>
        value !== null && value >= minimum ? undefined : `At least ${minimum}.`,
});

function SettingsForm({
    settings,
    providers,
}: {
    settings: AssistantSettings;
    providers: readonly AssistantProvider[];
}) {
    const update = useUpdateAssistantSettings();
    const form = useAppForm({
        defaultValues: formValues(settings),
        onSubmit: async ({ value, formApi }) => {
            try {
                await update.mutateAsync({
                    ...value,
                    default_provider_id: value.default_provider_id === NO_PROVIDER ? null : value.default_provider_id,
                    web_search_key: value.web_search_key.trim() || null,
                });
                // Keys are write-only: the field empties once the key is stored.
                formApi.setFieldValue('web_search_key', '');
                formApi.setFieldValue('clear_web_search_key', false);
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });
    const providerOptions = [
        { value: NO_PROVIDER, label: 'None' },
        ...providers.map((provider) => ({ value: provider.id, label: provider.name })),
    ];

    return (
        <Form form={form}>
            <TitledGreyBox title='Availability'>
                <div className='grid gap-6'>
                    <form.AppField name='enabled'>
                        {(field) => (
                            <field.SwitchField label='Assistant' description='The master switch for both assistants.' />
                        )}
                    </form.AppField>
                    <form.AppField name='server_enabled'>
                        {(field) => (
                            <field.SwitchField
                                label='Server assistant'
                                description='Players with the assistant permission can chat on their server page.'
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='admin_enabled'>
                        {(field) => (
                            <field.SwitchField label='Admin assistant' description='The Chat tab for root admins.' />
                        )}
                    </form.AppField>
                    <form.AppField name='crash_doctor_enabled'>
                        {(field) => (
                            <field.SwitchField
                                label='Crash doctor'
                                description='When a server crashes or fails to start, its page offers an AI diagnosis. Needs the server assistant; billed like any conversation.'
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='default_provider_id'>
                        {(field) => (
                            <field.SelectField
                                label='Default provider'
                                description='Used for new conversations.'
                                options={providerOptions}
                            />
                        )}
                    </form.AppField>
                </div>
            </TitledGreyBox>
            <TitledGreyBox title='Approvals and web search' className='mt-6'>
                <div className='grid gap-6'>
                    <form.AppField name='allow_everything_enabled'>
                        {(field) => (
                            <field.SwitchField
                                label='Allow "Allow everything"'
                                description='Users may switch a conversation to run actions without asking (after a warning). Deleting many files or a world folder, token changes, suspensions and announcements always ask.'
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='web_search_enabled'>
                        {(field) => (
                            <field.SwitchField
                                label='Web search'
                                description='The assistant may look up docs and error messages on the web. Searches are billed like other usage.'
                            />
                        )}
                    </form.AppField>
                    <div className='grid gap-6 sm:grid-cols-2'>
                        <form.AppField name='web_search_provider'>
                            {(field) => (
                                <field.SelectField
                                    label='Search with'
                                    description='OpenRouter has its own web search; other providers need a search API key.'
                                    options={searchProviderOptions}
                                />
                            )}
                        </form.AppField>
                        <form.AppField name='web_search_key'>
                            {(field) => (
                                <field.TextField
                                    type='password'
                                    autoComplete='off'
                                    label='Search API key'
                                    description={
                                        settings.web_search_key_set
                                            ? 'A key is stored. Leave blank to keep it.'
                                            : 'For Brave Search or Tavily. Stored encrypted.'
                                    }
                                />
                            )}
                        </form.AppField>
                    </div>
                    {settings.web_search_key_set && (
                        <form.AppField name='clear_web_search_key'>
                            {(field) => (
                                <field.SwitchField
                                    label='Remove the stored search key'
                                    description='Takes effect when you save.'
                                />
                            )}
                        </form.AppField>
                    )}
                </div>
            </TitledGreyBox>
            <TitledGreyBox title='Billing' className='mt-6'>
                <div className='grid gap-6 sm:grid-cols-3'>
                    <form.AppField name='free_tokens_monthly' validators={atLeast(0)}>
                        {(field) => (
                            <field.NumberField
                                label='Free AI tokens per month'
                                description='Per user, before tokens are charged.'
                                min={0}
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='ratio' validators={atLeast(1)}>
                        {(field) => (
                            <field.NumberField
                                label='AI tokens per panel token'
                                description='After the free allowance.'
                                min={1}
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='warn_percent' validators={atLeast(1)}>
                        {(field) => (
                            <field.NumberField
                                label='Warn at (%)'
                                description='Of the free allowance.'
                                min={1}
                                max={100}
                            />
                        )}
                    </form.AppField>
                </div>
            </TitledGreyBox>
            <TitledGreyBox title='Limits' className='mt-6'>
                <div className='grid gap-6 sm:grid-cols-3'>
                    <form.AppField name='max_tool_iterations' validators={atLeast(1)}>
                        {(field) => (
                            <field.NumberField
                                label='Tool calls per turn'
                                description='The loop stops after this many.'
                                min={1}
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='context_tokens' validators={atLeast(1024)}>
                        {(field) => (
                            <field.NumberField
                                label='Context tokens'
                                description='Older messages are summarised beyond this.'
                                min={1024}
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='max_output_tokens' validators={atLeast(64)}>
                        {(field) => <field.NumberField label='Max reply tokens' min={64} />}
                    </form.AppField>
                </div>
                <div className='mt-6 text-right'>
                    <form.AppForm>
                        <form.SubmitButton>Save Settings</form.SubmitButton>
                    </form.AppForm>
                </div>
            </TitledGreyBox>
        </Form>
    );
}

/** The `monoforge:ai:*` settings. */
export default function SettingsTab() {
    const settings = useAssistantSettings();
    const providers = useAssistantProviders();

    if (settings.error && !settings.data) {
        return <ServerError message={httpErrorToHuman(settings.error)} onRetry={() => void settings.refetch()} />;
    }

    return settings.data && providers.data ? (
        <SettingsForm settings={settings.data} providers={providers.data.data} />
    ) : (
        <Spinner size='large' centered />
    );
}
