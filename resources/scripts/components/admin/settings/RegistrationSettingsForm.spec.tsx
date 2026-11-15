/** @vitest-environment jsdom */

import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as RegistrationQueries from '@/api/admin/settings/registration';
import type { AdminRegistrationSettings } from '@/api/admin/settings/registration';
import { RegistrationSettingsForm } from './RegistrationSettingsForm';

const update = vi.hoisted(() => vi.fn());

vi.mock('@/api/admin/settings/registration', async (importOriginal) => ({
    ...(await importOriginal<typeof RegistrationQueries>()),
    useUpdateAdminRegistrationSettings: () => ({ mutateAsync: update }),
}));

const settings: AdminRegistrationSettings = {
    registrationEnabled: false,
    captchaProvider: 'turnstile',
    turnstileSiteKey: 'site-key',
    turnstileSecretKeySet: true,
    loadEnvironmentOnly: false,
    setInEnv: {
        registrationEnabled: false,
        captchaProvider: false,
        turnstileSiteKey: false,
        turnstileSecretKey: false,
    },
};

const allSetInEnv = {
    registrationEnabled: true,
    captchaProvider: true,
    turnstileSiteKey: true,
    turnstileSecretKey: true,
};

afterEach(cleanup);
beforeEach(() => vi.clearAllMocks());

describe('registration settings', () => {
    it('never shows the stored Turnstile secret and sends a new one once', async () => {
        const user = userEvent.setup();

        render(<RegistrationSettingsForm settings={settings} />);

        const secret = screen.getByLabelText('Turnstile Secret Key');

        expect(secret).toHaveValue('');
        expect(screen.getByText('A secret key is stored. Leave blank to keep it.')).toBeVisible();

        await user.click(screen.getByRole('switch', { name: 'Allow sign-up' }));
        await user.type(secret, 'new-secret');
        await user.click(screen.getByRole('button', { name: 'Save Changes' }));

        expect(update).toHaveBeenCalledWith({
            body: {
                registration_enabled: true,
                captcha_provider: 'turnstile',
                turnstile_site_key: 'site-key',
                turnstile_secret_key: 'new-secret',
            },
        });
        expect(secret).toHaveValue('');
    });

    it('hides the Turnstile keys for other providers and is read-only when .env sets every field', async () => {
        const { container } = render(
            <RegistrationSettingsForm
                settings={{
                    ...settings,
                    captchaProvider: 'recaptcha',
                    loadEnvironmentOnly: true,
                    setInEnv: allSetInEnv,
                }}
            />
        );

        expect(screen.queryByLabelText('Turnstile Site Key')).toBeNull();
        expect(screen.getByText('The reCAPTCHA keys are set on the Advanced tab.')).toBeVisible();
        expect(screen.getByRole('button', { name: 'Save Changes' })).toBeDisabled();

        await act(async () => fireEvent.submit(container.querySelector('form')!));

        expect(update).not.toHaveBeenCalled();
    });

    it('in environment-only mode locks only the fields that .env sets', () => {
        render(
            <RegistrationSettingsForm
                settings={{
                    ...settings,
                    loadEnvironmentOnly: true,
                    setInEnv: { ...settings.setInEnv, captchaProvider: true },
                }}
            />
        );

        expect(screen.getByText(/Set in \.env \(MONOFORGE_CAPTCHA_PROVIDER\)/)).toBeInTheDocument();
        expect(screen.getByLabelText('Turnstile Site Key')).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Save Changes' })).toBeEnabled();
    });
});
