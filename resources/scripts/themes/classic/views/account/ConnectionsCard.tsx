import { useState } from 'react';
import { Button, ContentBox, Dialog, Form, Spinner, type ConnectedAccountItem, type ConnectedProvider } from '@/sdk';
import type { ViewProps } from '@/sdk/theme';
import { TextField } from '../shared/fields';

function AccountRow({
    account,
    busy,
    onLink,
    onUnlink,
}: {
    account: ConnectedAccountItem;
    busy: boolean;
    onLink: () => void;
    onUnlink: () => void;
}) {
    return (
        <li className='flex items-center justify-between gap-4 py-3'>
            <div className='flex min-w-0 items-center gap-3'>
                {account.avatar ? (
                    <img
                        src={account.avatar}
                        alt=''
                        className='size-8 shrink-0 rounded-full'
                        referrerPolicy='no-referrer'
                    />
                ) : (
                    <span
                        aria-hidden
                        className='flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold text-muted-foreground'
                    >
                        {account.label.charAt(0)}
                    </span>
                )}
                <div className='min-w-0'>
                    <p className='text-sm font-medium text-foreground'>{account.label}</p>
                    <p className='truncate text-xs text-muted-foreground'>
                        {account.linked ? account.username || account.email || 'Linked' : 'Not linked'}
                    </p>
                </div>
            </div>
            {account.linked ? (
                <Button.Danger size='small' isSecondary disabled={busy} onClick={onUnlink}>
                    Unlink
                </Button.Danger>
            ) : (
                <Button size='small' disabled={busy || !account.canLink} onClick={onLink}>
                    Link
                </Button>
            )}
        </li>
    );
}

/** Discord and Google accounts that can sign the user in; hidden when neither is turned on or linked. */
export default function ConnectionsCard({ model, className }: ViewProps<'account.connections'>) {
    const [unlinking, setUnlinking] = useState<ConnectedProvider | null>(null);
    const pending = model.accounts.find((account) => account.provider === unlinking);

    if (!model.loading && model.accounts.length === 0) {
        return null;
    }

    return (
        <ContentBox className={className} title='Connected Accounts'>
            <Dialog.Confirm
                title={`Unlink ${pending?.label ?? 'account'}`}
                confirm='Unlink'
                open={unlinking !== null}
                pending={model.busy}
                onClose={() => setUnlinking(null)}
                onConfirmed={() => {
                    if (unlinking) {
                        void model.unlink(unlinking).then(() => setUnlinking(null));
                    }
                }}
            >
                You will no longer be able to sign in with this {pending?.label ?? ''} account.
            </Dialog.Confirm>
            <p className='text-sm'>Sign in with Discord or Google instead of your password.</p>
            {model.loading ? (
                <Spinner size='large' centered />
            ) : (
                <ul className='mt-2 divide-y divide-border'>
                    {model.accounts.map((account) => (
                        <AccountRow
                            key={account.provider}
                            account={account}
                            busy={model.busy}
                            onLink={() => model.link(account.provider)}
                            onUnlink={() => setUnlinking(account.provider)}
                        />
                    ))}
                </ul>
            )}
            {model.needsPassword && (
                <Form
                    form={{ handleSubmit: model.setPassword.submit }}
                    className='m-0 mt-4 border-t border-border pt-4'
                >
                    <p className='text-sm text-muted-foreground'>
                        Your account was created with Discord or Google. Set a password so you can still sign in, and
                        before unlinking your last connected account.
                    </p>
                    <div className='mt-4'>
                        <TextField
                            field={model.setPassword.fields.password}
                            id='initial_password'
                            label='New Password'
                        />
                    </div>
                    <div className='mt-4'>
                        <TextField
                            field={model.setPassword.fields.confirmPassword}
                            id='initial_password_confirmation'
                            label='Confirm Password'
                        />
                    </div>
                    <div className='mt-4'>
                        <Button
                            type='submit'
                            disabled={!model.setPassword.canSubmit}
                            isLoading={model.setPassword.submitting}
                        >
                            Set Password
                        </Button>
                    </div>
                </Form>
            )}
        </ContentBox>
    );
}
