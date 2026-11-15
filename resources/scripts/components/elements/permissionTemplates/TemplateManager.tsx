import { useState } from 'react';
import { Pencil } from 'lucide-react';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import { TextInput } from '@/components/form/controls';
import PermissionChecklist from './PermissionChecklist';

export interface ManagedTemplate {
    id: number;
    name: string;
    permissions: readonly string[];
}

export interface TemplateValues {
    name: string;
    permissions: string[];
}

interface Props {
    templates: readonly ManagedTemplate[];
    /** How many templates may be kept; the form hides once there are this many. */
    limit: number;
    busy: boolean;
    /** Resolve whether the template was saved; a failure has been reported. */
    onCreate: (values: TemplateValues) => Promise<boolean>;
    onUpdate: (id: number, values: TemplateValues) => Promise<boolean>;
    onDelete: (id: number) => Promise<boolean>;
    /** Says who sees the templates, above the list. */
    description: string;
}

const NAME_MAX_LENGTH = 64;
/** Connecting to the console is always granted, so it never counts as one of a template's permissions. */
const countPermissions = (permissions: readonly string[]) =>
    permissions.filter((permission) => permission !== 'websocket.connect').length;

/**
 * Lists permission templates and creates, edits or deletes them. A template only pre-fills the
 * subuser form, so changing one never changes a subuser.
 */
export default function TemplateManager({ templates, limit, busy, onCreate, onUpdate, onDelete, description }: Props) {
    const [editing, setEditing] = useState<number | null>(null);
    const [name, setName] = useState('');
    const [permissions, setPermissions] = useState<string[]>([]);

    const reset = () => {
        setEditing(null);
        setName('');
        setPermissions([]);
    };

    const edit = (template: ManagedTemplate) => {
        setEditing(template.id);
        setName(template.name);
        setPermissions(template.permissions.filter((permission) => permission !== 'websocket.connect'));
    };

    const trimmed = name.trim();
    const full = editing === null && templates.length >= limit;
    const canSave = trimmed.length > 0 && trimmed.length <= NAME_MAX_LENGTH && !busy && !full;

    const save = async () => {
        const values = { name: trimmed, permissions };
        const saved = editing === null ? await onCreate(values) : await onUpdate(editing, values);

        if (saved) {
            reset();
        }
    };

    return (
        <div className='space-y-6'>
            <TitledGreyBox title='Templates'>
                <p className='mb-4 text-sm text-muted-foreground'>{description}</p>
                {templates.length === 0 ? (
                    <p className='text-sm text-muted-foreground'>There are no templates yet.</p>
                ) : (
                    <ul className='divide-y divide-border'>
                        {templates.map((template) => (
                            <li key={template.id} className='flex flex-wrap items-center gap-3 py-3 first:pt-0'>
                                <div className='min-w-0 flex-1'>
                                    <p className='truncate text-sm font-medium text-foreground'>{template.name}</p>
                                    <p className='text-xs text-muted-foreground'>
                                        {countPermissions(template.permissions)} permissions
                                    </p>
                                </div>
                                <Button
                                    type='button'
                                    size='xsmall'
                                    color='grey'
                                    disabled={busy}
                                    onClick={() => edit(template)}
                                    aria-label={`Edit ${template.name}`}
                                >
                                    <Pencil className='size-3.5' aria-hidden />
                                    <span className='ml-1'>Edit</span>
                                </Button>
                                <Dialog.ConfirmTrigger
                                    title='Delete template'
                                    confirm='Delete'
                                    onConfirmed={(_event, close) =>
                                        onDelete(template.id).then((deleted) => {
                                            if (deleted && editing === template.id) {
                                                reset();
                                            }

                                            close();
                                        })
                                    }
                                    trigger={({ onClick }) => (
                                        <Button
                                            type='button'
                                            size='xsmall'
                                            color='red'
                                            isSecondary
                                            disabled={busy}
                                            onClick={onClick}
                                            aria-label={`Delete ${template.name}`}
                                        >
                                            Delete
                                        </Button>
                                    )}
                                >
                                    Delete the &ldquo;{template.name}&rdquo; template? Subusers invited with it keep
                                    their permissions.
                                </Dialog.ConfirmTrigger>
                            </li>
                        ))}
                    </ul>
                )}
            </TitledGreyBox>
            <TitledGreyBox title={editing === null ? 'New template' : 'Edit template'}>
                {full ? (
                    <p className='text-sm text-muted-foreground'>
                        You have {limit} templates, the most you can keep. Delete one to add another.
                    </p>
                ) : (
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (canSave) {
                                void save();
                            }
                        }}
                    >
                        <label htmlFor='permission-template-name' className='mb-1 block text-sm font-medium'>
                            Name
                        </label>
                        <TextInput
                            id='permission-template-name'
                            value={name}
                            maxLength={NAME_MAX_LENGTH}
                            onChange={(event) => setName(event.currentTarget.value)}
                            placeholder='Event staff'
                            className='max-w-sm'
                        />
                        <p className='mb-2 mt-5 text-sm font-medium'>Permissions</p>
                        <PermissionChecklist value={permissions} onChange={setPermissions} disabled={busy} />
                        <div className='mt-5 flex flex-wrap justify-end gap-2'>
                            {editing !== null && (
                                <Button type='button' color='grey' isSecondary onClick={reset} disabled={busy}>
                                    Cancel
                                </Button>
                            )}
                            <Button type='submit' disabled={!canSave} isLoading={busy}>
                                {editing === null ? 'Create template' : 'Save template'}
                            </Button>
                        </div>
                    </form>
                )}
            </TitledGreyBox>
        </div>
    );
}
