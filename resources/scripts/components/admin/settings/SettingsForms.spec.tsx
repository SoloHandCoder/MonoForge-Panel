/** @vitest-environment jsdom */

import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { AdminSettings } from '@/api/admin/settings/queries';
import type * as SettingsQueries from '@/api/admin/settings/queries';
import GeneralSettingsForm from './GeneralSettingsForm';
import AdvancedSettingsForm from './AdvancedSettingsForm';
import MailSettingsForm from './MailSettingsForm';

const mutations = vi.hoisted(() => ({ general: vi.fn(), advanced: vi.fn(), mail: vi.fn(), testMail: vi.fn() }));

vi.mock('@/api/admin/settings/queries', async (importOriginal) => ({
    ...(await importOriginal<typeof SettingsQueries>()),
    useUpdateAdminGeneralSettings: () => ({ mutateAsync: mutations.general }),
    useUpdateAdminAdvancedSettings: () => ({ mutateAsync: mutations.advanced }),
    useUpdateAdminMailSettings: () => ({ mutateAsync: mutations.mail }),
    useSendTestAdminMail: () => ({ mutateAsync: mutations.testMail }),
}));
vi.mock('@/api/admin/languages/queries', () => ({
    useAdminLanguages: () => ({ data: { en: 'English' }, isLoading: false }),
}));

const settings: AdminSettings = {
    general: { 'app:name': 'Panel', 'pterodactyl:auth:2fa_required': 0, 'app:locale': 'en' },
    mail: {
        'mail:default': 'smtp',
        'mail:mailers:smtp:host': 'localhost',
        'mail:mailers:smtp:port': 587,
        'mail:mailers:smtp:encryption': 'tls',
        'mail:mailers:smtp:username': null,
        'mail:mailers:smtp:password': '',
        'mail:from:address': 'panel@example.com',
        'mail:from:name': 'Panel',
        'services:resend:key_set': false,
    },
    advanced: {
        'recaptcha:enabled': false,
        'recaptcha:secret_key': '',
        'recaptcha:website_key': '',
        'pterodactyl:guzzle:timeout': 10,
        'pterodactyl:guzzle:connect_timeout': 5,
        'pterodactyl:client_features:allocations:enabled': false,
        'pterodactyl:client_features:allocations:range_start': null,
        'pterodactyl:client_features:allocations:range_end': null,
        'monoforge:privacy:hidden_ips': '',
    },
    // Environment-only, with .env also setting the one MonoForge key these forms hold.
    meta: { load_environment_only: true, show_recaptcha_warning: false, set_in_env: ['monoforge:privacy:hidden_ips'] },
};

afterEach(cleanup);
beforeEach(() => vi.clearAllMocks());

const forms = [
    { name: 'general', Component: GeneralSettingsForm, mutation: mutations.general },
    { name: 'advanced', Component: AdvancedSettingsForm, mutation: mutations.advanced },
    { name: 'mail', Component: MailSettingsForm, mutation: mutations.mail },
];

describe('mail settings', () => {
    it('submits the entered password once and then clears it', async () => {
        const user = userEvent.setup();

        render(
            <MailSettingsForm settings={{ ...settings, meta: { ...settings.meta, load_environment_only: false } }} />
        );

        const password = screen.getByLabelText('Password');

        await user.type(password, 'smtp-secret');
        await user.click(screen.getByRole('button', { name: 'Save Changes' }));

        expect(mutations.mail).toHaveBeenCalledWith({
            body: expect.objectContaining({
                driver: 'smtp',
                smtp: expect.objectContaining({ port: 587, password: 'smtp-secret' }),
                from_address: 'panel@example.com',
            }),
        });
        expect(password).toHaveValue('');
    });

    it('sends the Resend key once without SMTP settings', async () => {
        const user = userEvent.setup();

        render(
            <MailSettingsForm
                settings={{
                    ...settings,
                    mail: { ...settings.mail, 'mail:default': 'resend' },
                    meta: { ...settings.meta, load_environment_only: false },
                }}
            />
        );

        expect(screen.queryByLabelText('SMTP Host')).toBeNull();
        const key = screen.getByLabelText('Resend API Key');

        await user.type(key, 're_secret');
        await user.click(screen.getByRole('button', { name: 'Save Changes' }));

        expect(mutations.mail).toHaveBeenCalledWith({
            body: {
                driver: 'resend',
                from_address: 'panel@example.com',
                from_name: 'Panel',
                resend: { api_key: 're_secret' },
            },
        });
        expect(key).toHaveValue('');
    });
});

describe('advanced settings', () => {
    it('sends the hidden IP address list', async () => {
        const user = userEvent.setup();

        render(
            <AdvancedSettingsForm
                settings={{ ...settings, meta: { ...settings.meta, load_environment_only: false } }}
            />
        );

        await user.type(screen.getByLabelText('Hidden IP Addresses'), '203.0.113.7{Enter}2001:db8::/32');
        await user.click(screen.getByRole('button', { name: 'Save Changes' }));

        expect(mutations.advanced).toHaveBeenCalledWith({
            body: expect.objectContaining({ 'monoforge:privacy:hidden_ips': '203.0.113.7\n2001:db8::/32' }),
        });
    });
});

describe('advanced settings in environment-only mode', () => {
    it('keeps the hidden IPs editable while MONOFORGE_HIDDEN_IPS is unset', async () => {
        const user = userEvent.setup();

        render(<AdvancedSettingsForm settings={{ ...settings, meta: { ...settings.meta, set_in_env: [] } }} />);

        expect(screen.getByLabelText('reCAPTCHA Website Key')).toBeDisabled();
        expect(screen.getByLabelText('Hidden IP Addresses')).toBeEnabled();

        await user.type(screen.getByLabelText('Hidden IP Addresses'), '203.0.113.7');
        await user.click(screen.getByRole('button', { name: 'Save Changes' }));

        expect(mutations.advanced).toHaveBeenCalledOnce();
    });
});

describe.each(forms)('$name settings', ({ Component, mutation }) => {
    it('disables every setting and ignores native submissions in environment-only mode', async () => {
        const { container } = render(<Component settings={settings} />);

        const controls = container.querySelectorAll('input, button');

        expect(controls.length).toBeGreaterThan(1);
        for (const control of controls) {
            expect(control).toBeDisabled();
        }

        await act(async () => fireEvent.submit(container.querySelector('form')!));

        expect(mutation).not.toHaveBeenCalled();
        expect(mutations.testMail).not.toHaveBeenCalled();
    });

    it('allows saving when settings are loaded dynamically', async () => {
        render(<Component settings={{ ...settings, meta: { ...settings.meta, load_environment_only: false } }} />);

        const save = screen.getByRole('button', { name: 'Save Changes' });

        expect(save).toBeEnabled();
        await act(async () => fireEvent.click(save));

        expect(mutation).toHaveBeenCalledOnce();
    });
});
