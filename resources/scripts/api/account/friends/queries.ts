import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { invalidateGeneratedOperations } from '@/api/mutationUtils';
import {
    clientAcceptFriendRequestMutation,
    clientCancelFriendRequestMutation,
    clientDeclineFriendRequestMutation,
    clientListFriendsOptions,
    clientRemoveFriendMutation,
    clientSendFriendRequestMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type { ClientFriendListAttributes } from '@/api/generated';
import { notifyHttpError } from '@/plugins/notifications';

export type FriendList = ClientFriendListAttributes;
export type Friend = FriendList['friends'][number];
export type IncomingFriendRequest = FriendList['incoming'][number];
export type OutgoingFriendRequest = FriendList['outgoing'][number];

export const friendsQueryOptions = () => clientListFriendsOptions();

export const useFriends = () => useQuery(friendsQueryOptions());

/** The friend list and, since answering a request reads its notice, the notice feed. */
const refresh = (queryClient: ReturnType<typeof useQueryClient>) =>
    invalidateGeneratedOperations(queryClient, ['clientListFriends', 'clientListNotices']);

/** Sends a request; the server answers the same whether or not the user exists, and so does this. */
export const useSendFriendRequest = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientSendFriendRequestMutation(),
        onSuccess: (response) => {
            toast.success('Friend request sent', {
                description: response.message ?? "If that user exists, they'll get a request.",
            });
        },
        onSettled: () => refresh(queryClient),
        onError: (error) => notifyHttpError(error, 'Unable to send the friend request'),
    });
};

export const useAcceptFriendRequest = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientAcceptFriendRequestMutation(),
        onSuccess: () => {
            toast.success('Friend request accepted');
        },
        onSettled: () => refresh(queryClient),
        onError: (error) => notifyHttpError(error, 'Unable to accept the friend request'),
    });
};

export const useDeclineFriendRequest = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientDeclineFriendRequestMutation(),
        onSettled: () => refresh(queryClient),
        onError: (error) => notifyHttpError(error, 'Unable to decline the friend request'),
    });
};

export const useCancelFriendRequest = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientCancelFriendRequestMutation(),
        onSettled: () => refresh(queryClient),
        onError: (error) => notifyHttpError(error, 'Unable to cancel the friend request'),
    });
};

export const useRemoveFriend = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientRemoveFriendMutation(),
        onSuccess: () => {
            toast.success('Friend removed');
        },
        onSettled: () => refresh(queryClient),
        onError: (error) => notifyHttpError(error, 'Unable to remove the friend'),
    });
};
