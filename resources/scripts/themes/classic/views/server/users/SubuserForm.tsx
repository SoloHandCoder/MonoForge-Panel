import { useId, useState, type ComponentProps } from 'react';
import {
    Button,
    Card,
    Checkbox,
    cn,
    Dialog,
    Form,
    Input,
    Label,
    Select,
    setPermissionsSelected,
    SpinnerOverlay,
    useAppForm,
    type AppForm,
    type SubuserFormModel,
    type SubuserPermission,
    type SubuserPermissionGroup,
    type SubuserTemplateOption,
} from '@/sdk';
import { Slot, type ViewProps } from '@/sdk/theme';

/** `friend` is the UUID of the friend picked in place of an email, or empty. */
type Values = { email: string; permissions: string[]; friend: string };
type SubuserAppForm = AppForm<Values>;

interface Selection {
    selected: readonly string[];
    select: (permissions: string[]) => void;
    /** The viewer may change the selection right now. */
    enabled: boolean;
}

const PermissionRowContainer = ({ className, ...props }: ComponentProps<'label'> & { htmlFor: string }) => (
    <label
        className={cn(
            'flex items-center border border-transparent rounded-sm md:p-2 transition-colors duration-75 normal-case',
            '[&:not(.disabled)]:cursor-pointer [&:not(.disabled):hover]:border-border [&:not(.disabled):hover]:bg-background',
            'not-first-of-type:mt-4 sm:not-first-of-type:mt-2',
            '[&.disabled]:opacity-50',
            className
        )}
        {...props}
    />
);

function PermissionRow({
    permission,
    disabled,
    selection,
}: {
    permission: SubuserPermission;
    disabled: boolean;
    selection: Selection;
}) {
    const id = `permission_${permission.key}`;

    return (
        <PermissionRowContainer htmlFor={id} className={disabled ? 'disabled' : undefined}>
            <div className='p-2'>
                <Checkbox
                    id={id}
                    checked={selection.selected.includes(permission.key)}
                    disabled={disabled}
                    onChange={(checked) =>
                        selection.select(setPermissionsSelected(selection.selected, [permission.key], checked))
                    }
                    className='w-5 h-5 mr-2'
                />
            </div>
            <div className='flex-1'>
                <Label as='p' className='font-medium'>
                    {permission.label}
                </Label>
                {permission.description.length > 0 && (
                    <p className='text-xs text-muted-foreground mt-1'>{permission.description}</p>
                )}
            </div>
        </PermissionRowContainer>
    );
}

/** Checks every permission given, half-checked while only some are selected. */
function SelectAll({
    permissions,
    selection,
    label,
    disabled = false,
}: {
    permissions: readonly string[];
    selection: Selection;
    label: string;
    disabled?: boolean;
}) {
    const selected = new Set(selection.selected);
    const all = permissions.length > 0 && permissions.every((permission) => selected.has(permission));

    return (
        <Checkbox
            aria-label={label}
            checked={all}
            indeterminate={!all && permissions.some((permission) => selected.has(permission))}
            disabled={disabled}
            onChange={(checked) => selection.select(setPermissionsSelected(selection.selected, permissions, checked))}
            className='w-5 h-5'
        />
    );
}

function PermissionGroup({
    group,
    model,
    selection,
    className,
}: {
    group: SubuserPermissionGroup;
    model: SubuserFormModel;
    selection: Selection;
    className?: string;
}) {
    const editable = group.permissions.filter((permission) => permission.editable).map((permission) => permission.key);

    return (
        <Card
            className={className}
            title={
                <div className='flex items-center'>
                    <p className='text-sm uppercase flex-1'>{group.name}</p>
                    {model.canEdit && editable.length > 0 && (
                        <SelectAll
                            permissions={editable}
                            selection={selection}
                            label={`Select all ${group.name} permissions`}
                        />
                    )}
                </div>
            }
        >
            <p className='text-sm text-muted-foreground mb-4'>{group.description}</p>
            {group.permissions.map((permission) => (
                <PermissionRow
                    key={`permission_${permission.key}`}
                    permission={permission}
                    disabled={!model.canEdit || !permission.editable}
                    selection={selection}
                />
            ))}
        </Card>
    );
}

const TEMPLATE_SOURCES: readonly [SubuserTemplateOption['source'], string][] = [
    ['builtin', 'Built-in'],
    ['global', 'From your host'],
    ['personal', 'Your templates'],
];

/** Picks a friend to invite in place of typing their email. */
function FriendPicker({ form, model }: { form: SubuserAppForm; model: SubuserFormModel }) {
    const id = useId();

    if (model.friends.length === 0) {
        return null;
    }

    return (
        <form.Subscribe selector={(state) => state.values.friend}>
            {(friend) => (
                <div className='mt-6'>
                    <Label htmlFor={id}>Invite a friend</Label>
                    <div className='mt-1 max-w-md'>
                        <Select
                            id={id}
                            options={[
                                { value: '', label: 'Someone else, by email' },
                                ...model.friends.map((item) => ({ value: item.uuid, label: item.username })),
                            ]}
                            value={friend}
                            placeholder='Someone else, by email'
                            searchPlaceholder='Search friends'
                            emptyMessage='No friend has that name.'
                            onChange={(value) => form.setFieldValue('friend', String(value))}
                        />
                    </div>
                </div>
            )}
        </form.Subscribe>
    );
}

/** Fills the checkboxes from a template. The checkboxes stay editable afterwards. */
function TemplatePicker({
    model,
    selection,
}: {
    model: SubuserFormModel;
    selection: { selected: readonly string[]; select: (permissions: string[]) => void; enabled: boolean };
}) {
    const id = useId();
    const [picked, setPicked] = useState('');
    const template = model.templates.find((item) => item.key === picked);

    if (model.templates.length === 0) {
        return null;
    }

    return (
        <div className='mt-6'>
            <Label htmlFor={id}>Start from a template</Label>
            <div className='mt-1 max-w-md'>
                <Select
                    id={id}
                    groups={TEMPLATE_SOURCES.flatMap(([source, label]) => {
                        const options = model.templates
                            .filter((item) => item.source === source)
                            .map((item) => ({ value: item.key, label: item.name }));

                        return options.length > 0 ? [{ label, options }] : [];
                    })}
                    value={picked}
                    disabled={!selection.enabled}
                    placeholder='Choose a template'
                    searchPlaceholder='Search templates'
                    emptyMessage='No template has that name.'
                    onChange={(value) => {
                        const key = String(value);

                        setPicked(key);
                        selection.select(model.applyTemplate(selection.selected, key));
                    }}
                />
            </div>
            <p className='mt-1 text-xs text-muted-foreground' aria-live='polite'>
                {template && template.withheld > 0
                    ? `${template.withheld} of this template's permissions are ones you do not hold, so they were left out.`
                    : 'A template only fills in the checkboxes below; you can still change them.'}
            </p>
        </div>
    );
}

/** Saves the checked permissions as one of the viewer's own templates. */
function SaveAsTemplate({ model, selected }: { model: SubuserFormModel; selected: readonly string[] }) {
    const id = useId();
    const [name, setName] = useState('');
    const trimmed = name.trim();

    return (
        <div className='mt-4 flex flex-col gap-2 sm:flex-row sm:items-end'>
            <div className='min-w-0 flex-1 sm:max-w-xs'>
                <Label htmlFor={id}>Save these permissions as a template</Label>
                <Input
                    id={id}
                    className='mt-1'
                    value={name}
                    maxLength={64}
                    placeholder='Template name'
                    onChange={(event) => setName(event.currentTarget.value)}
                />
            </div>
            <Button
                type='button'
                color='grey'
                isSecondary
                disabled={trimmed.length === 0 || model.savingTemplate}
                isLoading={model.savingTemplate}
                onClick={() => {
                    void model.saveTemplate(trimmed, selected).then((saved) => {
                        if (saved) {
                            setName('');
                        }
                    });
                }}
            >
                Save template
            </Button>
        </div>
    );
}

function SubmitButton({ form, model }: { form: SubuserAppForm; model: SubuserFormModel }) {
    return (
        <form.AppForm>
            <form.SubmitButton className='w-full sm:w-auto'>{model.subuser ? 'Save' : 'Invite User'}</form.SubmitButton>
        </form.AppForm>
    );
}

function FormContent({
    form,
    model,
    submitting,
}: {
    form: SubuserAppForm;
    model: SubuserFormModel;
    submitting: boolean;
}) {
    if (model.loading) {
        return <SpinnerOverlay visible />;
    }

    if (model.failed) {
        return null;
    }

    return (
        <form.Subscribe selector={(state) => state.values.permissions}>
            {(selected) => {
                const selection: Selection = {
                    selected,
                    select: (permissions) => form.setFieldValue('permissions', permissions),
                    enabled: model.canEdit && !submitting && !model.saving,
                };
                const title = model.subuser
                    ? `${model.canEdit ? 'Modify' : 'View'} permissions for ${model.subuser.email}`
                    : 'Create new subuser';

                return (
                    <Form form={form}>
                        <SpinnerOverlay visible={submitting || model.saving} />
                        <div className='flex justify-between'>
                            <h2 className='text-2xl'>{title}</h2>
                            <div>
                                <SubmitButton form={form} model={model} />
                            </div>
                        </div>
                        {model.restricted && (
                            <div className='mt-4 pl-4 py-2 border-l-4 border-accent'>
                                <p className='text-sm text-muted-foreground'>
                                    Only permissions which your account is currently assigned may be selected when
                                    creating or modifying other users.
                                </p>
                            </div>
                        )}
                        {!model.subuser && <FriendPicker form={form} model={model} />}
                        {!model.subuser && (
                            <form.Subscribe selector={(state) => state.values.friend}>
                                {(friend) =>
                                    friend === '' && (
                                        <div className='mt-6'>
                                            <form.AppField
                                                name='email'
                                                validators={{
                                                    onChangeListenTo: ['friend'],
                                                    onChange: ({ value, fieldApi }) =>
                                                        fieldApi.form.getFieldValue('friend') === ''
                                                            ? model.validateEmail(value)
                                                            : undefined,
                                                }}
                                            >
                                                {(field) => (
                                                    <field.TextField
                                                        label='User Email'
                                                        description='Enter the email address of the user you wish to invite as a subuser for this server.'
                                                    />
                                                )}
                                            </form.AppField>
                                        </div>
                                    )
                                }
                            </form.Subscribe>
                        )}
                        {model.canEdit && <TemplatePicker model={model} selection={selection} />}
                        <Slot
                            name='server.users.permissions.before'
                            data={model.permissionSlot(selected, submitting, selection.select)}
                        />
                        <Card
                            className='mt-6'
                            title={
                                <div className='flex items-center justify-between'>
                                    <span className='text-sm uppercase'>All Permissions</span>
                                    {model.editable.length > 0 && (
                                        <div className='flex items-center gap-2 text-sm'>
                                            <SelectAll
                                                permissions={model.editable}
                                                selection={selection}
                                                label='Select all permissions'
                                                disabled={!selection.enabled}
                                            />
                                        </div>
                                    )}
                                </div>
                            }
                        >
                            <p className='m-0 text-sm text-muted-foreground'>
                                Only select this if <strong>you entirely trust the user you are inviting</strong> with
                                all available permissions!
                            </p>
                        </Card>
                        <div className='my-6'>
                            {model.groups.map((group, index) => (
                                <PermissionGroup
                                    key={`permission_${group.name}`}
                                    group={group}
                                    model={model}
                                    selection={selection}
                                    className={index > 0 ? 'mt-4' : undefined}
                                />
                            ))}
                        </div>
                        {model.canEdit && <SaveAsTemplate model={model} selected={selected} />}
                        {model.canEdit && (
                            <div className='pb-6 flex justify-end'>
                                <SubmitButton form={form} model={model} />
                            </div>
                        )}
                    </Form>
                );
            }}
        </form.Subscribe>
    );
}

/** Invites a subuser, or edits one in a dialog when the view is given `onClose`. */
export default function SubuserForm({ model, onClose, className }: ViewProps<'server.subuserForm'>) {
    const defaultValues: Values = {
        email: model.values.email,
        permissions: [...model.values.permissions],
        friend: model.values.friend ?? '',
    };
    const form = useAppForm({
        defaultValues,
        onSubmit: async ({ value }) => {
            await model.save({ ...value, friend: value.friend === '' ? null : value.friend });
        },
    });

    return (
        <form.Subscribe selector={(state) => state.isSubmitting}>
            {(submitting) => {
                const content = (
                    <div className={className}>
                        <FormContent form={form} model={model} submitting={submitting} />
                    </div>
                );

                if (!onClose) {
                    return content;
                }

                const busy = submitting || model.saving;

                return (
                    <Dialog open onClose={onClose} preventExternalClose={busy} hideCloseIcon={busy}>
                        {content}
                    </Dialog>
                );
            }}
        </form.Subscribe>
    );
}
