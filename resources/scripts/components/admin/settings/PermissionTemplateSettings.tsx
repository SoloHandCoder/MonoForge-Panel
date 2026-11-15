import {
    GLOBAL_TEMPLATE_LIMIT,
    useAdminPermissionTemplates,
    useCreateAdminPermissionTemplate,
    useDeleteAdminPermissionTemplate,
    useUpdateAdminPermissionTemplate,
} from '@/api/admin/settings/permissionTemplates';
import { httpErrorToHuman } from '@/api/http';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import TemplateManager, { type TemplateValues } from '@/components/elements/permissionTemplates/TemplateManager';
import { succeeded } from '@/components/elements/permissionTemplates/succeeded';

/** Admin, Settings, Templates: the global subuser permission templates. */
export default function PermissionTemplateSettings() {
    const { data, error, refetch } = useAdminPermissionTemplates();
    const create = useCreateAdminPermissionTemplate();
    const update = useUpdateAdminPermissionTemplate();
    const remove = useDeleteAdminPermissionTemplate();

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => refetch()} />;
    }

    if (!data) {
        return <Spinner size='large' centered />;
    }

    const body = (values: TemplateValues) => ({ name: values.name, permissions: values.permissions });

    return (
        <TemplateManager
            templates={data}
            limit={GLOBAL_TEMPLATE_LIMIT}
            busy={create.isPending || update.isPending || remove.isPending}
            description='Every user can pick these when inviting a subuser, next to the built-in Viewer, Moderator, Builder and Co-owner. A template only fills in the checkboxes: users can change them, can only grant what they hold, and editing a template never changes existing subusers.'
            onCreate={(values) => succeeded(() => create.mutateAsync({ body: body(values) }))}
            onUpdate={(id, values) =>
                succeeded(() => update.mutateAsync({ path: { permissionTemplate_id: id }, body: body(values) }))
            }
            onDelete={(id) => succeeded(() => remove.mutateAsync({ path: { permissionTemplate_id: id } }))}
        />
    );
}
