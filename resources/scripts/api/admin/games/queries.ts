import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';

import { invalidateGeneratedOperations, resourceMutationMessages } from '@/api/mutationUtils';
import { fetchAllPages, MAX_PER_PAGE } from '@/api/pagination';
import {
    adminCreateGameMutation,
    adminDeleteGameMutation,
    adminListEggsQueryKey,
    adminListGamesOptions,
    adminUpdateGameMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import {
    adminListEggs,
    type AdminCreateGameData,
    type AdminDeleteGameData,
    type AdminEggResource,
    type AdminGameResource,
    type AdminUpdateGameData,
    type Options,
} from '@/api/generated';
import { notifyHttpError } from '@/plugins/notifications';

export type AdminGame = AdminGameResource;
export type AdminGameValues = AdminCreateGameData['body'];
export type AdminGameExtraPort = AdminGame['attributes']['extra_ports'][number];
export type GameEgg = AdminEggResource;

export const createAdminGameInput = (values: AdminGameValues): Options<AdminCreateGameData> => ({ body: values });

export const updateAdminGameInput = (id: number, values: AdminGameValues): Options<AdminUpdateGameData> => ({
    path: { game_id: id },
    body: values,
});

export const deleteAdminGameInput = (id: number, name?: string): Options<AdminDeleteGameData> => ({
    path: { game_id: id },
    meta: name ? { name } : undefined,
});

export const adminGamesQueryOptions = () => adminListGamesOptions();

export const useAdminGames = () => useQuery({ ...adminGamesQueryOptions(), select: (response) => response.data });

const gameEggsInput = { query: { sort: 'name,id', per_page: MAX_PER_PAGE, include: 'tags' } } as const;

/** Every egg with its tags, for the game's egg picker (grouped by tag, as nests used to be). */
export const adminGameEggsQueryOptions = () =>
    queryOptions({
        queryKey: [...adminListEggsQueryKey(gameEggsInput), 'games'],
        queryFn: async ({ signal }): Promise<GameEgg[]> =>
            (
                await fetchAllPages(
                    async (page) => (await adminListEggs({ query: { ...gameEggsInput.query, page }, signal })).data,
                    signal
                )
            ).data,
    });

export const useAdminGameEggs = (enabled = true) => useQuery({ ...adminGameEggsQueryOptions(), enabled });

/** The tag an egg is listed under in the picker: its first tag, or "Other". */
export function eggGroup(egg: GameEgg): string {
    const relation = egg.attributes.relationships?.tags;
    const tags = relation && 'data' in relation ? relation.data : [];

    return tags[0]?.attributes.name ?? 'Other';
}

/** Eggs by group name, groups and eggs in name order, "Other" last. */
export function groupEggs(eggs: readonly GameEgg[]): { group: string; eggs: GameEgg[] }[] {
    const groups = new Map<string, GameEgg[]>();

    for (const egg of eggs) {
        const group = eggGroup(egg);

        groups.set(group, [...(groups.get(group) ?? []), egg]);
    }

    const order = (a: string, b: string): number => {
        if (a === 'Other' || b === 'Other') {
            return Number(a === 'Other') - Number(b === 'Other');
        }

        return a.localeCompare(b);
    };

    return [...groups.entries()]
        .sort(([a], [b]) => order(a, b))
        .map(([group, members]) => ({
            group,
            eggs: [...members].sort((a, b) => a.attributes.name.localeCompare(b.attributes.name)),
        }));
}

/** The game that claims each egg, by egg ID. */
export function claimedBy(games: readonly AdminGame[]): Map<number, AdminGame> {
    const claims = new Map<number, AdminGame>();

    for (const game of games) {
        for (const id of game.attributes.egg_ids) {
            claims.set(id, game);
        }
    }

    return claims;
}

// Moving an egg changes the game of every server on it, which the server pages read.
const gameOperations = ['adminListGames', 'adminGetGame'];

export const useCreateAdminGame = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminCreateGameMutation(),
        onSuccess: async (game) => {
            await invalidateGeneratedOperations(queryClient, gameOperations);
            const messages = resourceMutationMessages('game', 'create', game.attributes.name);

            toast.success(messages.success.title, { description: messages.success.description });
        },
        onError: (error) => notifyHttpError(error, resourceMutationMessages('game', 'create').errorTitle),
    });
};

export const useUpdateAdminGame = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminUpdateGameMutation(),
        onSuccess: async (game) => {
            await invalidateGeneratedOperations(queryClient, gameOperations);
            const messages = resourceMutationMessages('game', 'update', game.attributes.name);

            toast.success(messages.success.title, { description: messages.success.description });
        },
        onError: (error) => notifyHttpError(error, resourceMutationMessages('game', 'update').errorTitle),
    });
};

export const useDeleteAdminGame = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminDeleteGameMutation(),
        onSuccess: async (_result, variables) => {
            await invalidateGeneratedOperations(queryClient, gameOperations);
            const messages = resourceMutationMessages('game', 'delete', variables.meta?.name);

            toast.success(messages.success.title, { description: messages.success.description });
        },
        onError: (error) => notifyHttpError(error, resourceMutationMessages('game', 'delete').errorTitle),
    });
};
