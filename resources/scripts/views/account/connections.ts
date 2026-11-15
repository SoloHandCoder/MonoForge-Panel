import { useEffect } from 'react';
import { useField, useForm, useStore } from '@tanstack/react-form';
import { toast } from 'sonner';
import {
    takeOAuthResult,
    useConnectedAccounts,
    useLinkOAuthAccount,
    useSetInitialPassword,
    useUnlinkOAuthAccount,
} from '@/api/account/oauth';
import { boundField, fixtureField } from '../forms';
import { implementViews } from '../registry';
import type { AccountConnectionsModel, ConnectedAccountItem } from './types';

const newPassword = { type: 'password', autoComplete: 'new-password' } as const;

function useAccountConnectionsModel(): AccountConnectionsModel {
    const { data, isPending } = useConnectedAccounts();
    const link = useLinkOAuthAccount();
    const unlink = useUnlinkOAuthAccount();
    const setInitialPassword = useSetInitialPassword();

    // The provider sends the browser back to the account page after linking.
    useEffect(() => {
        const result = takeOAuthResult();

        if (result?.status === 'success' && result.message) {
            toast.success(result.message);
        } else if (result?.status === 'error' && result.message) {
            toast.error(result.message);
        }
    }, []);

    const form = useForm({
        defaultValues: { password: '', confirmPassword: '' },
        onSubmit: async ({ value, formApi }) => {
            try {
                await setInitialPassword.mutateAsync({
                    password: value.password,
                    password_confirmation: value.confirmPassword,
                });
                formApi.reset();
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });
    const password = useField({
        form,
        name: 'password',
        validators: {
            onChange: ({ value }) =>
                value.length >= 8 ? undefined : 'Your password should be at least 8 characters in length.',
        },
    });
    const confirmPassword = useField({
        form,
        name: 'confirmPassword',
        validators: {
            onChangeListenTo: ['password'],
            onChange: ({ value, fieldApi }) =>
                value === fieldApi.form.getFieldValue('password')
                    ? undefined
                    : 'Password confirmation does not match the password you entered.',
        },
    });
    const canSubmit = useStore(form.store, (state) => state.canSubmit);
    const submitting = useStore(form.store, (state) => state.isSubmitting) || setInitialPassword.isPending;

    const accounts: ConnectedAccountItem[] = (data?.providers ?? [])
        .filter((account) => account.enabled || account.linked)
        .map((account) => ({
            provider: account.provider,
            label: account.label,
            linked: account.linked,
            username: account.username,
            email: account.email,
            avatar: account.avatar,
            canLink: account.enabled,
        }));

    return {
        accounts,
        loading: isPending,
        busy: link.isPending || unlink.isPending,
        link: (provider) => link.mutate(provider),
        unlink: async (provider) => {
            try {
                await unlink.mutateAsync(provider);
            } catch {
                // Error toast is handled by the mutation.
            }
        },
        needsPassword: data !== undefined && !data.has_password,
        setPassword: {
            fields: {
                password: boundField(password, newPassword),
                confirmPassword: boundField(confirmPassword, newPassword),
            },
            submit: () => void form.handleSubmit(),
            canSubmit: canSubmit && !setInitialPassword.isPending,
            submitting,
        },
    };
}

const noop = () => {};

function connectionsFixture(overrides: Partial<AccountConnectionsModel> = {}): AccountConnectionsModel {
    return {
        accounts: [
            {
                provider: 'discord',
                label: 'Discord',
                linked: true,
                username: 'alex',
                email: 'alex@example.test',
                avatar: null,
                canLink: true,
            },
            {
                provider: 'google',
                label: 'Google',
                linked: false,
                username: null,
                email: null,
                avatar: null,
                canLink: true,
            },
        ],
        loading: false,
        busy: false,
        link: noop,
        unlink: () => Promise.resolve(),
        needsPassword: false,
        setPassword: {
            fields: {
                password: fixtureField('password', newPassword),
                confirmPassword: fixtureField('confirmPassword', newPassword),
            },
            submit: noop,
            canSubmit: false,
            submitting: false,
        },
        ...overrides,
    };
}

implementViews({
    'account.connections': {
        use: useAccountConnectionsModel,
        fixtures: {
            default: () => connectionsFixture(),
            needsPassword: () => connectionsFixture({ needsPassword: true }),
            loading: () => connectionsFixture({ accounts: [], loading: true }),
        },
    },
});
