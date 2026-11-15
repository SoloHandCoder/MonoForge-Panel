import { httpErrorToHuman } from '@/api/http';
import {
    useAdminShopSettings,
    useUpdateShopSettings,
    type ShopCapacity,
    type ShopSettings,
} from '@/api/admin/shop/queries';
import { formatMegabytes } from '@/components/admin/shop/format';
import Card from '@/components/elements/Card';
import ProgressBar from '@/components/elements/ProgressBar';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import { Form, useAppForm } from '@/components/form';

interface LimitsFormValues {
    enabled: boolean;
    grace_days: number | null;
    low_balance_days: number | null;
    memory: number | null;
    disk: number | null;
    cpu: number | null;
}

const capacityRows: { key: keyof ShopCapacity; label: string; format: (value: number) => string }[] = [
    { key: 'memory', label: 'Memory', format: formatMegabytes },
    { key: 'disk', label: 'Disk', format: formatMegabytes },
    { key: 'cpu', label: 'CPU', format: (value) => `${value}%` },
];

function CapacityMeters({ settings }: { settings: ShopSettings }) {
    return (
        <Card title='Sold right now'>
            <p className='mb-4 text-sm text-muted-foreground'>
                Servers bought in the shop that are active or suspended. When a purchase would pass a limit, the store
                greys out the buy button and says it has hit its limit.
            </p>
            <div className='grid gap-4'>
                {capacityRows.map(({ key, label, format }) => {
                    const used = settings.used[key];
                    const limit = settings.limits[key];
                    const percent = limit > 0 ? Math.min(100, Math.round((used / limit) * 100)) : null;
                    let tone: 'success' | 'warning' | 'danger' = 'success';

                    if (percent !== null && percent >= 90) {
                        tone = 'danger';
                    } else if (percent !== null && percent >= 75) {
                        tone = 'warning';
                    }

                    return (
                        <div key={key}>
                            <div className='mb-1 flex items-baseline justify-between gap-3 text-sm'>
                                <span className='text-foreground'>{label}</span>
                                <span className='tabular-nums text-muted-foreground'>
                                    {format(used)} / {limit > 0 ? format(limit) : 'no limit'}
                                </span>
                            </div>
                            <ProgressBar
                                value={percent ?? 0}
                                tone={limit > 0 ? tone : 'neutral'}
                                label={`${label} sold`}
                            />
                        </div>
                    );
                })}
            </div>
        </Card>
    );
}

const between =
    (label: string, min: number, max: number) =>
    ({ value }: { value: number | null }) =>
        value === null || value < min || value > max ? `${label} must be between ${min} and ${max}.` : undefined;

function LimitsForm({ settings }: { settings: ShopSettings }) {
    const update = useUpdateShopSettings();
    const defaultValues: LimitsFormValues = {
        enabled: settings.enabled,
        grace_days: settings.grace_days,
        low_balance_days: settings.low_balance_days,
        memory: settings.limits.memory,
        disk: settings.limits.disk,
        cpu: settings.limits.cpu,
    };
    const form = useAppForm({
        defaultValues,
        onSubmit: async ({ value }) => {
            try {
                await update.mutateAsync({
                    enabled: value.enabled,
                    grace_days: value.grace_days ?? 7,
                    low_balance_days: value.low_balance_days ?? 3,
                    limits: { memory: value.memory ?? 0, disk: value.disk ?? 0, cpu: value.cpu ?? 0 },
                });
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });

    return (
        <Form form={form}>
            <div className='grid gap-6'>
                <Card title='Store'>
                    <div className='grid gap-5'>
                        <form.AppField name='enabled'>
                            {(field) => (
                                <field.SwitchField
                                    label='Store open'
                                    description='Players can buy new servers. Closing it never touches servers already bought.'
                                />
                            )}
                        </form.AppField>
                        <div className='grid gap-5 sm:grid-cols-2'>
                            <form.AppField name='grace_days' validators={{ onChange: between('Grace period', 0, 30) }}>
                                {(field) => (
                                    <field.NumberField
                                        label='Days before deletion'
                                        description='How long a server stays suspended when its tokens run out. 0 deletes it right away.'
                                        min={0}
                                        max={30}
                                    />
                                )}
                            </form.AppField>
                            <form.AppField name='low_balance_days' validators={{ onChange: between('Warning', 0, 30) }}>
                                {(field) => (
                                    <field.NumberField
                                        label='Low-balance warning (days)'
                                        description='Warn players this many days before a renewal they cannot pay.'
                                        min={0}
                                        max={30}
                                    />
                                )}
                            </form.AppField>
                        </div>
                    </div>
                </Card>
                <Card title='Capacity limits'>
                    <p className='mb-4 text-sm text-muted-foreground'>
                        The most the shop sells in total across shop servers. 0 is no limit.
                    </p>
                    <div className='grid gap-5 sm:grid-cols-3'>
                        <form.AppField name='memory' validators={{ onChange: between('Memory', 0, 1_000_000_000) }}>
                            {(field) => <field.NumberField label='Memory (MB)' description='20480 is 20 GB.' min={0} />}
                        </form.AppField>
                        <form.AppField name='disk' validators={{ onChange: between('Disk', 0, 1_000_000_000) }}>
                            {(field) => <field.NumberField label='Disk (MB)' description='512000 is 500 GB.' min={0} />}
                        </form.AppField>
                        <form.AppField name='cpu' validators={{ onChange: between('CPU', 0, 10_000_000) }}>
                            {(field) => <field.NumberField label='CPU (%)' description='1500 is 15 threads.' min={0} />}
                        </form.AppField>
                    </div>
                </Card>
                <div className='flex justify-end'>
                    <form.AppForm>
                        <form.SubmitButton>Save changes</form.SubmitButton>
                    </form.AppForm>
                </div>
            </div>
        </Form>
    );
}

/** Opens or closes the store, sets the grace period and how much the shop may sell. */
export default function LimitsTab() {
    const { data, error, refetch } = useAdminShopSettings();

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => void refetch()} />;
    }

    if (!data) {
        return <Spinner size='large' centered />;
    }

    return (
        <div className='grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]'>
            <LimitsForm settings={data} />
            <div className='lg:self-start'>
                <CapacityMeters settings={data} />
            </div>
        </div>
    );
}
