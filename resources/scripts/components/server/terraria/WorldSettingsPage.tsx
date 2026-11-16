import { useId, useState } from 'react';
import { toast } from 'sonner';

import { useCurrentServerUuid } from '@/api/server/queries';
import { useServerStartup, useUpdateStartupVariable } from '@/api/server/startup/queries';
import { httpErrorToHuman } from '@/api/http';
import { Alert } from '@/components/elements/alert';
import Button from '@/components/elements/Button';
import PageHeading from '@/components/elements/PageHeading';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Spinner from '@/components/elements/Spinner';
import { TextInput } from '@/components/form/controls';
import { useServerPermission } from '@/sdk/core';
import { worldFieldError, worldFields, type WorldField } from './worldSettings';

function helpText(field: WorldField, editable: boolean, error: string | null): string {
    if (error) {
        return error;
    }

    return editable
        ? `Saved in the ${field.variable.env_variable} startup variable.`
        : 'Only the server owner or an administrator can change this.';
}

function WorldFieldRow({ uuid, field, canEdit }: { uuid: string; field: WorldField; canEdit: boolean }) {
    const id = useId();
    const [value, setValue] = useState(field.value);
    const { mutateAsync, isPending } = useUpdateStartupVariable(uuid);
    const editable = canEdit && field.variable.is_editable;
    const error = worldFieldError(field, value);
    const changed = value !== field.value;
    const { definition } = field;

    const save = async () => {
        if (error || !changed) {
            return;
        }

        try {
            await mutateAsync({ path: { server_uuid: uuid }, body: { key: field.variable.env_variable, value } });
            toast.success(`${definition.label} saved`, { description: 'Restart the server to use it.' });
        } catch {
            // The mutation shows the error.
        }
    };

    return (
        <form
            className='flex flex-col gap-2 sm:flex-row sm:items-end'
            onSubmit={(event) => {
                event.preventDefault();
                void save();
            }}
        >
            <div className='min-w-0 flex-1'>
                <label htmlFor={id} className='mb-1 block text-sm font-medium text-foreground'>
                    {definition.label}
                </label>
                {definition.choices ? (
                    <select
                        id={id}
                        disabled={!editable}
                        className='h-10 w-full rounded-md border border-border bg-background px-2 text-sm text-foreground disabled:opacity-60'
                        value={value}
                        onChange={(event) => setValue(event.currentTarget.value)}
                    >
                        {!definition.choices.some((choice) => choice.value === value) && (
                            <option value={value}>{value === '' ? 'Not set' : value}</option>
                        )}
                        {definition.choices.map((choice) => (
                            <option key={choice.value} value={choice.value}>
                                {choice.label}
                            </option>
                        ))}
                    </select>
                ) : (
                    <TextInput
                        id={id}
                        type={definition.kind === 'password' ? 'password' : 'text'}
                        inputMode={definition.kind === 'players' ? 'numeric' : undefined}
                        autoComplete={definition.kind === 'password' ? 'new-password' : 'off'}
                        disabled={!editable}
                        value={value}
                        $hasError={changed && !!error}
                        aria-invalid={changed && !!error}
                        aria-describedby={`${id}-help`}
                        onChange={(event) => setValue(event.currentTarget.value)}
                    />
                )}
                <p id={`${id}-help`} className='mt-1 text-xs text-muted-foreground'>
                    {helpText(field, editable, changed ? error : null)}
                </p>
            </div>
            {editable && (
                <Button type='submit' disabled={!changed || !!error} isLoading={isPending} className='sm:mb-5'>
                    Save
                </Button>
            )}
        </form>
    );
}

/** Terraria's world settings, edited through the egg's startup variables. */
export default function WorldSettingsPage() {
    const uuid = useCurrentServerUuid() ?? '';
    const canEdit = useServerPermission('startup.update');
    const startup = useServerStartup(uuid);
    const fields = worldFields(startup.data?.data ?? []);

    return (
        <ServerContentBlock title='World'>
            <PageHeading
                title='World'
                description="Your Terraria world's size, difficulty, player limit and password. Changes apply the next time the server starts; size and difficulty only shape a newly created world."
            />
            <div className='mt-6 space-y-4'>
                {startup.isPending && <Spinner size='large' centered />}
                {startup.error && (
                    <Alert type='danger' title='Could not load the world settings'>
                        {httpErrorToHuman(startup.error)}
                    </Alert>
                )}
                {startup.data && fields.length === 0 && (
                    <Alert type='info' title='No world settings'>
                        This server&apos;s egg has no world settings the panel knows. Use the Startup page instead.
                    </Alert>
                )}
                {fields.length > 0 && (
                    <section aria-label='World settings' className='space-y-4 rounded-lg bg-card p-4 shadow-sm'>
                        {fields.map((field) => (
                            <WorldFieldRow
                                key={`${field.variable.env_variable}:${field.value}`}
                                uuid={uuid}
                                field={field}
                                canEdit={canEdit}
                            />
                        ))}
                    </section>
                )}
            </div>
        </ServerContentBlock>
    );
}
