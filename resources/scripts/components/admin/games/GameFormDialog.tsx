import { useId, useMemo, useState } from 'react';
import { Plus, Search, Trash2 } from 'lucide-react';

import {
    claimedBy,
    createAdminGameInput,
    groupEggs,
    updateAdminGameInput,
    useAdminGameEggs,
    useCreateAdminGame,
    useUpdateAdminGame,
    type AdminGame,
    type AdminGameValues,
} from '@/api/admin/games/queries';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import Icon from '@/components/elements/Icon';
import NamedIcon from '@/components/elements/NamedIcon';
import Spinner from '@/components/elements/Spinner';
import { TextInput } from '@/components/form/controls';
import Checkbox from '@/components/ui/Checkbox';
import Switch from '@/components/ui/Switch';

interface Props {
    open: boolean;
    onClose: () => void;
    /** The game to edit; a new custom game when left out. */
    game?: AdminGame;
    /** Every game, to show which game an egg would move from. */
    games: readonly AdminGame[];
}

type Protocol = 'tcp' | 'udp' | 'both';

/** An extra port row as typed: the port and the allocation position are strings until saved. */
export interface ExtraPortDraft {
    /** Keeps a row's identity while rows are added and removed; never sent. */
    uid: number;
    name: string;
    protocol: Protocol;
    port: string;
    allocation: string;
}

export interface GameDraft {
    name: string;
    color: string;
    icon: string;
    autoTunnels: boolean;
    eggIds: number[];
    extraPorts: ExtraPortDraft[];
}

const PROTOCOLS = [
    { value: 'udp', label: 'UDP' },
    { value: 'tcp', label: 'TCP' },
    { value: 'both', label: 'TCP and UDP' },
];

let nextUid = 0;
const newUid = (): number => ++nextUid;

export const gameDraft = (game?: AdminGame): GameDraft => ({
    name: game?.attributes.name ?? '',
    // Left empty for a new game: the panel then gives it its neutral colour.
    color: game?.attributes.color ?? '',
    icon: game?.attributes.icon ?? 'gamepad-2',
    autoTunnels: game?.attributes.auto_tunnels ?? false,
    eggIds: game?.attributes.egg_ids ?? [],
    extraPorts: (game?.attributes.extra_ports ?? []).map((port) => ({
        uid: newUid(),
        name: port.name,
        protocol: port.protocol,
        port: port.port === null ? '' : String(port.port),
        allocation: port.allocation === null ? '' : String(port.allocation),
    })),
});

const HEX = /^#[0-9a-fA-F]{6}$/;
const ICON = /^[a-z0-9][a-z0-9-]{0,47}$/;
const isWhole = (value: string, min: number, max: number): boolean =>
    /^\d+$/.test(value) && Number(value) >= min && Number(value) <= max;

/** Field errors of a game draft: `name`, `color`, `icon` and `port-<index>`. */
export type GameDraftErrors = Partial<Record<'name' | 'color' | 'icon' | `port-${number}`, string>>;

function extraPortError(port: ExtraPortDraft): string | null {
    if (port.name.trim() === '' || port.name.trim().length > 32) {
        return 'Give the port a name of up to 32 characters.';
    }

    if (port.port === '' && port.allocation === '') {
        return 'Set a port, an allocation position, or both.';
    }

    if (port.port !== '' && !isWhole(port.port, 1, 65535)) {
        return 'The port must be between 1 and 65535.';
    }

    if (port.allocation !== '' && !isWhole(port.allocation, 1, 20)) {
        return 'The allocation position must be between 1 and 20.';
    }

    return null;
}

/** What is wrong with the draft, by field; empty when it can be saved. */
export function validateGameDraft(draft: GameDraft): GameDraftErrors {
    const errors: GameDraftErrors = {};

    if (draft.name.trim() === '' || draft.name.trim().length > 64) {
        errors.name = 'Give the game a name of up to 64 characters.';
    }

    if (draft.color !== '' && !HEX.test(draft.color)) {
        errors.color = 'Use a six-digit hex colour: a hash and six digits or letters a to f.';
    }

    if (draft.icon !== '' && !ICON.test(draft.icon)) {
        errors.icon = 'Use a lucide icon name such as gamepad-2.';
    }

    for (const [index, port] of draft.extraPorts.entries()) {
        const error = extraPortError(port);

        if (error) {
            errors[`port-${index}`] = error;
        }
    }

    return errors;
}

/** The request body; an empty colour is left out, so the panel keeps (or gives) its own. */
export function gameRequest(draft: GameDraft): AdminGameValues {
    const request: AdminGameValues = {
        name: draft.name.trim(),
        icon: draft.icon === '' ? null : draft.icon,
        auto_tunnels: draft.autoTunnels,
        egg_ids: draft.eggIds,
        extra_ports: draft.extraPorts.map((port) => ({
            name: port.name.trim(),
            protocol: port.protocol,
            port: port.port === '' ? null : Number(port.port),
            allocation: port.allocation === '' ? null : Number(port.allocation),
        })),
    };

    if (draft.color !== '') {
        request.color = draft.color.toUpperCase();
    }

    return request;
}

function EggPicker({
    game,
    games,
    selected,
    onChange,
}: {
    game?: AdminGame;
    games: readonly AdminGame[];
    selected: readonly number[];
    onChange: (ids: number[]) => void;
}) {
    const eggs = useAdminGameEggs();
    const [filter, setFilter] = useState('');
    const claims = useMemo(() => claimedBy(games), [games]);
    const groups = useMemo(() => {
        const query = filter.trim().toLowerCase();
        const matching = (eggs.data ?? []).filter(
            (egg) => query === '' || egg.attributes.name.toLowerCase().includes(query)
        );

        return groupEggs(matching);
    }, [eggs.data, filter]);
    const toggle = (id: number, checked: boolean) =>
        onChange(checked ? [...selected, id] : selected.filter((value) => value !== id));

    if (eggs.isPending) {
        return <Spinner size='small' />;
    }

    return (
        <fieldset className='min-w-0'>
            <legend className='mb-1 text-sm font-medium text-foreground'>Eggs</legend>
            <p className='mb-2 text-xs text-muted-foreground'>
                Servers on these eggs get this game&apos;s pages. An egg belongs to one game: picking one here moves it.
            </p>
            <div className='relative mb-2'>
                <Icon
                    icon={Search}
                    aria-hidden
                    className='pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground'
                />
                <TextInput
                    aria-label='Search eggs'
                    className='pl-9'
                    placeholder='Search eggs…'
                    value={filter}
                    onChange={(event) => setFilter(event.currentTarget.value)}
                />
            </div>
            <div className='max-h-64 space-y-3 overflow-y-auto rounded-md border border-border p-3'>
                {groups.length === 0 && <p className='text-sm text-muted-foreground'>No eggs match.</p>}
                {groups.map(({ group, eggs: members }) => (
                    <div key={group} role='group' aria-label={group}>
                        <p className='mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground'>
                            {group}
                        </p>
                        <ul className='space-y-1'>
                            {members.map((egg) => {
                                const id = egg.attributes.id;
                                const owner = claims.get(id);
                                const elsewhere = owner && owner.attributes.id !== game?.attributes.id ? owner : null;
                                const checked = selected.includes(id);

                                return (
                                    <li key={id}>
                                        <label className='flex min-h-9 cursor-pointer items-center gap-2 text-sm'>
                                            <Checkbox
                                                checked={checked}
                                                onChange={(value) => toggle(id, value)}
                                                aria-describedby={elsewhere ? `egg-${id}-owner` : undefined}
                                            />
                                            <span className='min-w-0 flex-1 truncate'>{egg.attributes.name}</span>
                                            {elsewhere && (
                                                <span
                                                    id={`egg-${id}-owner`}
                                                    className='shrink-0 text-xs text-muted-foreground'
                                                >
                                                    {checked ? 'moves from' : 'in'} {elsewhere.attributes.name}
                                                </span>
                                            )}
                                        </label>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                ))}
            </div>
            <p className='mt-1 text-xs text-muted-foreground' aria-live='polite'>
                {selected.length} {selected.length === 1 ? 'egg' : 'eggs'} selected
            </p>
        </fieldset>
    );
}

function ExtraPorts({
    ports,
    errors,
    onChange,
}: {
    ports: ExtraPortDraft[];
    errors: GameDraftErrors;
    onChange: (ports: ExtraPortDraft[]) => void;
}) {
    const id = useId();
    const update = (index: number, change: Partial<ExtraPortDraft>) =>
        onChange(ports.map((port, position) => (position === index ? { ...port, ...change } : port)));

    return (
        <fieldset className='min-w-0'>
            <legend className='mb-1 text-sm font-medium text-foreground'>Extra tunnels</legend>
            <p className='mb-2 text-xs text-muted-foreground'>
                Ports besides the game port, such as Simple Voice Chat (UDP 24454). Each matches one of a server&apos;s
                extra allocations: by its port, otherwise by its position (1 is the first extra allocation).
            </p>
            <ul className='space-y-3'>
                {ports.map((port, index) => (
                    <li key={port.uid} className='rounded-md border border-border p-3'>
                        <div className='grid gap-2 sm:grid-cols-[1fr_9rem]'>
                            <TextInput
                                aria-label={`Extra tunnel ${index + 1} name`}
                                placeholder='Voice chat'
                                value={port.name}
                                onChange={(event) => update(index, { name: event.currentTarget.value })}
                            />
                            <select
                                id={`${id}-protocol-${index}`}
                                aria-label={`Extra tunnel ${index + 1} protocol`}
                                className='h-9 w-full rounded-md border border-border bg-background px-2 text-sm text-foreground'
                                value={port.protocol}
                                onChange={(event) => update(index, { protocol: event.currentTarget.value as Protocol })}
                            >
                                {PROTOCOLS.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className='mt-2 grid grid-cols-[1fr_1fr_auto] items-center gap-2'>
                            <TextInput
                                aria-label={`Extra tunnel ${index + 1} port`}
                                inputMode='numeric'
                                placeholder='Port, e.g. 24454'
                                value={port.port}
                                onChange={(event) => update(index, { port: event.currentTarget.value.trim() })}
                            />
                            <TextInput
                                aria-label={`Extra tunnel ${index + 1} allocation position`}
                                inputMode='numeric'
                                placeholder='Or allocation #'
                                value={port.allocation}
                                onChange={(event) => update(index, { allocation: event.currentTarget.value.trim() })}
                            />
                            <Button.Text
                                type='button'
                                size='small'
                                aria-label={`Remove extra tunnel ${index + 1}`}
                                onClick={() => onChange(ports.filter((_, position) => position !== index))}
                            >
                                <Icon icon={Trash2} aria-hidden className='h-4 w-4' />
                            </Button.Text>
                        </div>
                        {errors[`port-${index}`] !== undefined && (
                            <p role='alert' className='mt-1 text-xs text-destructive'>
                                {errors[`port-${index}`]}
                            </p>
                        )}
                    </li>
                ))}
            </ul>
            {ports.length < 5 && (
                <Button.Text
                    type='button'
                    size='small'
                    className='mt-2 inline-flex items-center gap-2'
                    onClick={() =>
                        onChange([...ports, { uid: newUid(), name: '', protocol: 'udp', port: '', allocation: '' }])
                    }
                >
                    <Icon icon={Plus} aria-hidden className='h-4 w-4' /> Add extra tunnel
                </Button.Text>
            )}
        </fieldset>
    );
}

/** Creates a custom game or edits any game: its look, eggs, tunnels and extra ports. */
export default function GameFormDialog({ open, onClose, game, games }: Props) {
    const id = useId();
    const create = useCreateAdminGame();
    const update = useUpdateAdminGame();
    const [draft, setDraft] = useState<GameDraft>(() => gameDraft(game));
    const [submitted, setSubmitted] = useState(false);
    const errors = validateGameDraft(draft);
    const shown = submitted ? errors : {};
    const pending = create.isPending || update.isPending;
    const set = (change: Partial<GameDraft>) => setDraft((current) => ({ ...current, ...change }));

    const save = async () => {
        setSubmitted(true);

        if (Object.keys(errors).length > 0) {
            return;
        }

        try {
            if (game) {
                await update.mutateAsync(updateAdminGameInput(game.attributes.id, gameRequest(draft)));
            } else {
                await create.mutateAsync(createAdminGameInput(gameRequest(draft)));
            }

            onClose();
        } catch {
            // The mutation shows the error.
        }
    };

    return (
        <Dialog
            open={open}
            title={game ? `Edit ${game.attributes.name}` : 'New game'}
            preventExternalClose={pending}
            hideCloseIcon={pending}
            onClose={onClose}
        >
            <form
                className='grid gap-5'
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();
                    void save();
                }}
            >
                <div>
                    <label htmlFor={`${id}-name`} className='mb-1 block text-sm font-medium text-foreground'>
                        Name
                    </label>
                    <TextInput
                        id={`${id}-name`}
                        value={draft.name}
                        maxLength={64}
                        $hasError={!!shown.name}
                        aria-invalid={!!shown.name}
                        aria-describedby={shown.name ? `${id}-name-error` : undefined}
                        onChange={(event) => set({ name: event.currentTarget.value })}
                    />
                    {shown.name && (
                        <p id={`${id}-name-error`} className='mt-1 text-xs text-destructive'>
                            {shown.name}
                        </p>
                    )}
                </div>
                <div className='grid gap-4 sm:grid-cols-2'>
                    <div>
                        <label htmlFor={`${id}-color`} className='mb-1 block text-sm font-medium text-foreground'>
                            Colour
                        </label>
                        <div className='flex items-center gap-2'>
                            <input
                                type='color'
                                aria-label='Pick a colour'
                                className='h-9 w-11 shrink-0 cursor-pointer rounded border border-border bg-transparent'
                                value={HEX.test(draft.color) ? draft.color : undefined}
                                onChange={(event) => set({ color: event.currentTarget.value.toUpperCase() })}
                            />
                            <TextInput
                                id={`${id}-color`}
                                value={draft.color}
                                spellCheck={false}
                                $hasError={!!shown.color}
                                aria-invalid={!!shown.color}
                                onChange={(event) => set({ color: event.currentTarget.value.trim() })}
                            />
                        </div>
                        {shown.color && <p className='mt-1 text-xs text-destructive'>{shown.color}</p>}
                    </div>
                    <div>
                        <label htmlFor={`${id}-icon`} className='mb-1 block text-sm font-medium text-foreground'>
                            Icon
                        </label>
                        <div className='flex items-center gap-2'>
                            <span
                                aria-hidden
                                className='flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted text-primary-foreground'
                                style={HEX.test(draft.color) ? { backgroundColor: draft.color } : undefined}
                            >
                                <NamedIcon name={draft.icon || 'gamepad-2'} className='h-4 w-4' />
                            </span>
                            <TextInput
                                id={`${id}-icon`}
                                value={draft.icon}
                                spellCheck={false}
                                placeholder='gamepad-2'
                                $hasError={!!shown.icon}
                                aria-invalid={!!shown.icon}
                                onChange={(event) => set({ icon: event.currentTarget.value.trim().toLowerCase() })}
                            />
                        </div>
                        {shown.icon && <p className='mt-1 text-xs text-destructive'>{shown.icon}</p>}
                    </div>
                </div>
                <EggPicker game={game} games={games} selected={draft.eggIds} onChange={(eggIds) => set({ eggIds })} />
                <Switch
                    checked={draft.autoTunnels}
                    onChange={(autoTunnels) => set({ autoTunnels })}
                    label='Auto-create playit tunnels'
                    description='Servers on these eggs get playit.gg tunnels on nodes with an agent. Eggs no game claims never do.'
                />
                <ExtraPorts ports={draft.extraPorts} errors={shown} onChange={(extraPorts) => set({ extraPorts })} />
                <div className='flex flex-col-reverse gap-2 sm:flex-row sm:justify-end'>
                    <Button.Text type='button' isSecondary disabled={pending} onClick={onClose}>
                        Cancel
                    </Button.Text>
                    <Button type='submit' isLoading={pending}>
                        {game ? 'Save changes' : 'Create game'}
                    </Button>
                </div>
            </form>
        </Dialog>
    );
}
