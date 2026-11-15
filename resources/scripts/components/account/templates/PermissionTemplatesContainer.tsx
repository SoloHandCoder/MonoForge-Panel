import {
    useCreatePermissionTemplate,
    useDeletePermissionTemplate,
    usePermissionTemplates,
    useUpdatePermissionTemplate,
} from '@/api/account/permission-templates/queries';
import { httpErrorToHuman } from '@/api/http';
import PageContentBlock from '@/components/elements/PageContentBlock';
import PageHeading from '@/components/elements/PageHeading';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import TemplateManager, {
    type ManagedTemplate,
    type TemplateValues,
} from '@/components/elements/permissionTemplates/TemplateManager';
import { succeeded } from '@/components/elements/permissionTemplates/succeeded';

/** The user's own subuser permission templates, offered with the built-in and global ones when inviting. */
export default function PermissionTemplatesContainer() {
    const { data, error, refetch } = usePermissionTemplates();
    const create = useCreatePermissionTemplate();
    const update = useUpdatePermissionTemplate();
    const remove = useDeletePermissionTemplate();

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => refetch()} />;
    }

    // A personal template's key is "personal:<id>".
    const templates: ManagedTemplate[] = (data?.attributes.templates ?? []).flatMap((template) => {
        const id = Number(template.key.slice('personal:'.length));

        return template.source === 'personal' && Number.isInteger(id) && id > 0
            ? [{ id, name: template.name, permissions: template.permissions }]
            : [];
    });
    const body = (values: TemplateValues) => ({ name: values.name, permissions: values.permissions });

    return (
        <PageContentBlock title='Permission templates'>
            <PageHeading
                title='Permission templates'
                description='Save sets of permissions to pick when you invite someone to a server. You can still change the checkboxes before inviting, and you can only grant what you hold on that server.'
            />
            <div className='mt-6'>
                {data ? (
                    <TemplateManager
                        templates={templates}
                        limit={data.attributes.personal_limit}
                        busy={create.isPending || update.isPending || remove.isPending}
                        description='Only you see these. The built-in templates (Viewer, Moderator, Builder and Co-owner) and any your host added are offered as well.'
                        onCreate={(values) => succeeded(() => create.mutateAsync({ body: body(values) }))}
                        onUpdate={(id, values) =>
                            succeeded(() => update.mutateAsync({ path: { template: id }, body: body(values) }))
                        }
                        onDelete={(id) => succeeded(() => remove.mutateAsync({ path: { template: id } }))}
                    />
                ) : (
                    <Spinner size='large' centered />
                )}
            </div>
        </PageContentBlock>
    );
}
