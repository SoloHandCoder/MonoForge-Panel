import type { ComponentType } from 'react';
import type { RouteSlotData } from '@/extensions/registry';
import type { CorePanelDestination } from '@/sdk/navigation';
import type { FormModel } from '../forms';

/**
 * Every sign-in page is a form: the engine fetches the CSRF cookie, runs the reCAPTCHA, maps errors to
 * toasts and redirects. Place `Captcha` inside the form when it is set, or a captcha-protected panel cannot
 * sign in.
 */
interface AuthFormModel<F extends string> extends FormModel<F> {
    readonly Captcha: ComponentType | null;
}

/** A "Continue with Discord/Google" button. */
export interface SocialLoginProvider {
    readonly id: 'discord' | 'google';
    readonly label: string;
    /** Runs the captcha and sends the browser to the provider. */
    readonly start: () => void;
    /** A sign in with this provider is starting. */
    readonly pending: boolean;
}

export interface LoginFormModel extends AuthFormModel<'username' | 'password'> {
    readonly forgotPassword: CorePanelDestination;
    /** The sign-up page, or null while the panel does not allow sign-up. */
    readonly register: CorePanelDestination | null;
    /** What the `auth.login.form.after` slot receives. */
    readonly route: RouteSlotData;
    /** The enabled Discord and Google sign-in buttons, in display order; empty when none are enabled. */
    readonly providers: readonly SocialLoginProvider[];
}

/** The second step of a two-factor sign-in, by authenticator code or by recovery code. */
export interface CheckpointFormModel extends FormModel<'code'> {
    /** The code field takes a recovery code instead of an authenticator code. */
    readonly recovery: boolean;
    readonly toggleRecovery: () => void;
    readonly login: CorePanelDestination;
}

export interface ForgotPasswordFormModel extends AuthFormModel<'email'> {
    readonly login: CorePanelDestination;
}

export interface ResetPasswordFormModel extends AuthFormModel<'password' | 'passwordConfirmation'> {
    /** The account being reset, from the emailed link. */
    readonly email: string;
    readonly login: CorePanelDestination;
}

/** A checkbox the visitor must tick, such as agreeing to the Terms of Service and Privacy Policy. */
export interface CheckboxFieldModel {
    readonly name: string;
    readonly checked: boolean;
    readonly onChange: (checked: boolean) => void;
    readonly disabled?: boolean;
    readonly error: string | null;
}

/** Sign-up: a new account's username, email and password, and the visitor's agreement to the Terms of Service and Privacy Policy. */
export interface RegisterFormModel extends AuthFormModel<'username' | 'email' | 'password' | 'passwordConfirmation'> {
    readonly terms: CheckboxFieldModel;
    readonly login: CorePanelDestination;
}

declare module '../registry' {
    interface ViewModels {
        'page.auth.login': LoginFormModel;
        'page.auth.checkpoint': CheckpointFormModel;
        'page.auth.password': ForgotPasswordFormModel;
        'page.auth.passwordReset': ResetPasswordFormModel;
        'page.auth.register': RegisterFormModel;
    }
}
