import { useId } from 'react';
import { useSystemPermissions } from '@/api/system/queries';
import Checkbox from '@/components/ui/Checkbox';
import Spinner from '@/components/elements/Spinner';
import { permissionGroupRows, setPermissionsSelected } from '@/views/server/permissionSelection';

interface Props {
    value: readonly string[];
    onChange: (permissions: string[]) => void;
    disabled?: boolean;
}

/**
 * Every subuser permission as a checkbox, grouped like the subuser form. Connecting to the console
 * is left out: every subuser gets it.
 */
export default function PermissionChecklist({ value, onChange, disabled = false }: Props) {
    const id = useId();
    const { data, isLoading } = useSystemPermissions();
    const selected = new Set(value);

    if (isLoading || !data) {
        return <Spinner size='small' />;
    }

    const groups = Object.entries(data.attributes.permissions).filter(([group]) => group !== 'websocket');

    return (
        <div className='grid gap-4 sm:grid-cols-2 lg:grid-cols-3'>
            {groups.map(([group, { description, keys }]) => {
                const rows = permissionGroupRows(group, keys);

                return (
                    <fieldset key={group} className='min-w-0 rounded-md border border-border p-3'>
                        <legend className='px-1 text-xs font-semibold uppercase tracking-wide text-foreground'>
                            {group}
                        </legend>
                        <p className='mb-2 text-xs text-muted-foreground'>{description}</p>
                        <ul className='space-y-1.5'>
                            {rows.map((row) => {
                                const checkboxId = `${id}-${row.permission}`;

                                return (
                                    <li key={row.permission} className='flex items-start gap-2'>
                                        <Checkbox
                                            id={checkboxId}
                                            checked={selected.has(row.permission)}
                                            disabled={disabled}
                                            onChange={(checked) =>
                                                onChange(setPermissionsSelected([...value], [row.permission], checked))
                                            }
                                            className='mt-0.5 size-4 shrink-0'
                                        />
                                        <label htmlFor={checkboxId} className='min-w-0 text-sm' title={row.description}>
                                            {row.key}
                                        </label>
                                    </li>
                                );
                            })}
                        </ul>
                    </fieldset>
                );
            })}
        </div>
    );
}
