import { useAppForm, Form } from '@/components/form';
import {
    updateAdminUpdateModeInput,
    useAdminUpdateStatus,
    useUpdateAdminUpdateMode,
    type AdminUpdateMode,
} from '@/api/admin/updates/queries';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import Spinner from '@/components/elements/Spinner';

const modeOptions: { value: AdminUpdateMode; label: string }[] = [
    { value: 'notify', label: 'Notify me (check daily)' },
    { value: 'auto', label: 'Install automatically (check hourly)' },
    { value: 'off', label: 'Off (no update checks)' },
];

function UpdateModeForm({ mode, readOnly }: { mode: AdminUpdateMode; readOnly: boolean }) {
    const updateMode = useUpdateAdminUpdateMode();
    const form = useAppForm({
        defaultValues: { mode },
        onSubmit: async ({ value }) => {
            if (readOnly) {
                return;
            }

            try {
                await updateMode.mutateAsync(updateAdminUpdateModeInput(value.mode));
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });

    return (
        <Form form={form}>
            <form.AppField name='mode'>
                {(field) => (
                    <field.SelectField
                        id='monoforge_update_mode'
                        label='MonoForge Updates'
                        options={modeOptions}
                        disabled={readOnly}
                        description='New MonoForge builds come from the GitHub releases of SoloHandCoder/MonoForge-Panel. Automatic installs briefly put the panel into maintenance, back up the code and database, and run migrations.'
                    />
                )}
            </form.AppField>
            <div className='flex justify-end mt-4'>
                <form.AppForm>
                    <form.SubmitButton disabled={readOnly}>Save Changes</form.SubmitButton>
                </form.AppForm>
            </div>
        </Form>
    );
}

/** The MonoForge self-update mode, saved as the monoforge:update:mode setting. */
export default function UpdateSettingsForm({ readOnly }: { readOnly: boolean }) {
    const { data: status } = useAdminUpdateStatus();

    return (
        <TitledGreyBox title='Updates' className='mt-6'>
            {status ? <UpdateModeForm mode={status.mode} readOnly={readOnly} /> : <Spinner size='small' centered />}
        </TitledGreyBox>
    );
}
