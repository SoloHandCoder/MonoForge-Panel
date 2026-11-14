import { useAppForm, Form } from '@/components/form';
import type { AdminSettings } from '@/api/admin/settings/queries';
import { type MailSettingsValues } from '@/api/admin/settings/queries';
import {
    isMailDriver,
    mailDrivers,
    testAdminMailInput,
    updateAdminMailSettingsInput,
    useSendTestAdminMail,
    useUpdateAdminMailSettings,
} from '@/api/admin/settings/queries';
import { type NumberInputValue, requiredNumber, submittedNumber } from '@/components/admin/numberInput';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import Button from '@/components/elements/Button';

type MailSubmitAction = 'save' | 'test';

interface MailSettingsFormValues extends Omit<MailSettingsValues, 'port'> {
    port: NumberInputValue;
}

const mailEncryptionValue = (
    encryption: AdminSettings['mail']['mail:mailers:smtp:encryption']
): MailSettingsValues['encryption'] => (encryption === 'tls' || encryption === 'ssl' ? encryption : '');

const mailSettingsFormValues = (settings: AdminSettings): MailSettingsFormValues => ({
    driver: isMailDriver(settings.mail['mail:default']) ? settings.mail['mail:default'] : 'smtp',
    host: settings.mail['mail:mailers:smtp:host'],
    port: settings.mail['mail:mailers:smtp:port'],
    encryption: mailEncryptionValue(settings.mail['mail:mailers:smtp:encryption']),
    username: settings.mail['mail:mailers:smtp:username'] || '',
    password: '',
    fromAddress: settings.mail['mail:from:address'],
    fromName: settings.mail['mail:from:name'] || '',
    resendApiKey: '',
});

export default function MailSettingsForm({ settings }: { settings: AdminSettings }) {
    const currentDriver = settings.mail['mail:default'];
    const readOnly = settings.meta.load_environment_only;
    const resendKeySet = settings.mail['services:resend:key_set'];
    const updateMailSettings = useUpdateAdminMailSettings();
    const updateMailSettingsForTest = useUpdateAdminMailSettings({ successNotification: false });
    const sendTestMail = useSendTestAdminMail();

    const form = useAppForm({
        onSubmitMeta: 'save' as MailSubmitAction,
        defaultValues: mailSettingsFormValues(settings),
        onSubmit: async ({ value, meta }) => {
            if (readOnly) {
                return;
            }

            const input = updateAdminMailSettingsInput({ ...value, port: submittedNumber(value.port) });
            const cleared = { ...value, password: '', resendApiKey: '' };

            try {
                if (meta === 'test') {
                    await updateMailSettingsForTest.mutateAsync(input);
                    form.reset(cleared);
                    await sendTestMail.mutateAsync(testAdminMailInput());

                    return;
                }

                await updateMailSettings.mutateAsync(input);
                form.reset(cleared);
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });

    const sendTestEmail = () => void form.handleSubmit('test');

    return (
        <Form form={form}>
            <TitledGreyBox title='Email'>
                {!isMailDriver(currentDriver) && (
                    <div className='mb-6 rounded-sm border border-border bg-popover px-4 py-3 text-sm text-foreground'>
                        The panel currently sends mail with the <code>{currentDriver}</code> driver from the environment
                        file. Saving here switches it to the driver selected below.
                    </div>
                )}
                <form.AppField name='driver'>
                    {(field) => (
                        <field.SelectField id='mail_driver' label='Driver' options={mailDrivers} disabled={readOnly} />
                    )}
                </form.AppField>
                <form.Subscribe selector={(state) => state.values.driver}>
                    {(driver) => (
                        <>
                            {driver === 'smtp' && (
                                <>
                                    <div className='mt-6 grid grid-cols-2 gap-4'>
                                        <form.AppField name='host'>
                                            {(field) => (
                                                <field.TextField
                                                    type='text'
                                                    id='mail_host'
                                                    label='SMTP Host'
                                                    disabled={readOnly}
                                                />
                                            )}
                                        </form.AppField>
                                        <form.AppField
                                            name='port'
                                            validators={{ onChange: requiredNumber('An SMTP port must be provided.') }}
                                        >
                                            {(field) => (
                                                <field.NumberField
                                                    id='mail_port'
                                                    label='SMTP Port'
                                                    disabled={readOnly}
                                                />
                                            )}
                                        </form.AppField>
                                    </div>
                                    <div className='mt-6'>
                                        <form.AppField name='encryption'>
                                            {(field) => (
                                                <field.SelectField
                                                    id='mail_encryption'
                                                    label='Encryption'
                                                    options={[
                                                        { value: '', label: 'None' },
                                                        { value: 'tls', label: 'Transport Layer Security (TLS)' },
                                                        { value: 'ssl', label: 'Secure Sockets Layer (SSL)' },
                                                    ]}
                                                    disabled={readOnly}
                                                />
                                            )}
                                        </form.AppField>
                                    </div>
                                    <div className='mt-6'>
                                        <form.AppField name='username'>
                                            {(field) => (
                                                <field.TextField
                                                    type='text'
                                                    id='mail_username'
                                                    label='Username'
                                                    disabled={readOnly}
                                                />
                                            )}
                                        </form.AppField>
                                    </div>
                                    <div className='mt-6'>
                                        <form.AppField name='password'>
                                            {(field) => (
                                                <field.TextField
                                                    type='password'
                                                    id='mail_password'
                                                    label='Password'
                                                    description='Leave blank to keep the existing password. Enter !e to clear the password.'
                                                    disabled={readOnly}
                                                />
                                            )}
                                        </form.AppField>
                                    </div>
                                </>
                            )}
                            {driver === 'resend' && (
                                <div className='mt-6'>
                                    <form.AppField name='resendApiKey'>
                                        {(field) => (
                                            <field.TextField
                                                type='password'
                                                id='mail_resend_api_key'
                                                label='Resend API Key'
                                                description={
                                                    resendKeySet
                                                        ? 'A key is saved. Leave blank to keep it, or enter !e to clear it.'
                                                        : 'No key is saved yet. Create one at resend.com under API Keys.'
                                                }
                                                disabled={readOnly}
                                            />
                                        )}
                                    </form.AppField>
                                </div>
                            )}
                            {driver === 'log' && (
                                <p className='mt-6 text-sm text-muted-foreground'>
                                    Emails are written to the panel log instead of being sent.
                                </p>
                            )}
                        </>
                    )}
                </form.Subscribe>
                <div className='mt-6 grid grid-cols-2 gap-4'>
                    <form.AppField name='fromAddress'>
                        {(field) => (
                            <field.TextField
                                type='text'
                                id='mail_from_address'
                                label='From Address'
                                disabled={readOnly}
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='fromName'>
                        {(field) => (
                            <field.TextField type='text' id='mail_from_name' label='From Name' disabled={readOnly} />
                        )}
                    </form.AppField>
                </div>
                <div className='mt-6 flex flex-wrap justify-end'>
                    <form.Subscribe
                        selector={(state) => ({ canSubmit: state.canSubmit, isSubmitting: state.isSubmitting })}
                    >
                        {({ canSubmit, isSubmitting }) => (
                            <Button
                                type='button'
                                isSecondary
                                className='w-full sm:w-auto sm:mr-2'
                                disabled={readOnly || !canSubmit}
                                isLoading={isSubmitting}
                                onClick={sendTestEmail}
                            >
                                Send Test Email
                            </Button>
                        )}
                    </form.Subscribe>
                    <form.AppForm>
                        <form.SubmitButton disabled={readOnly}>Save Changes</form.SubmitButton>
                    </form.AppForm>
                </div>
            </TitledGreyBox>
        </Form>
    );
}
