import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';

import {
    adminGetRegistrationSettingsOptions,
    adminGetRegistrationSettingsQueryKey,
    adminGetSettingsQueryKey,
    adminUpdateRegistrationSettingsMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type {
    AdminGetRegistrationSettingsResponse,
    AdminUpdateRegistrationSettingsData,
    Options,
} from '@/api/generated';
import { setSiteSettingsQueryData } from '@/api/settings/queries';
import type { CaptchaProvider } from '@/api/settings/types';
import { notifyHttpError } from '@/plugins/notifications';

/** Sign-up and captcha settings; the Turnstile secret is write-only and only reported as set. */
export interface AdminRegistrationSettings {
    registrationEnabled: boolean;
    captchaProvider: CaptchaProvider;
    turnstileSiteKey: string;
    turnstileSecretKeySet: boolean;
    loadEnvironmentOnly: boolean;
}

export interface RegistrationSettingsValues {
    registrationEnabled: boolean;
    captchaProvider: CaptchaProvider;
    turnstileSiteKey: string;
    /** Blank keeps the stored secret. */
    turnstileSecretKey: string;
}

const captchaProvider = (value: string | undefined): CaptchaProvider =>
    value === 'recaptcha' || value === 'turnstile' ? value : 'none';

export const toAdminRegistrationSettings = (
    response: AdminGetRegistrationSettingsResponse
): AdminRegistrationSettings => ({
    registrationEnabled: response.registration_enabled ?? false,
    captchaProvider: captchaProvider(response.captcha_provider),
    turnstileSiteKey: response.turnstile_site_key ?? '',
    turnstileSecretKeySet: response.turnstile_secret_key_set ?? false,
    loadEnvironmentOnly: response.load_environment_only ?? false,
});

export const updateAdminRegistrationSettingsInput = (
    values: RegistrationSettingsValues
): Options<AdminUpdateRegistrationSettingsData> => ({
    body: {
        registration_enabled: values.registrationEnabled,
        captcha_provider: values.captchaProvider,
        turnstile_site_key: values.turnstileSiteKey === '' ? null : values.turnstileSiteKey,
        turnstile_secret_key: values.turnstileSecretKey === '' ? null : values.turnstileSecretKey,
    },
});

export const adminRegistrationSettingsQueryOptions = () => adminGetRegistrationSettingsOptions();

export const useAdminRegistrationSettings = () =>
    useQuery({ ...adminRegistrationSettingsQueryOptions(), select: toAdminRegistrationSettings });

export const useUpdateAdminRegistrationSettings = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminUpdateRegistrationSettingsMutation(),
        onSuccess: async (_data, { body }) => {
            setSiteSettingsQueryData(queryClient, (settings) => {
                const provider = body.captcha_provider;
                const siteKey = provider === 'turnstile' ? (body.turnstile_site_key ?? '') : settings.recaptcha.siteKey;

                return {
                    ...settings,
                    recaptcha: { ...settings.recaptcha, enabled: provider === 'recaptcha' },
                    captcha: { provider, siteKey: provider === 'none' ? '' : siteKey },
                    registration: { enabled: body.registration_enabled },
                };
            });
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: adminGetRegistrationSettingsQueryKey() }),
                queryClient.invalidateQueries({ queryKey: adminGetSettingsQueryKey() }),
            ]);
            toast.success('Registration settings updated', {
                description: 'Sign-up and captcha settings have been updated.',
            });
        },
        onError: (error) => notifyHttpError(error, 'Unable to update registration settings'),
    });
};
