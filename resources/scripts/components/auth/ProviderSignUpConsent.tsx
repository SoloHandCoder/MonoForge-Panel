/* oxlint-disable rules/design-tokens -- the card radius is the brand's fixed 18px */
import { useId, useState } from 'react';
import { useCompleteOAuthSignUp } from '@/api/account/oauth';
import { useSiteSettings } from '@/api/settings/queries';
import Button from '@/components/elements/Button';
import { LegalLink } from '@/components/legal/LegalLinks';
import Checkbox from '@/components/ui/Checkbox';
import { PanelLink } from '@/sdk/navigation';

/**
 * The last step of a Discord or Google sign-up: the account is only created once the visitor
 * agrees to the Terms of Service and Privacy Policy here.
 */
export default function ProviderSignUpConsent() {
    const pending = useSiteSettings()?.oauth?.registration ?? null;
    const complete = useCompleteOAuthSignUp();
    const [agreed, setAgreed] = useState(false);
    const termsId = useId();

    return (
        <div className='mx-auto w-full max-w-md'>
            <div className='w-full rounded-[18px] border border-border bg-card p-8 text-card-foreground shadow-lg'>
                <div className='mb-6 flex select-none flex-col items-center gap-2'>
                    <img src='/favicons/favicon.svg' alt='' className='size-12' />
                    <p className='text-lg font-semibold tracking-wide text-foreground'>MonoForge</p>
                </div>
                <h2 className='mb-4 text-center text-xl font-medium text-foreground'>Create your account</h2>
                {pending ? (
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            complete.mutate(agreed);
                        }}
                    >
                        <p className='text-sm text-muted-foreground'>
                            You are signing up with {pending.label}
                            {pending.email && (
                                <>
                                    {' '}
                                    as <span className='font-medium text-foreground'>{pending.email}</span>
                                </>
                            )}
                            . One last step before we create your account:
                        </p>
                        <div className='mt-6 flex items-start gap-3'>
                            <Checkbox
                                id={termsId}
                                name='terms'
                                checked={agreed}
                                onChange={setAgreed}
                                disabled={complete.isPending}
                            />
                            <label htmlFor={termsId} className='text-sm text-foreground'>
                                I agree to the <LegalLink document='terms' /> and <LegalLink document='privacy' />
                            </label>
                        </div>
                        <Button
                            type='submit'
                            size='xlarge'
                            className='mt-6 w-full justify-center'
                            disabled={!agreed || complete.isPending}
                            isLoading={complete.isPending}
                        >
                            Create Account
                        </Button>
                    </form>
                ) : (
                    <p className='text-center text-sm text-muted-foreground'>
                        This sign up expired or was already finished. Please continue with Discord or Google again from
                        the sign-in page.
                    </p>
                )}
                <div className='mt-6 text-center'>
                    <PanelLink
                        destination={{ to: '/auth/login' }}
                        className='text-xs text-muted-foreground tracking-wide uppercase no-underline hover:text-foreground'
                    >
                        Back to sign in
                    </PanelLink>
                </div>
            </div>
        </div>
    );
}
