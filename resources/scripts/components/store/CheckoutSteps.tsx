import type { CSSProperties, ReactNode } from 'react';
import { Check, MapPin, Minus, Plus } from 'lucide-react';
import type { StoreAddon, StoreEgg, StoreLocation, StorePeriod } from '@/api/store/types';
import Icon from '@/components/elements/Icon';
import { TextInput } from '@/components/form/controls';
import { everyDays, multiplierNote, periodNote, resourceLabel, tokens } from '@/components/store/storeFormat';
import { NAME_MAX } from '@/components/store/useCheckout';
import { cn } from '@/lib/cn';

const pillClass = (selected: boolean) =>
    cn(
        'rounded-full border px-3.5 py-1.5 text-sm transition-colors duration-150',
        selected
            ? 'border-primary bg-(--active-item) text-(--active-item-foreground)'
            : 'border-border text-muted-foreground hover:bg-(--hover-row) hover:text-foreground'
    );

export function Step({
    number,
    title,
    description,
    children,
}: {
    number: number;
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <section
            className='animate-rise rounded-2xl border border-border bg-card p-5 sm:p-6'
            style={{ '--rise-delay': `${number * 0.05}s` } as CSSProperties}
            aria-labelledby={`checkout-step-${number}`}
        >
            <div className='mb-4 flex items-start gap-3'>
                <span className='grid size-7 shrink-0 place-items-center rounded-full bg-(--active-item) text-sm font-semibold text-(--active-item-foreground)'>
                    {number}
                </span>
                <div>
                    <h2 id={`checkout-step-${number}`} className='font-header text-base font-semibold text-foreground'>
                        {title}
                    </h2>
                    {description && <p className='text-sm text-muted-foreground'>{description}</p>}
                </div>
            </div>
            {children}
        </section>
    );
}

/** A radio button drawn as a selectable card. */
function Choice({
    name,
    checked,
    onSelect,
    children,
}: {
    name: string;
    checked: boolean;
    onSelect: () => void;
    children: ReactNode;
}) {
    return (
        <label
            className={cn(
                'relative flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 transition-[border-color,background-color,box-shadow] duration-150',
                'has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring',
                checked
                    ? 'border-primary bg-(--active-item) shadow-lg shadow-primary/20'
                    : 'border-border hover:border-primary/50 hover:bg-(--hover-row)'
            )}
        >
            <input type='radio' name={name} checked={checked} onChange={onSelect} className='sr-only' />
            {children}
            <span
                aria-hidden
                className={cn(
                    'ml-auto grid size-5 shrink-0 place-items-center rounded-full border transition-colors duration-150',
                    checked ? 'border-primary bg-primary text-primary-foreground' : 'border-border'
                )}
            >
                {checked && <Icon icon={Check} className='size-3' />}
            </span>
        </label>
    );
}

export function EggBadge({ egg, className }: { egg: StoreEgg; className?: string }) {
    return (
        <span
            className={cn(
                'grid shrink-0 place-items-center overflow-hidden bg-secondary text-xs font-semibold text-foreground',
                className
            )}
        >
            {egg.image ? (
                <img src={egg.image} alt='' className='size-full object-cover' loading='lazy' />
            ) : (
                egg.name.slice(0, 2).toUpperCase()
            )}
        </span>
    );
}

export function EggStep({
    eggs,
    eggId,
    onChange,
}: {
    eggs: StoreEgg[];
    eggId: number | null;
    onChange: (id: number) => void;
}) {
    return (
        <Step number={1} title='Choose your game' description='The server comes set up for it.'>
            {eggs.length === 0 && <p className='text-sm text-muted-foreground'>This plan has no games to pick yet.</p>}
            <div className='grid gap-2 sm:grid-cols-2' role='radiogroup' aria-label='Game'>
                {eggs.map((egg) => (
                    <Choice key={egg.id} name='egg' checked={egg.id === eggId} onSelect={() => onChange(egg.id)}>
                        <EggBadge egg={egg} className='size-9 rounded-lg' />
                        <span className='min-w-0'>
                            <span className='block truncate text-sm font-medium text-foreground'>{egg.name}</span>
                            {egg.description && (
                                <span className='block truncate text-xs text-muted-foreground'>{egg.description}</span>
                            )}
                        </span>
                    </Choice>
                ))}
            </div>
        </Step>
    );
}

function NodePicker({
    location,
    nodeId,
    onChange,
}: {
    location: StoreLocation;
    nodeId: number | null;
    onChange: (id: number | null) => void;
}) {
    return (
        <div className='mt-4'>
            <p className='mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase'>Node</p>
            <div className='flex flex-wrap gap-2' role='radiogroup' aria-label='Node'>
                <button
                    type='button'
                    role='radio'
                    aria-checked={nodeId === null}
                    onClick={() => onChange(null)}
                    className={pillClass(nodeId === null)}
                >
                    Any — fastest setup
                </button>
                {location.nodes.map((node) => {
                    const note = multiplierNote(node.multiplier);

                    return (
                        <button
                            key={node.id}
                            type='button'
                            role='radio'
                            aria-checked={node.id === nodeId}
                            onClick={() => onChange(node.id)}
                            className={pillClass(node.id === nodeId)}
                        >
                            {node.name}
                            {note && <span className='ml-1.5 text-xs opacity-75'>{note}</span>}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

export function LocationStep({
    locations,
    locationId,
    nodeId,
    onLocation,
    onNode,
}: {
    locations: StoreLocation[];
    locationId: number | null;
    nodeId: number | null;
    onLocation: (id: number) => void;
    onNode: (id: number | null) => void;
}) {
    const location = locations.find((each) => each.id === locationId);

    return (
        <Step number={2} title='Pick a location' description='Some locations cost a little more.'>
            <div className='grid gap-2 sm:grid-cols-2' role='radiogroup' aria-label='Location'>
                {locations.map((each) => (
                    <Choice
                        key={each.id}
                        name='location'
                        checked={each.id === locationId}
                        onSelect={() => onLocation(each.id)}
                    >
                        <Icon icon={MapPin} className='size-4 shrink-0 text-(--accent-soft)' aria-hidden />
                        <span className='min-w-0'>
                            <span className='block truncate text-sm font-medium text-foreground'>{each.name}</span>
                            <span className='block text-xs text-muted-foreground'>
                                {multiplierNote(each.multiplier) ?? 'Standard price'}
                            </span>
                        </span>
                    </Choice>
                ))}
            </div>
            {location && location.nodes.length > 1 && (
                <NodePicker location={location} nodeId={nodeId} onChange={onNode} />
            )}
        </Step>
    );
}

export function PeriodStep({
    number,
    periods,
    periodId,
    onChange,
}: {
    number: number;
    periods: StorePeriod[];
    periodId: number | null;
    onChange: (id: number) => void;
}) {
    return (
        <Step number={number} title='Billing period' description='Tokens are taken automatically each period.'>
            {periods.length === 0 && <p className='text-sm text-muted-foreground'>No billing periods right now.</p>}
            <div className='grid gap-2 sm:grid-cols-3' role='radiogroup' aria-label='Billing period'>
                {periods.map((period) => {
                    const note = periodNote(period);

                    return (
                        <Choice
                            key={period.id}
                            name='period'
                            checked={period.id === periodId}
                            onSelect={() => onChange(period.id)}
                        >
                            <span className='min-w-0'>
                                <span className='block text-sm font-medium text-foreground'>{period.name}</span>
                                <span className='block text-xs text-muted-foreground'>{everyDays(period.days)}</span>
                            </span>
                            {note && (
                                <span
                                    className={cn(
                                        'rounded-full px-2 py-0.5 text-xs font-semibold',
                                        period.priceModifierPercent < 0
                                            ? 'bg-(--status-online-bg) text-(--status-online-fg)'
                                            : 'bg-(--status-busy-bg) text-(--status-busy-fg)'
                                    )}
                                >
                                    {note}
                                </span>
                            )}
                        </Choice>
                    );
                })}
            </div>
        </Step>
    );
}

export function NameStep({
    number,
    name,
    nameError,
    onName,
    subdomain,
}: {
    number: number;
    name: string;
    nameError: string | null;
    onName: (name: string) => void;
    subdomain: {
        enabled: boolean;
        base: string | null;
        value: string;
        error: string | null;
        onChange: (value: string) => void;
    };
}) {
    return (
        <Step number={number} title='Name your server' description='You can rename it later.'>
            <label htmlFor='checkout-name' className='sr-only'>
                Server name
            </label>
            <TextInput
                id='checkout-name'
                value={name}
                maxLength={NAME_MAX}
                autoComplete='off'
                $hasError={!!nameError}
                aria-invalid={!!nameError}
                aria-describedby={nameError ? 'checkout-name-error' : undefined}
                onChange={(event) => onName(event.target.value)}
            />
            {nameError && (
                <p id='checkout-name-error' className='mt-1.5 text-sm text-destructive'>
                    {nameError}
                </p>
            )}
            {subdomain.enabled && (
                <div className='mt-4'>
                    <label htmlFor='checkout-subdomain' className='mb-1.5 block text-sm text-foreground'>
                        Custom address <span className='text-muted-foreground'>(optional)</span>
                    </label>
                    <div className='flex items-center gap-2'>
                        <TextInput
                            id='checkout-subdomain'
                            value={subdomain.value}
                            maxLength={32}
                            autoComplete='off'
                            placeholder='myserver'
                            $hasError={!!subdomain.error}
                            aria-invalid={!!subdomain.error}
                            onChange={(event) => subdomain.onChange(event.target.value.toLowerCase().trim())}
                        />
                        {subdomain.base && (
                            <span className='shrink-0 font-mono text-sm text-muted-foreground'>.{subdomain.base}</span>
                        )}
                    </div>
                    {subdomain.error && <p className='mt-1.5 text-sm text-destructive'>{subdomain.error}</p>}
                </div>
            )}
        </Step>
    );
}

function AddonStepper({
    addon,
    units,
    periodDays,
    onChange,
}: {
    addon: StoreAddon;
    units: number;
    periodDays: number;
    onChange: (units: number) => void;
}) {
    const max = addon.maxUnits > 0 ? addon.maxUnits : 99;
    const price = Math.ceil((addon.priceTokens * periodDays) / 30);
    const buttonClass =
        'grid size-8 place-items-center rounded-lg border border-border text-foreground transition-colors hover:bg-(--hover-row) disabled:opacity-40';

    return (
        <div className='flex items-center gap-4 rounded-xl border border-border px-4 py-3'>
            <div className='min-w-0 flex-1'>
                <p className='text-sm font-medium text-foreground'>{addon.name}</p>
                <p className='text-xs text-muted-foreground'>
                    +{resourceLabel(addon.resource, addon.amount)} for {tokens(price)} {everyDays(periodDays)}
                    {addon.description ? ` · ${addon.description}` : ''}
                </p>
            </div>
            <div className='flex items-center gap-1' role='group' aria-label={`${addon.name} units`}>
                <button
                    type='button'
                    aria-label={`Remove one ${addon.name}`}
                    disabled={units <= 0}
                    onClick={() => onChange(units - 1)}
                    className={buttonClass}
                >
                    <Icon icon={Minus} className='size-3.5' aria-hidden />
                </button>
                <output
                    className='w-8 text-center text-sm font-semibold text-foreground tabular-nums'
                    aria-live='polite'
                >
                    {units}
                </output>
                <button
                    type='button'
                    aria-label={`Add one ${addon.name}`}
                    disabled={units >= max}
                    onClick={() => onChange(units + 1)}
                    className={buttonClass}
                >
                    <Icon icon={Plus} className='size-3.5' aria-hidden />
                </button>
            </div>
        </div>
    );
}

export function AddonsStep({
    number,
    addons,
    units,
    periodDays,
    onChange,
}: {
    number: number;
    addons: StoreAddon[];
    units: Record<string, number>;
    periodDays: number;
    onChange: (key: string, units: number) => void;
}) {
    return (
        <Step number={number} title='Add-ons' description='Optional extras, billed with the server.'>
            <div className='grid gap-2'>
                {addons.map((addon) => (
                    <AddonStepper
                        key={addon.key}
                        addon={addon}
                        units={units[addon.key] ?? 0}
                        periodDays={periodDays}
                        onChange={(count) => onChange(addon.key, count)}
                    />
                ))}
            </div>
        </Step>
    );
}
