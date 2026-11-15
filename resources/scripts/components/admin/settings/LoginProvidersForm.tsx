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

const ENV_VARIABLES = {
    discord: {
        enabled: 'DISCORD_LOGIN_ENABLED',
        client_id: 'DISCORD_CLIENT_ID',
        client_secret: 'DISCORD_CLIENT_SECRET',
    },
    google: { enabled: 'GOOGLE_LOGIN_ENABLED', client_id: 'GOOGLE_CLIENT_ID', client_secret: 'GOOGLE_CLIENT_SECRET' },
} satisfies Record<OAuthProviderId, Record<keyof LoginProvidersSettings[OAuthProviderId]['set_in_env'], string>>;

/** The note under a field that .env sets, so the panel cannot change it. */
const setInEnv = (variable: string) => `Set in .env (${variable}). Change it there.`;

const formValues = (settings: LoginProvidersSettings): LoginProvidersInput => ({
    discord: { enabled: settings.discord.enabled, client_id: settings.discord.client_id, client_secret: '' },
    google: { enabled: settings.google.enabled, client_id: settings.google.client_id, client_secret: '' },
});

function secretDescription(settings: LoginProvidersSettings[OAuthProviderId], variable: string): string {
    if (settings.set_in_env.client_secret) {
        return setInEnv(variable);
    }

    return settings.client_secret_set ? 'A secret is stored. Leave blank to keep it.' : 'No secret is stored yet.';
}

function LoginProvidersFields({ settings }: { settings: LoginProvidersSettings }) {
    const locked = (id: OAuthProviderId) => settings[id].set_in_env;
    const readOnly = PROVIDERS.every(({ id }) => Object.values(locked(id)).every(Boolean));
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
                                description={
                                    locked(provider.id).enabled
                                        ? setInEnv(ENV_VARIABLES[provider.id].enabled)
                                        : `Let users sign in with ${provider.title} and link it from their account page.`
                                }
                                disabled={locked(provider.id).enabled}
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
                                    description={
                                        locked(provider.id).client_id
                                            ? setInEnv(ENV_VARIABLES[provider.id].client_id)
                                            : undefined
                                    }
                                    disabled={locked(provider.id).client_id}
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
                                    description={secretDescription(
                                        settings[provider.id],
                                        ENV_VARIABLES[provider.id].client_secret
                                    )}
                                    disabled={locked(provider.id).client_secret}
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
export default function LoginProvidersForm() {
    const { data, error, refetch } = useAdminLoginProviders();

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => void refetch()} />;
    }

    return data ? <LoginProvidersFields settings={data} /> : <Spinner size='large' centered />;
}
