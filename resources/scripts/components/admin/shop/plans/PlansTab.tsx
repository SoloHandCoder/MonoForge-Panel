import { useNavigate } from '@tanstack/react-router';
import { ArrowDown, ArrowUp, FolderPlus, Package } from 'lucide-react';
import { httpErrorToHuman } from '@/api/http';
import {
    useAdminShopCategories,
    useAdminShopPlans,
    useDeleteShopCategory,
    useDeleteShopPlan,
    useReorderShopCategories,
    type ShopCategory,
    type ShopPlan,
} from '@/api/admin/shop/queries';
import { resourceSummary } from '@/components/admin/shop/format';
import CategoryFormDialog from '@/components/admin/shop/plans/CategoryFormDialog';
import Badge from '@/components/elements/Badge';
import Card from '@/components/elements/Card';
import { Dialog } from '@/components/elements/dialog';
import { NewButton } from '@/components/elements/NewButton';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import {
    DeleteAction,
    EditAction,
    EditLinkAction,
    RowActionButton,
    RowActions,
} from '@/components/elements/table/RowActions';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { formatTokens } from '@/lib/shopPricing';

function NewCategoryButton() {
    return (
        <Dialog.Trigger
            trigger={({ onClick }) => (
                <NewButton icon={FolderPlus} isSecondary onClick={onClick}>
                    New category
                </NewButton>
            )}
        >
            {({ open, onClose }) => <CategoryFormDialog open={open} onClose={onClose} />}
        </Dialog.Trigger>
    );
}

function PlanRow({ plan }: { plan: ShopPlan }) {
    const remove = useDeleteShopPlan();

    return (
        <li className='flex flex-col gap-3 py-3 sm:flex-row sm:items-center'>
            <div className='min-w-0 flex-1'>
                <div className='flex flex-wrap items-center gap-2'>
                    <p className='truncate font-medium text-foreground'>{plan.name}</p>
                    {!plan.visible && (
                        <Badge tone='neutral' variant='soft' size='sm'>
                            Hidden
                        </Badge>
                    )}
                    {plan.node_ids && (
                        <Badge tone='info' variant='soft' size='sm'>
                            {plan.node_ids.length} {plan.node_ids.length === 1 ? 'node' : 'nodes'}
                        </Badge>
                    )}
                </div>
                <p className='mt-1 text-xs text-muted-foreground'>{resourceSummary(plan.resources).join(' · ')}</p>
            </div>
            <p className='shrink-0 text-sm tabular-nums text-foreground'>
                {formatTokens(plan.price_tokens)}
                <span className='text-muted-foreground'> / 30 days</span>
            </p>
            <RowActions>
                <EditLinkAction aria-label={`Edit ${plan.name}`} to='/panel/shop/plans/$id' params={{ id: plan.id }} />
                <Dialog.ConfirmTrigger
                    title='Delete plan'
                    confirm='Delete plan'
                    onConfirmed={async (_event, close) => {
                        try {
                            await remove.mutateAsync(plan.id);
                            close();
                        } catch {
                            // Error toast is handled by the mutation.
                        }
                    }}
                    trigger={({ onClick }) => <DeleteAction aria-label={`Delete ${plan.name}`} onClick={onClick} />}
                >
                    <p className='text-sm leading-relaxed text-muted-foreground'>
                        <strong className='text-foreground'>{plan.name}</strong> is no longer sold. Servers already
                        bought keep running at the price they were bought at.
                    </p>
                </Dialog.ConfirmTrigger>
            </RowActions>
        </li>
    );
}

interface CategoryCardProps {
    category: ShopCategory;
    plans: ShopPlan[];
    position: number;
    count: number;
    onMove: (from: number, to: number) => void;
}

function CategoryCard({ category, plans, position, count, onMove }: CategoryCardProps) {
    const remove = useDeleteShopCategory();
    const navigate = useNavigate();

    return (
        <Card
            title={
                <div className='flex min-w-0 items-center gap-2'>
                    <h2 className='truncate text-base font-semibold text-foreground'>{category.name}</h2>
                    <span className='truncate text-xs text-muted-foreground'>/{category.slug}</span>
                    {!category.visible && (
                        <Badge tone='neutral' variant='soft' size='sm'>
                            Hidden
                        </Badge>
                    )}
                </div>
            }
            actions={
                <RowActions>
                    <RowActionButton
                        icon={ArrowUp}
                        label='Move up'
                        aria-label={`Move ${category.name} up`}
                        disabled={position === 0}
                        onClick={() => onMove(position, position - 1)}
                    />
                    <RowActionButton
                        icon={ArrowDown}
                        label='Move down'
                        aria-label={`Move ${category.name} down`}
                        disabled={position === count - 1}
                        onClick={() => onMove(position, position + 1)}
                    />
                    <Dialog.Trigger
                        trigger={({ onClick }) => <EditAction aria-label={`Edit ${category.name}`} onClick={onClick} />}
                    >
                        {({ open, onClose }) => (
                            <CategoryFormDialog open={open} onClose={onClose} category={category} />
                        )}
                    </Dialog.Trigger>
                    <Dialog.ConfirmTrigger
                        title='Delete category'
                        confirm='Delete category'
                        onConfirmed={async (_event, close) => {
                            try {
                                await remove.mutateAsync(category.id);
                                close();
                            } catch {
                                // Error toast is handled by the mutation.
                            }
                        }}
                        trigger={({ onClick }) => (
                            <DeleteAction
                                aria-label={`Delete ${category.name}`}
                                onClick={onClick}
                                disabled={plans.length > 0}
                                disabledReason='Move or delete its plans first'
                            />
                        )}
                    >
                        <p className='text-sm leading-relaxed text-muted-foreground'>
                            This deletes the empty <strong className='text-foreground'>{category.name}</strong>{' '}
                            category.
                        </p>
                    </Dialog.ConfirmTrigger>
                </RowActions>
            }
        >
            {category.description && <p className='mb-2 text-sm text-muted-foreground'>{category.description}</p>}
            {plans.length > 0 ? (
                <ul className='divide-y divide-border'>
                    {plans.map((plan) => (
                        <PlanRow key={plan.id} plan={plan} />
                    ))}
                </ul>
            ) : (
                <p className='py-3 text-sm text-muted-foreground'>No plans in this category yet.</p>
            )}
            <div className='mt-3'>
                <NewButton
                    isSecondary
                    size='xsmall'
                    onClick={() => void navigate({ to: '/panel/shop/plans/new', search: { category: category.id } })}
                >
                    New plan
                </NewButton>
            </div>
        </Card>
    );
}

/** Categories in store order, each with its plans cheapest first, as the store lists them. */
export default function PlansTab() {
    const categories = useAdminShopCategories();
    const plans = useAdminShopPlans();
    const reorder = useReorderShopCategories();

    const error = categories.error ?? plans.error;

    if (error) {
        return (
            <ServerError
                message={httpErrorToHuman(error)}
                onRetry={() => {
                    void categories.refetch();
                    void plans.refetch();
                }}
            />
        );
    }

    if (!categories.data || !plans.data) {
        return <Spinner size='large' centered />;
    }

    const list = categories.data;
    const allPlans = plans.data;
    const move = (from: number, to: number) => {
        const ids = list.map((category) => category.id);
        const [moved] = ids.splice(from, 1);

        if (moved === undefined) {
            return;
        }

        ids.splice(to, 0, moved);
        reorder.mutate(ids);
    };

    return (
        <div className='grid grid-cols-1 gap-4'>
            <div className='flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between'>
                <p className='text-sm text-muted-foreground'>
                    Plans are listed cheapest first in the store. Use the arrows to order the categories.
                </p>
                {list.length > 0 && <NewCategoryButton />}
            </div>
            {list.length === 0 ? (
                <Empty>
                    <EmptyHeader>
                        <EmptyMedia variant='icon'>
                            <Package />
                        </EmptyMedia>
                        <EmptyTitle>No categories yet</EmptyTitle>
                        <EmptyDescription>
                            Create a category such as &ldquo;Minecraft&rdquo;, then add the plans players can buy in it.
                        </EmptyDescription>
                    </EmptyHeader>
                    <EmptyContent>
                        <NewCategoryButton />
                    </EmptyContent>
                </Empty>
            ) : (
                list.map((category, position) => (
                    <CategoryCard
                        key={category.id}
                        category={category}
                        plans={allPlans.filter((plan) => plan.category_id === category.id)}
                        position={position}
                        count={list.length}
                        onMove={move}
                    />
                ))
            )}
        </div>
    );
}
