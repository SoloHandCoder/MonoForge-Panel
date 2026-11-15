import {
    useAdminPluginSettings,
    useUpdateAdminPluginSettings,
    type AdminPluginSettings,
} from '@/api/admin/settings/plugins';
import { httpErrorToHuman } from '@/api/http';
import Button from '@/components/elements/Button';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import { Form, useAppForm } from '@/components/form';

function keyDescription(settings: AdminPluginSettings): string {
    if (settings.set_in_env) {
        return 'Set in .env (CURSEFORGE_API_KEY). Change it there.';
    }

    return settings.curseforge_key_set
        ? 'A key is stored. Leave blank to keep it.'
        : 'No key is stored, so players only see Modrinth and Hangar.';
}

export function PluginSettingsFields({ settings }: { settings: AdminPluginSettings }) {
    const update = useUpdateAdminPluginSettings();
    const form = useAppForm({
        defaultValues: { curseforge_key: '' },
        onSubmit: async ({ value }) => {
            try {
                await update.mutateAsync({ body: { curseforge_key: value.curseforge_key.trim() || null } });
                form.reset({ curseforge_key: '' });
            } catch {
                // The mutation shows the error.
            }
        },
    });

    return (
        <Form form={form}>
            <TitledGreyBox title='CurseForge'>
                <p className='mb-4 text-sm text-muted-foreground'>
                    Modrinth and Hangar need no key. Add a CurseForge API key from the CurseForge for Studios console to
                    offer CurseForge mods and Bukkit plugins on the Plugins &amp; mods page too. Only files whose
                    authors allow third-party downloads can be installed.
                </p>
                <form.AppField name='curseforge_key'>
                    {(field) => (
                        <field.TextField
                            type='password'
                            id='curseforge_key'
                            label='CurseForge API key'
                            autoComplete='off'
                            description={keyDescription(settings)}
                            disabled={settings.set_in_env}
                        />
                    )}
                </form.AppField>
            </TitledGreyBox>
            <div className='mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end'>
                {settings.curseforge_key_set && !settings.set_in_env && (
                    <Button.Danger
                        isSecondary
                        disabled={update.isPending}
                        onClick={() => update.mutate({ body: { clear_curseforge_key: true } })}
                    >
                        Remove key
                    </Button.Danger>
                )}
                <form.AppForm>
                    <form.SubmitButton disabled={settings.set_in_env}>Save Changes</form.SubmitButton>
                </form.AppForm>
            </div>
        </Form>
    );
}

/** The "Plugins" settings tab: the CurseForge API key of the one-click plugin installer. */
export default function PluginSettingsForm() {
    const { data, error, refetch } = useAdminPluginSettings();

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => void refetch()} />;
    }

    return data ? <PluginSettingsFields settings={data} /> : <Spinner size='large' centered />;
}
