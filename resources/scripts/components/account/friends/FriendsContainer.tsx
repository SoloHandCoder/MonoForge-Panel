import { useState } from 'react';
import { UserPlus } from 'lucide-react';
import {
    useAcceptFriendRequest,
    useCancelFriendRequest,
    useDeclineFriendRequest,
    useFriends,
    useRemoveFriend,
    useSendFriendRequest,
} from '@/api/account/friends/queries';
import { httpErrorToHuman } from '@/api/http';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import PageContentBlock from '@/components/elements/PageContentBlock';
import PageHeading from '@/components/elements/PageHeading';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import { TextInput } from '@/components/form/controls';
import { formatDate } from '@/lib/dates';

const IDENTIFIER_MAX_LENGTH = 191;

function Avatar({ image, name }: { image: string; name: string }) {
    return (
        <img
            src={`${image}?s=64&d=identicon`}
            alt=''
            aria-hidden
            className='size-9 shrink-0 rounded-full'
            title={name}
        />
    );
}

function AddFriend() {
    const [identifier, setIdentifier] = useState('');
    const send = useSendFriendRequest();
    const trimmed = identifier.trim();

    return (
        <TitledGreyBox title='Add a friend'>
            <form
                className='flex flex-col gap-3 sm:flex-row sm:items-end'
                onSubmit={(event) => {
                    event.preventDefault();
                    if (trimmed.length === 0 || send.isPending) {
                        return;
                    }

                    send.mutate({ body: { user: trimmed } }, { onSuccess: () => setIdentifier('') });
                }}
            >
                <div className='min-w-0 flex-1'>
                    <label htmlFor='friend-identifier' className='mb-1 block text-sm font-medium'>
                        Username or email
                    </label>
                    <TextInput
                        id='friend-identifier'
                        value={identifier}
                        maxLength={IDENTIFIER_MAX_LENGTH}
                        autoComplete='off'
                        onChange={(event) => setIdentifier(event.currentTarget.value)}
                        aria-describedby='friend-identifier-help'
                    />
                </div>
                <Button type='submit' disabled={trimmed.length === 0} isLoading={send.isPending}>
                    <UserPlus className='size-4' aria-hidden />
                    <span className='ml-1.5'>Send request</span>
                </Button>
            </form>
            <p id='friend-identifier-help' className='mt-2 text-xs text-muted-foreground'>
                They get a request in the panel and become your friend once they accept. Friends can be picked when you
                invite someone to a server.
            </p>
        </TitledGreyBox>
    );
}

/** The user's friends, the requests waiting for their answer, and the requests they sent. */
export default function FriendsContainer() {
    const { data, error, refetch } = useFriends();
    const accept = useAcceptFriendRequest();
    const decline = useDeclineFriendRequest();
    const cancel = useCancelFriendRequest();
    const remove = useRemoveFriend();

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => refetch()} />;
    }

    const lists = data?.attributes;

    return (
        <PageContentBlock title='Friends'>
            <PageHeading
                title='Friends'
                description='Add friends to pick them quickly when you give someone access to one of your servers.'
            />
            <div className='mt-6 space-y-6'>
                <AddFriend />
                {lists ? (
                    <>
                        {lists.incoming.length > 0 && (
                            <TitledGreyBox title={`Requests for you (${lists.incoming.length})`}>
                                <ul className='divide-y divide-border' aria-label='Friend requests for you'>
                                    {lists.incoming.map((request) => (
                                        <li
                                            key={request.id}
                                            className='flex flex-wrap items-center gap-3 py-3 first:pt-0'
                                        >
                                            <Avatar image={request.image} name={request.username} />
                                            <div className='min-w-0 flex-1'>
                                                <p className='truncate text-sm font-medium'>{request.username}</p>
                                                <p className='text-xs text-muted-foreground'>
                                                    Sent {formatDate(request.created_at, 'MMM D, YYYY')}
                                                </p>
                                            </div>
                                            <Button
                                                type='button'
                                                size='xsmall'
                                                color='green'
                                                disabled={accept.isPending || decline.isPending}
                                                onClick={() => accept.mutate({ path: { friendRequest: request.id } })}
                                                aria-label={`Accept ${request.username}`}
                                            >
                                                Accept
                                            </Button>
                                            <Button
                                                type='button'
                                                size='xsmall'
                                                color='grey'
                                                isSecondary
                                                disabled={accept.isPending || decline.isPending}
                                                onClick={() => decline.mutate({ path: { friendRequest: request.id } })}
                                                aria-label={`Decline ${request.username}`}
                                            >
                                                Decline
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </TitledGreyBox>
                        )}
                        <TitledGreyBox title={`Friends (${lists.friends.length})`}>
                            {lists.friends.length === 0 ? (
                                <p className='text-sm text-muted-foreground'>
                                    No friends yet. Send a request above to add one.
                                </p>
                            ) : (
                                <ul className='divide-y divide-border' aria-label='Friends'>
                                    {lists.friends.map((friend) => (
                                        <li
                                            key={friend.uuid}
                                            className='flex flex-wrap items-center gap-3 py-3 first:pt-0'
                                        >
                                            <Avatar image={friend.image} name={friend.username} />
                                            <div className='min-w-0 flex-1'>
                                                <p className='truncate text-sm font-medium'>{friend.username}</p>
                                                {friend.since && (
                                                    <p className='text-xs text-muted-foreground'>
                                                        Friends since {formatDate(friend.since, 'MMM D, YYYY')}
                                                    </p>
                                                )}
                                            </div>
                                            <Dialog.ConfirmTrigger
                                                title='Remove friend'
                                                confirm='Remove'
                                                onConfirmed={(_event, close) =>
                                                    remove
                                                        .mutateAsync({ path: { friend: friend.uuid } })
                                                        .then(close)
                                                        .catch(() => {
                                                            // The mutation reports the failure.
                                                        })
                                                }
                                                trigger={({ onClick }) => (
                                                    <Button
                                                        type='button'
                                                        size='xsmall'
                                                        color='red'
                                                        isSecondary
                                                        onClick={onClick}
                                                        aria-label={`Remove ${friend.username}`}
                                                    >
                                                        Remove
                                                    </Button>
                                                )}
                                            >
                                                Remove {friend.username} from your friends? Any access they have to your
                                                servers stays until you remove them from those servers.
                                            </Dialog.ConfirmTrigger>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </TitledGreyBox>
                        {lists.outgoing.length > 0 && (
                            <TitledGreyBox title={`Sent requests (${lists.outgoing.length})`}>
                                <p className='mb-3 text-xs text-muted-foreground'>
                                    A request stays here until it is accepted or you cancel it.
                                </p>
                                <ul className='divide-y divide-border' aria-label='Sent friend requests'>
                                    {lists.outgoing.map((request) => (
                                        <li
                                            key={request.id}
                                            className='flex flex-wrap items-center gap-3 py-3 first:pt-0'
                                        >
                                            <div className='min-w-0 flex-1'>
                                                <p className='truncate text-sm font-medium'>{request.identifier}</p>
                                                <p className='text-xs text-muted-foreground'>
                                                    Sent {formatDate(request.created_at, 'MMM D, YYYY')}
                                                </p>
                                            </div>
                                            <Button
                                                type='button'
                                                size='xsmall'
                                                color='grey'
                                                isSecondary
                                                disabled={cancel.isPending}
                                                onClick={() => cancel.mutate({ path: { friendRequest: request.id } })}
                                                aria-label={`Cancel the request to ${request.identifier}`}
                                            >
                                                Cancel
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </TitledGreyBox>
                        )}
                    </>
                ) : (
                    <Spinner size='large' centered />
                )}
            </div>
        </PageContentBlock>
    );
}
