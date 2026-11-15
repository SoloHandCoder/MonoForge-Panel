import { useStore } from '@tanstack/react-form';
import { useAppForm, Form } from '@/components/form';
import {
    type AdminRegistrationSettings,
    updateAdminRegistrationSettingsInput,
    useAdminRegistrationSettings,
    useUpdateAdminRegistrationSettings,
} from '@/api/admin/settings/registration';
import type { CaptchaProvider } from '@/api/settings/types';
import { httpErrorToHuman } from '@/api/http';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import Spinner from '@/components/elements/Spinner';
import { ServerError } from '@/components/elements/ScreenBlock';

const providerOptions: { value: CaptchaProvider; label: string }[] = [
    { value: 'none', label: 'None' },
    { value: 'recaptcha', label: 'Google reCAPTCHA' },
    { value: 'turnstile', label: 'Cloudflare Turnstile' },
];

const isProvider = (value: string): value is CaptchaProvider =>
    value === 'none' || value === 'recaptcha' || value === 'turnstile';

/** The note under a field that .env sets, so the panel cannot change it. */
const setInEnv = (variable: string) => `Set in .env (${variable}). Change it there.`;

function secretDescription(settings: AdminRegistrationSettings): string {
    if (settings.setInEnv.turnstileSecretKey) {
        return setInEnv('TURNSTILE_SECRET_KEY');
    }

    return settings.turnstileSecretKeySet
        ? 'A secret key is stored. Leave blank to keep it.'
        : 'No secret key is stored yet.';
}

export function RegistrationSettingsForm({ settings }: { settings: AdminRegistrationSettings }) {
    const locked = settings.setInEnv;
    const readOnly = Object.values(locked).every(Boolean);
    const update = useUpdateAdminRegistrationSettings();

    const form = useAppForm({
        defaultValues: {
            registrationEnabled: settings.registrationEnabled,
            captchaProvider: settings.captchaProvider as string,
            turnstileSiteKey: settings.turnstileSiteKey,
            turnstileSecretKey: '',
        },
        onSubmit: async ({ value }) => {
            if (readOnly) {
                return;
            }

            try {
                await update.mutateAsync(
                    updateAdminRegistrationSettingsInput({
                        registrationEnabled: value.registrationEnabled,
                        captchaProvider: isProvider(value.captchaProvider) ? value.captchaProvider : 'none',
                        turnstileSiteKey: value.turnstileSiteKey.trim(),
                        turnstileSecretKey: value.turnstileSecretKey.trim(),
                    })
                );
                form.reset({ ...value, turnstileSecretKey: '' });
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });
    const provider = useStore(form.store, (state) => state.values.captchaProvider);

    return (
        <Form form={form}>
            <TitledGreyBox title='Sign-up'>
                <form.AppField name='registrationEnabled'>
                    {(field) => (
                        <field.SwitchField
                            label='Allow sign-up'
                            description={
                                locked.registrationEnabled
                                    ? setInEnv('MONOFORGE_REGISTRATION_ENABLED')
                                    : 'Show "Create an account" on the login page so visitors can make their own account.'
                            }
                            disabled={locked.registrationEnabled}
                        />
                    )}
                </form.AppField>
            </TitledGreyBox>
            <TitledGreyBox title='Captcha' className='mt-6'>
                <form.AppField name='captchaProvider'>
                    {(field) => (
                        <field.SelectField
                            id='captcha_provider'
                            label='Captcha Provider'
                            description={
                                locked.captchaProvider
                                    ? setInEnv('MONOFORGE_CAPTCHA_PROVIDER')
                                    : 'Protects login, sign-up, password reset and social login.'
                            }
                            options={providerOptions}
                            disabled={locked.captchaProvider}
                        />
                    )}
                </form.AppField>
                {provider === 'recaptcha' && (
                    <p className='mt-4 text-sm text-muted-foreground'>
                        The reCAPTCHA keys are set on the Advanced tab.
                    </p>
                )}
                {provider === 'turnstile' && (
                    <div className='mt-6 grid gap-6 md:grid-cols-2'>
                        <form.AppField name='turnstileSiteKey'>
                            {(field) => (
                                <field.TextField
                                    type='text'
                                    id='turnstile_site_key'
                                    label='Turnstile Site Key'
                                    description={locked.turnstileSiteKey ? setInEnv('TURNSTILE_SITE_KEY') : undefined}
                                    disabled={locked.turnstileSiteKey}
                                />
                            )}
                        </form.AppField>
                        <form.AppField name='turnstileSecretKey'>
                            {(field) => (
                                <field.TextField
                                    type='password'
                                    id='turnstile_secret_key'
                                    label='Turnstile Secret Key'
                                    description={secretDescription(settings)}
                                    disabled={locked.turnstileSecretKey}
                                />
                            )}
                        </form.AppField>
                    </div>
                )}
            </TitledGreyBox>
            <div className='flex justify-end mt-6'>
                <form.AppForm>
                    <form.SubmitButton disabled={readOnly}>Save Changes</form.SubmitButton>
                </form.AppForm>
            </div>
        </Form>
    );
}

/** The Registration tab: loads its own settings, which live beside the panel-wide ones. */
export default function RegistrationSettings() {
    const { data, error, refetch } = useAdminRegistrationSettings();

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => refetch()} />;
    }

    return data ? <RegistrationSettingsForm settings={data} /> : <Spinner size='large' centered />;
}
