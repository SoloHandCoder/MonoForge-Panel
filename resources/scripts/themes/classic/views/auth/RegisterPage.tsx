import { useId } from 'react';
import { Button, Checkbox, PanelLink } from '@/sdk';
import type { ViewProps } from '@/sdk/theme';
import { TextField } from '../shared/fields';
import AuthCard, { authLinkClass } from './AuthCard';

export default function RegisterPage({ model, className }: ViewProps<'page.auth.register'>) {
    const { fields, terms, Captcha } = model;
    const termsId = useId();

    return (
        <AuthCard title='Create an Account' submit={model.submit} className={className}>
            <TextField field={fields.username} light label='Username' />
            <div className='mt-6'>
                <TextField field={fields.email} light label='Email' />
            </div>
            <div className='mt-6'>
                <TextField
                    field={fields.password}
                    light
                    label='Password'
                    description='Passwords must be at least 8 characters in length.'
                />
            </div>
            <div className='mt-6'>
                <TextField field={fields.passwordConfirmation} light label='Confirm Password' />
            </div>
            <div className='mt-6'>
                <div className='flex items-center gap-3'>
                    <Checkbox
                        id={termsId}
                        name={terms.name}
                        checked={terms.checked}
                        onChange={terms.onChange}
                        disabled={terms.disabled}
                        aria-invalid={terms.error ? true : undefined}
                        aria-describedby={terms.error ? `${termsId}-error` : undefined}
                    />
                    <label htmlFor={termsId} className='text-sm text-foreground'>
                        I agree to the rules
                    </label>
                </div>
                {terms.error && (
                    <p id={`${termsId}-error`} className='input-help error'>
                        {terms.error}
                    </p>
                )}
            </div>
            <div className='mt-6'>
                <Button type='submit' size='xlarge' disabled={!model.canSubmit} isLoading={model.submitting}>
                    Create Account
                </Button>
            </div>
            {Captcha && <Captcha />}
            <div className='mt-6 text-center'>
                <PanelLink destination={model.login} className={authLinkClass}>
                    Already have an account? Sign in
                </PanelLink>
            </div>
        </AuthCard>
    );
}
