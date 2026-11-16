import { useState } from 'react';
import { Gamepad2, Pencil, Trash2 } from 'lucide-react';

import { deleteAdminGameInput, useAdminGames, useDeleteAdminGame, type AdminGame } from '@/api/admin/games/queries';
import { httpErrorToHuman } from '@/api/http';
import AdminContentBlock from '@/components/admin/AdminContentBlock';
import Badge from '@/components/elements/Badge';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import Icon from '@/components/elements/Icon';
import NamedIcon from '@/components/elements/NamedIcon';
import { NewButton } from '@/components/elements/NewButton';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import GameFormDialog from './GameFormDialog';

/** What a game's servers get, in words. */
export const FEATURE_LABELS = new Map<string, string>([
    ['plugins', 'Plugins & mods'],
    ['versions', 'Version switcher'],
    ['player_count', 'Player count'],
    ['world_settings', 'World settings'],
]);

const plural = (count: number, noun: string): string => `${count} ${noun}${count === 1 ? '' : 's'}`;

function GameCard({ game, onEdit, onDelete }: { game: AdminGame; onEdit: () => void; onDelete: () => void }) {
    const { attributes } = game;
    const detected = attributes.detected_egg_ids.length;

    return (
        <li className='flex flex-col rounded-lg border border-border bg-card p-4 shadow-sm'>
            <div className='flex items-start gap-3'>
                <span
                    aria-hidden
                    className='flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-primary-foreground'
                    style={{ backgroundColor: attributes.color }}
                >
                    <NamedIcon name={attributes.icon ?? 'gamepad-2'} className='h-5 w-5' />
                </span>
                <div className='min-w-0 flex-1'>
                    <h2 className='truncate text-base font-semibold text-foreground'>{attributes.name}</h2>
                    <p className='text-xs text-muted-foreground'>
                        {plural(attributes.egg_ids.length, 'egg')}
                        {detected > 0 && ` · ${detected} more detected`}
                    </p>
                </div>
                {attributes.built_in && (
                    <Badge tone='neutral' variant='soft' size='sm'>
                        Built in
                    </Badge>
                )}
            </div>
            <ul aria-label={`${attributes.name} features`} className='mt-3 flex flex-wrap gap-1.5'>
                {attributes.features.map((feature) => (
                    <li key={feature}>
                        <Badge tone='info' variant='soft' size='sm'>
                            {FEATURE_LABELS.get(feature) ?? feature}
                        </Badge>
                    </li>
                ))}
                <li>
                    <Badge tone={attributes.auto_tunnels ? 'success' : 'neutral'} variant='soft' size='sm'>
                        {attributes.auto_tunnels ? 'Auto tunnels on' : 'Auto tunnels off'}
                    </Badge>
                </li>
                {attributes.extra_ports.map((port) => (
                    <li key={port.key}>
                        <Badge tone='neutral' variant='soft' size='sm'>
                            {port.name} ({port.protocol.toUpperCase()}
                            {port.port === null ? '' : ` ${port.port}`})
                        </Badge>
                    </li>
                ))}
            </ul>
            {detected > 0 && (
                <p className='mt-2 text-xs text-muted-foreground'>
                    Detected eggs are treated as {attributes.name} until a game claims them. Edit the game to claim them
                    for good.
                </p>
            )}
            <div className='mt-4 flex flex-wrap gap-2 sm:mt-auto sm:pt-4'>
                <Button size='small' className='inline-flex items-center gap-2' onClick={onEdit}>
                    <Icon icon={Pencil} aria-hidden className='h-4 w-4' /> Edit
                    <span className='sr-only'>{attributes.name}</span>
                </Button>
                {!attributes.built_in && (
                    <Button.Danger size='small' className='inline-flex items-center gap-2' onClick={onDelete}>
                        <Icon icon={Trash2} aria-hidden className='h-4 w-4' /> Delete
                        <span className='sr-only'>{attributes.name}</span>
                    </Button.Danger>
                )}
            </div>
        </li>
    );
}

/**
 * Admin > Games: which eggs run which game. A server whose egg belongs to a game gets that
 * game's pages (Minecraft's plugins and versions, Terraria's world settings) and, when the game
 * says so, playit.gg tunnels. Eggs no game claims fall back to the built-in detection.
 */
export default function GamesContainer() {
    const games = useAdminGames();
    const remove = useDeleteAdminGame();
    const [editing, setEditing] = useState<AdminGame | 'new' | null>(null);
    const [deleting, setDeleting] = useState<AdminGame | null>(null);

    if (games.error) {
        return <ServerError message={httpErrorToHuman(games.error)} onRetry={() => void games.refetch()} />;
    }

    const list = games.data ?? [];

    return (
        <AdminContentBlock
            title='Admin · Games'
            heading='Games'
            description='Choose which eggs run which game. Their servers get the game’s own pages, such as the Minecraft version switcher, and playit.gg tunnels when the game asks for them.'
        >
            <div className='mb-4 flex justify-end'>
                <NewButton onClick={() => setEditing('new')}>New game</NewButton>
            </div>
            {games.isPending && <Spinner size='large' centered />}
            {!games.isPending && list.length === 0 && (
                <div className='flex flex-col items-center gap-2 py-12 text-center text-muted-foreground'>
                    <Icon icon={Gamepad2} aria-hidden className='h-8 w-8' />
                    <p>No games yet.</p>
                </div>
            )}
            {list.length > 0 && (
                <ul aria-label='Games' className='grid gap-4 sm:grid-cols-2 xl:grid-cols-3'>
                    {list.map((game) => (
                        <GameCard
                            key={game.attributes.id}
                            game={game}
                            onEdit={() => setEditing(game)}
                            onDelete={() => setDeleting(game)}
                        />
                    ))}
                </ul>
            )}
            {editing !== null && (
                <GameFormDialog
                    key={editing === 'new' ? 'new' : editing.attributes.id}
                    open
                    game={editing === 'new' ? undefined : editing}
                    games={list}
                    onClose={() => setEditing(null)}
                />
            )}
            <Dialog.Confirm
                open={deleting !== null}
                title={`Delete ${deleting?.attributes.name ?? 'game'}?`}
                confirm='Delete game'
                pending={remove.isPending}
                onClose={() => setDeleting(null)}
                onConfirmed={() => {
                    if (deleting) {
                        remove
                            .mutateAsync(deleteAdminGameInput(deleting.attributes.id, deleting.attributes.name))
                            .then(() => setDeleting(null))
                            .catch(() => {
                                // The mutation shows the error.
                            });
                    }
                }}
            >
                Its eggs go back to the built-in detection. Servers keep running; only their game pages change.
            </Dialog.Confirm>
        </AdminContentBlock>
    );
}
