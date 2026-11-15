import { useField, useForm, useStore } from '@tanstack/react-form';
import { useRegister } from '@/api/auth/queries';
import { useLegalDocuments } from '@/api/legal';
import { firstError } from '@/components/form/errors';
import { boundField, fixtureField } from '../forms';
import { implementViews } from '../registry';
import { useCaptcha } from './captcha';
import type { CheckboxFieldModel, RegisterFormModel } from './types';

const isEmail = (value: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
// The same characters the panel accepts for usernames; the server has the final say.
const isUsername = (value: string) => /^[a-z0-9]([\w.-]+)[a-z0-9]$/.test(value.toLowerCase());
const newPassword = { type: 'password', autoComplete: 'new-password' } as const;
const RULES_REQUIRED = 'You must agree to the Terms of Service and Privacy Policy to create an account.';

function useRegisterFormModel(): RegisterFormModel {
    const register = useRegister();
    const { Captcha, token } = useCaptcha('register');
    // The versions the linked documents were at when the form opened; the server refuses older ones.
    const { terms: termsDocument, privacy: privacyDocument } = useLegalDocuments();
    const form = useForm({
        defaultValues: { username: '', email: '', password: '', passwordConfirmation: '', terms: false },
        onSubmit: async ({ value }) => {
            const captcha = await token();

            if (captcha === null) {
                return;
            }

            try {
                const response = await register.mutateAsync({
                    body: {
                        username: value.username,
                        email: value.email,
                        password: value.password,
                        password_confirmation: value.passwordConfirmation,
                        terms: value.terms,
                        terms_version: termsDocument.version,
                        privacy_version: privacyDocument.version,
                        'g-recaptcha-response': captcha,
                    },
                });

                window.location.assign(response.data.intended || '/');
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });
    const username = useField({
        form,
        name: 'username',
        validators: {
            onChange: ({ value }) =>
                isUsername(value)
                    ? undefined
                    : 'Usernames start and end with a letter or number and may contain dashes, underscores and periods.',
        },
    });
    const email = useField({
        form,
        name: 'email',
        validators: {
            onChange: ({ value }) => (isEmail(value) ? undefined : 'A valid email address must be provided.'),
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
    const confirmation = useField({
        form,
        name: 'passwordConfirmation',
        validators: {
            onChangeListenTo: ['password'],
            onChange: ({ value, fieldApi }) =>
                value === fieldApi.form.getFieldValue('password') ? undefined : 'Your passwords do not match.',
        },
    });
    const terms = useField({
        form,
        name: 'terms',
        validators: { onChange: ({ value }) => (value ? undefined : RULES_REQUIRED) },
    });
    const canSubmit = useStore(form.store, (state) => state.canSubmit);
    const submitting = useStore(form.store, (state) => state.isSubmitting) || register.isPending;

    return {
        fields: {
            username: boundField(username, { type: 'text', autoComplete: 'username', disabled: submitting }),
            email: boundField(email, { type: 'email', autoComplete: 'email', disabled: submitting }),
            password: boundField(password, { ...newPassword, disabled: submitting }),
            passwordConfirmation: boundField(confirmation, { ...newPassword, disabled: submitting }),
        },
        terms: {
            name: 'terms',
            checked: terms.state.value,
            onChange: (checked) => terms.handleChange(checked),
            disabled: submitting,
            error: firstError(terms.state.meta.errors) ?? null,
        },
        submit: () => void form.handleSubmit(),
        // The Terms of Service and Privacy Policy must be ticked before the form can be sent.
        canSubmit: canSubmit && terms.state.value,
        submitting,
        Captcha,
        login: { to: '/auth/login' },
    };
}

const noop = () => {};

function termsFixture(checked: boolean, error: string | null = null): CheckboxFieldModel {
    return { name: 'terms', checked, onChange: noop, error };
}

function registerFixture(invalid: boolean): RegisterFormModel {
    return {
        fields: {
            username: fixtureField('username', { type: 'text', autoComplete: 'username' }, invalid ? '-x' : 'alex'),
            email: fixtureField(
                'email',
                { type: 'email', autoComplete: 'email' },
                invalid ? 'alex' : 'alex@example.test',
                invalid ? 'A valid email address must be provided.' : null
            ),
            password: fixtureField('password', newPassword, 'correct horse'),
            passwordConfirmation: fixtureField(
                'passwordConfirmation',
                newPassword,
                invalid ? 'correct horse battery' : 'correct horse',
                invalid ? 'Your passwords do not match.' : null
            ),
        },
        terms: termsFixture(!invalid, invalid ? RULES_REQUIRED : null),
        submit: noop,
        canSubmit: !invalid,
        submitting: false,
        Captcha: null,
        login: { to: '/auth/login' },
    };
}

implementViews({
    'page.auth.register': {
        use: useRegisterFormModel,
        fixtures: { default: () => registerFixture(false), invalid: () => registerFixture(true) },
    },
});
