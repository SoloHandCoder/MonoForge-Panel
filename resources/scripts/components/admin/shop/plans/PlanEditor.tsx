import { useStore } from '@tanstack/react-form';
import { useNavigate, useParams, useSearch } from '@tanstack/react-router';
import { ArrowLeft, Cpu, HardDrive, MemoryStick, Tags } from 'lucide-react';
import { useMemo } from 'react';
import { httpErrorToHuman } from '@/api/http';
import {
    useAdminShopCategories,
    useAdminShopMultipliers,
    useAdminShopOptions,
    useAdminShopPeriods,
    useAdminShopPlans,
    useSaveShopPlan,
    type ShopCategory,
    type ShopMultiplier,
    type ShopNodeOption,
    type ShopOptions,
    type ShopPeriod,
    type ShopPlan,
    type ShopPlanInput,
    type ShopResources,
} from '@/api/admin/shop/queries';
import AdminContentBlock from '@/components/admin/AdminContentBlock';
import { formatCpu, formatMegabytes } from '@/components/admin/shop/format';
import Badge from '@/components/elements/Badge';
import { RouterLinkButton } from '@/components/elements/Button';
import Card from '@/components/elements/Card';
import Label from '@/components/elements/Label';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import { Form, useAppForm } from '@/components/form';
import Select, { type SelectGroup } from '@/components/ui/Select';
import { formatMultiplier, formatTokens, modifierLabel, periodTotal } from '@/lib/shopPricing';

interface PlanFormValues {
    category_id: number | null;
    name: string;
    description: string;
    visible: boolean;
    price_tokens: number | null;
    memory: number | null;
    swap: number | null;
    disk: number | null;
    cpu: number | null;
    io: number | null;
    databases: number | null;
    backups: number | null;
    allocations: number | null;
    egg_ids: number[];
    restrict_nodes: boolean;
    node_ids: number[];
}

const newPlanResources: ShopResources = {
    memory: 2048,
    swap: 0,
    disk: 10240,
    io: 500,
    cpu: 100,
    databases: 0,
    backups: 1,
    allocations: 0,
};

const formValues = (plan: ShopPlan | undefined, categoryId: number | null): PlanFormValues => {
    if (!plan) {
        return {
            ...newPlanResources,
            category_id: categoryId,
            name: '',
            description: '',
            visible: true,
            price_tokens: null,
            egg_ids: [],
            restrict_nodes: false,
            node_ids: [],
        };
    }

    return {
        ...plan.resources,
        category_id: plan.category_id,
        name: plan.name,
        description: plan.description ?? '',
        visible: plan.visible,
        price_tokens: plan.price_tokens,
        egg_ids: plan.egg_ids,
        restrict_nodes: plan.node_ids !== null,
        node_ids: plan.node_ids ?? [],
    };
};

/** Each resource field, with what an emptied field is sent as. */
const resourceInput = (values: PlanFormValues): ShopResources => ({
    memory: values.memory ?? 0,
    swap: values.swap ?? 0,
    disk: values.disk ?? 0,
    io: values.io ?? 500,
    cpu: values.cpu ?? 0,
    databases: values.databases ?? 0,
    backups: values.backups ?? 0,
    allocations: values.allocations ?? 0,
});

const toInput = (values: PlanFormValues, sort: number): ShopPlanInput => ({
    ...resourceInput(values),
    category_id: values.category_id ?? 0,
    name: values.name.trim(),
    description: values.description.trim() || null,
    visible: values.visible,
    sort,
    price_tokens: values.price_tokens ?? 0,
    egg_ids: values.egg_ids,
    node_ids: values.restrict_nodes ? values.node_ids : [],
});

const numberRequired =
    (label: string, min: number) =>
    ({ value }: { value: number | null }) => {
        if (value === null) {
            return `${label} is required.`;
        }

        return value < min ? `${label} must be at least ${min}.` : undefined;
    };

/** Eggs grouped by their first tag, which replaced nests as the way eggs are grouped. */
const eggGroups = (options: ShopOptions): SelectGroup[] => {
    const groups = new Map<string, SelectGroup>();

    for (const egg of options.eggs) {
        const label = egg.tags[0]?.name ?? 'Other';
        const group = groups.get(label) ?? { label, options: [] };

        group.options.push({ value: egg.id, label: egg.name, textLabel: egg.name });
        groups.set(label, group);
    }

    return [...groups.values()].sort((a, b) => a.label.localeCompare(b.label));
};

const nodeLabel = (node: ShopNodeOption): string => {
    const notes = [!node.shop_enabled && 'not in the shop', node.maintenance && 'maintenance'].filter(Boolean);

    return notes.length > 0 ? `${node.name} (${notes.join(', ')})` : node.name;
};

const nodeGroups = (options: ShopOptions): SelectGroup[] =>
    options.locations
        .map((location) => ({
            id: `location-${location.id}`,
            label: location.long ? `${location.short} · ${location.long}` : location.short,
            options: options.nodes
                .filter((node) => node.location_id === location.id)
                .map((node) => ({
                    value: node.id,
                    textLabel: node.name,
                    label: nodeLabel(node),
                })),
        }))
        .filter((group) => group.options.length > 0);

/** The multipliers of the nodes a plan may use: the cheapest and dearest. */
const multiplierRange = (
    options: ShopOptions,
    multipliers: ShopMultiplier[],
    restrict: boolean,
    nodeIds: number[]
): { min: number; max: number; nodes: number } => {
    // The store only sells on public nodes that are available in the shop.
    const nodes = options.nodes.filter(
        (node) => node.public && node.shop_enabled && (!restrict || nodeIds.includes(node.id))
    );
    const of = (scope: 'node' | 'location', id: number) =>
        multipliers.find((multiplier) => multiplier.scope === scope && multiplier.scope_id === id)?.multiplier;
    const values = nodes.map((node) => of('node', node.id) ?? of('location', node.location_id) ?? 1);

    return values.length > 0
        ? { min: Math.min(...values), max: Math.max(...values), nodes: values.length }
        : { min: 1, max: 1, nodes: 0 };
};

interface PreviewProps {
    values: PlanFormValues;
    periods: ShopPeriod[];
    multipliers: ShopMultiplier[];
    options: ShopOptions;
}

function PricePreview({ values, periods, multipliers, options }: PreviewProps) {
    const price = values.price_tokens ?? 0;
    const range = multiplierRange(options, multipliers, values.restrict_nodes, values.node_ids);
    const enabled = periods.filter((period) => period.enabled);
    const monthly = periodTotal(price, { days: 30, price_modifier_percent: 0 }, range.min);

    return (
        <Card title='Store preview'>
            <p className='text-xs uppercase tracking-wide text-muted-foreground'>From</p>
            <p className='mt-1 text-3xl font-semibold tabular-nums text-foreground'>
                {formatTokens(monthly)}
                <span className='text-base font-normal text-muted-foreground'> / month</span>
            </p>
            {range.max !== range.min && (
                <p className='mt-1 text-xs text-muted-foreground'>
                    Up to {formatTokens(periodTotal(price, { days: 30, price_modifier_percent: 0 }, range.max))} on{' '}
                    {formatMultiplier(range.max)} nodes.
                </p>
            )}
            <ul className='mt-4 grid gap-1 text-sm'>
                <li className='flex items-center gap-2 text-muted-foreground'>
                    <MemoryStick className='h-4 w-4' aria-hidden /> {formatMegabytes(values.memory ?? 0)} RAM
                </li>
                <li className='flex items-center gap-2 text-muted-foreground'>
                    <HardDrive className='h-4 w-4' aria-hidden /> {formatMegabytes(values.disk ?? 0)} disk
                </li>
                <li className='flex items-center gap-2 text-muted-foreground'>
                    <Cpu className='h-4 w-4' aria-hidden /> {formatCpu(values.cpu ?? 0)}
                </li>
                <li className='flex items-center gap-2 text-muted-foreground'>
                    <Tags className='h-4 w-4' aria-hidden /> {values.egg_ids.length}{' '}
                    {values.egg_ids.length === 1 ? 'egg' : 'eggs'} · {range.nodes}{' '}
                    {range.nodes === 1 ? 'node' : 'nodes'}
                </li>
            </ul>
            <div className='mt-5 border-t border-border pt-4'>
                <p className='mb-2 text-xs uppercase tracking-wide text-muted-foreground'>Per billing period</p>
                {enabled.length === 0 ? (
                    <p className='text-sm text-muted-foreground'>No billing period is enabled yet.</p>
                ) : (
                    <ul className='grid gap-2'>
                        {enabled.map((period) => (
                            <li key={period.id} className='flex items-baseline justify-between gap-3 text-sm'>
                                <span className='text-foreground'>
                                    {period.name}{' '}
                                    <span className='text-xs text-muted-foreground'>
                                        {period.days} days · {modifierLabel(period.price_modifier_percent)}
                                    </span>
                                </span>
                                <span className='tabular-nums text-foreground'>
                                    {formatTokens(periodTotal(price, period, range.min))}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            {range.nodes === 0 && (
                <p className='mt-4 text-sm text-destructive'>No node can host this plan, so the store hides it.</p>
            )}
        </Card>
    );
}

interface EditorProps {
    plan?: ShopPlan;
    categories: ShopCategory[];
    periods: ShopPeriod[];
    multipliers: ShopMultiplier[];
    options: ShopOptions;
    initialCategory: number | null;
}

function PlanEditorForm({ plan, categories, periods, multipliers, options, initialCategory }: EditorProps) {
    const navigate = useNavigate();
    const save = useSaveShopPlan();
    const form = useAppForm({
        defaultValues: formValues(plan, initialCategory ?? categories[0]?.id ?? null),
        onSubmit: async ({ value }) => {
            try {
                await save.mutateAsync({ id: plan?.id, values: toInput(value, plan?.sort ?? 0) });
                await navigate({ to: '/panel/shop' });
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });
    const values = useStore(form.store, (state) => state.values);
    const categoryOptions = categories.map((category) => ({ value: category.id, label: category.name }));
    const eggs = useMemo(() => eggGroups(options), [options]);
    const nodes = useMemo(() => nodeGroups(options), [options]);

    return (
        <Form form={form}>
            <div className='grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]'>
                <div className='grid min-w-0 gap-6'>
                    <Card title='Plan'>
                        <div className='grid gap-5 sm:grid-cols-2'>
                            <form.AppField
                                name='name'
                                validators={{
                                    onChange: ({ value }) => (value.trim() ? undefined : 'Name is required.'),
                                }}
                            >
                                {(field) => <field.TextField type='text' label='Name' autoComplete='off' />}
                            </form.AppField>
                            <form.AppField
                                name='category_id'
                                validators={{
                                    onChange: ({ value }) => (value === null ? 'Pick a category.' : undefined),
                                }}
                            >
                                {(field) => <field.SelectField label='Category' options={categoryOptions} />}
                            </form.AppField>
                            <div className='sm:col-span-2'>
                                <form.AppField name='description'>
                                    {(field) => (
                                        <field.TextAreaField
                                            label='Description'
                                            description='Shown on the plan card in the store.'
                                            rows={3}
                                        />
                                    )}
                                </form.AppField>
                            </div>
                            <form.AppField name='price_tokens' validators={{ onChange: numberRequired('Price', 0) }}>
                                {(field) => (
                                    <field.NumberField
                                        label='Price per 30 days'
                                        description='In tokens, before billing period discounts and node multipliers.'
                                        min={0}
                                    />
                                )}
                            </form.AppField>
                            <form.AppField name='visible'>
                                {(field) => (
                                    <field.SwitchField
                                        label='For sale'
                                        description='Hidden plans stay with the servers already bought.'
                                    />
                                )}
                            </form.AppField>
                        </div>
                    </Card>

                    <Card title='Resources'>
                        <div className='grid gap-5 sm:grid-cols-2 xl:grid-cols-4'>
                            <form.AppField name='memory' validators={{ onChange: numberRequired('Memory', 128) }}>
                                {(field) => (
                                    <field.NumberField
                                        label='Memory (MB)'
                                        description={formatMegabytes(values.memory ?? 0)}
                                        min={128}
                                    />
                                )}
                            </form.AppField>
                            <form.AppField name='disk' validators={{ onChange: numberRequired('Disk', 128) }}>
                                {(field) => (
                                    <field.NumberField
                                        label='Disk (MB)'
                                        description={formatMegabytes(values.disk ?? 0)}
                                        min={128}
                                    />
                                )}
                            </form.AppField>
                            <form.AppField name='cpu' validators={{ onChange: numberRequired('CPU', 1) }}>
                                {(field) => (
                                    <field.NumberField label='CPU (%)' description='100% is one thread.' min={1} />
                                )}
                            </form.AppField>
                            <form.AppField name='swap' validators={{ onChange: numberRequired('Swap', 0) }}>
                                {(field) => <field.NumberField label='Swap (MB)' min={0} />}
                            </form.AppField>
                            <form.AppField name='backups' validators={{ onChange: numberRequired('Backups', 0) }}>
                                {(field) => <field.NumberField label='Backups' min={0} />}
                            </form.AppField>
                            <form.AppField name='databases' validators={{ onChange: numberRequired('Databases', 0) }}>
                                {(field) => <field.NumberField label='Databases' min={0} />}
                            </form.AppField>
                            <form.AppField
                                name='allocations'
                                validators={{ onChange: numberRequired('Extra ports', 0) }}
                            >
                                {(field) => (
                                    <field.NumberField
                                        label='Extra ports'
                                        description='On top of the main port.'
                                        min={0}
                                    />
                                )}
                            </form.AppField>
                            <form.AppField name='io' validators={{ onChange: numberRequired('Block IO weight', 10) }}>
                                {(field) => (
                                    <field.NumberField
                                        label='Block IO weight'
                                        description='10 to 1000.'
                                        min={10}
                                        max={1000}
                                    />
                                )}
                            </form.AppField>
                        </div>
                    </Card>

                    <Card title='Eggs and nodes'>
                        <div className='grid gap-5'>
                            <form.Field
                                name='egg_ids'
                                validators={{
                                    onChange: ({ value }) =>
                                        value.length === 0 ? 'Pick at least one egg.' : undefined,
                                }}
                            >
                                {(field) => (
                                    <div>
                                        <Label htmlFor='shop-plan-eggs'>Eggs players can pick</Label>
                                        <Select
                                            multiple
                                            id='shop-plan-eggs'
                                            groups={eggs}
                                            value={field.state.value}
                                            onChange={(selected) => field.handleChange(selected.map(Number))}
                                            onBlur={field.handleBlur}
                                            placeholder='No eggs selected'
                                            searchPlaceholder='Search eggs'
                                            hasError={field.state.meta.errors.length > 0}
                                        />
                                        <p
                                            className={
                                                field.state.meta.errors.length > 0 ? 'input-help error' : 'input-help'
                                            }
                                        >
                                            {field.state.meta.errors.length > 0
                                                ? 'Pick at least one egg.'
                                                : 'Grouped by tag. Players choose one of these at checkout.'}
                                        </p>
                                    </div>
                                )}
                            </form.Field>
                            <form.AppField name='restrict_nodes'>
                                {(field) => (
                                    <field.SwitchField
                                        label='Only on chosen nodes'
                                        description='Off: any public node available in the shop (Shop → Nodes) that is not under maintenance.'
                                    />
                                )}
                            </form.AppField>
                            {values.restrict_nodes && (
                                <form.Field
                                    name='node_ids'
                                    validators={{
                                        onChangeListenTo: ['restrict_nodes'],
                                        onChange: ({ value, fieldApi }) =>
                                            fieldApi.form.getFieldValue('restrict_nodes') && value.length === 0
                                                ? 'Pick at least one node.'
                                                : undefined,
                                    }}
                                >
                                    {(field) => (
                                        <div>
                                            <Label htmlFor='shop-plan-nodes'>Nodes</Label>
                                            <Select
                                                multiple
                                                id='shop-plan-nodes'
                                                groups={nodes}
                                                value={field.state.value}
                                                onChange={(selected) => field.handleChange(selected.map(Number))}
                                                onBlur={field.handleBlur}
                                                placeholder='No nodes selected'
                                                searchPlaceholder='Search nodes'
                                                hasError={field.state.meta.errors.length > 0}
                                            />
                                            <p
                                                className={
                                                    field.state.meta.errors.length > 0
                                                        ? 'input-help error'
                                                        : 'input-help'
                                                }
                                            >
                                                {field.state.meta.errors.length > 0
                                                    ? 'Pick at least one node.'
                                                    : 'Grouped by location. A server is placed on one of these.'}
                                            </p>
                                        </div>
                                    )}
                                </form.Field>
                            )}
                        </div>
                    </Card>
                </div>
                <div className='lg:sticky lg:top-4 lg:self-start'>
                    <PricePreview values={values} periods={periods} multipliers={multipliers} options={options} />
                    <div className='mt-4 flex flex-col gap-2'>
                        <form.AppForm>
                            <form.SubmitButton>{plan ? 'Save plan' : 'Create plan'}</form.SubmitButton>
                        </form.AppForm>
                        {plan && !plan.visible && (
                            <Badge tone='neutral' variant='soft' size='sm' className='self-center'>
                                Not for sale
                            </Badge>
                        )}
                    </div>
                </div>
            </div>
        </Form>
    );
}

/** Creates (/panel/shop/plans/new) or edits (/panel/shop/plans/:id) a shop plan, with a live price preview. */
function PlanEditorBody({ planId, category }: { planId: number | undefined; category: number | null }) {
    const categories = useAdminShopCategories();
    const plans = useAdminShopPlans();
    const periods = useAdminShopPeriods();
    const multipliers = useAdminShopMultipliers();
    const options = useAdminShopOptions();

    const queries = [categories, plans, periods, multipliers, options];
    const error = queries.find((query) => query.error)?.error;

    if (error) {
        return (
            <ServerError
                message={httpErrorToHuman(error)}
                onRetry={() => {
                    for (const query of queries) {
                        void query.refetch();
                    }
                }}
            />
        );
    }

    if (!categories.data || !plans.data || !periods.data || !multipliers.data || !options.data) {
        return <Spinner size='large' centered />;
    }

    const plan = plans.data.find((item) => item.id === planId);

    if (planId !== undefined && !plan) {
        return <ServerError message='This plan no longer exists.' />;
    }

    return (
        <PlanEditorForm
            key={planId ?? 'new'}
            plan={plan}
            categories={categories.data}
            periods={periods.data}
            multipliers={multipliers.data}
            options={options.data}
            initialCategory={category}
        />
    );
}

export default function PlanEditor() {
    const params: { id?: number } = useParams({ strict: false });
    const search: { category?: number } = useSearch({ strict: false });
    const plans = useAdminShopPlans();
    const name = plans.data?.find((item) => item.id === params.id)?.name;
    const title = params.id === undefined ? 'New plan' : (name ?? 'Edit plan');

    return (
        <AdminContentBlock
            title={`Admin · Shop · ${title}`}
            heading={title}
            description='A server size sold in the shop.'
        >
            <div className='mb-4'>
                <RouterLinkButton to='/panel/shop' isSecondary size='xsmall'>
                    <ArrowLeft className='h-3.5 w-3.5' aria-hidden /> Back to plans
                </RouterLinkButton>
            </div>
            <PlanEditorBody planId={params.id} category={search.category ?? null} />
        </AdminContentBlock>
    );
}
