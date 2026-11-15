import { useAppForm, Form } from '@/components/form';
import type { OAuthProviderId } from '@/api/account/oauth';
import {
    useAdminLoginProviders,
    useUpdateAdminLoginProviders,
    type LoginProvidersInput,
    type LoginProvidersSettings,
} from '@/api/admin/settings/loginProviders';
import { httpErrorToHuman } from '@/api/http';
import Code from '@/components/elements/Code';
import CopyOnClick from '@/components/elements/CopyOnClick';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import TitledGreyBox from '@/components/elements/TitledGreyBox';

const PROVIDERS: { id: OAuthProviderId; title: string; console: string }[] = [
    { id: 'discord', title: 'Discord', console: 'the Discord Developer Portal (OAuth2 → Redirects)' },
    { id: 'google', title: 'Google', console: 'Google Cloud Console (Credentials → Authorized redirect URIs)' },
];

const formValues = (settings: LoginProvidersSettings): LoginProvidersInput => ({
    discord: { enabled: settings.discord.enabled, client_id: settings.discord.client_id, client_secret: '' },
    google: { enabled: settings.google.enabled, client_id: settings.google.client_id, client_secret: '' },
});

function LoginProvidersFields({ settings, readOnly }: { settings: LoginProvidersSettings; readOnly: boolean }) {
    const update = useUpdateAdminLoginProviders();
    const form = useAppForm({
        defaultValues: formValues(settings),
        onSubmit: async ({ value }) => {
            if (readOnly) {
                return;
            }

            try {
                await update.mutateAsync(value);
                form.reset({
                    discord: { ...value.discord, client_secret: '' },
                    google: { ...value.google, client_secret: '' },
                });
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });

    return (
        <Form form={form}>
            {PROVIDERS.map((provider, index) => (
                <TitledGreyBox key={provider.id} title={provider.title} className={index > 0 ? 'mt-6' : undefined}>
                    <form.AppField name={`${provider.id}.enabled`}>
                        {(field) => (
                            <field.SwitchField
                                label={`Continue with ${provider.title}`}
                                description={`Let users sign in with ${provider.title} and link it from their account page.`}
                                disabled={readOnly}
                            />
                        )}
                    </form.AppField>
                    <div className='mt-6'>
                        <form.AppField name={`${provider.id}.client_id`}>
                            {(field) => (
                                <field.TextField
                                    type='text'
                                    id={`${provider.id}_client_id`}
                                    label='Client ID'
                                    disabled={readOnly}
                                />
                            )}
                        </form.AppField>
                    </div>
                    <div className='mt-6'>
                        <form.AppField name={`${provider.id}.client_secret`}>
                            {(field) => (
                                <field.TextField
                                    type='password'
                                    id={`${provider.id}_client_secret`}
                                    label='Client Secret'
                                    description={
                                        settings[provider.id].client_secret_set
                                            ? 'A secret is stored. Leave blank to keep it.'
                                            : 'No secret is stored yet.'
                                    }
                                    disabled={readOnly}
                                />
                            )}
                        </form.AppField>
                    </div>
                    <div className='mt-6 text-sm'>
                        <p className='text-muted-foreground'>Callback URL to register in {provider.console}:</p>
                        <div className='mt-2'>
                            <CopyOnClick text={settings[provider.id].callback_url}>
                                <Code className='break-all'>{settings[provider.id].callback_url}</Code>
                            </CopyOnClick>
                        </div>
                    </div>
                </TitledGreyBox>
            ))}
            <div className='flex justify-end mt-6'>
                <form.AppForm>
                    <form.SubmitButton disabled={readOnly}>Save Changes</form.SubmitButton>
                </form.AppForm>
            </div>
        </Form>
    );
}

/** The "Login providers" settings tab: the Discord and Google apps used for social sign in. */
export default function LoginProvidersForm({ readOnly }: { readOnly: boolean }) {
    const { data, error, refetch } = useAdminLoginProviders();

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => void refetch()} />;
    }

    return data ? <LoginProvidersFields settings={data} readOnly={readOnly} /> : <Spinner size='large' centered />;
}
