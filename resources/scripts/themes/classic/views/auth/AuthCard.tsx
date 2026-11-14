/* oxlint-disable rules/design-tokens -- the card radius is the brand's fixed 18px */
import type { ReactNode } from 'react';
import { cn, Form } from '@/sdk';
import MonoForgeMark from '../brand/MonoForgeMark';
import { discordLinkProps } from '../brand/links';

interface Props {
    title: string;
    submit: () => void;
    className?: string;
    children: ReactNode;
}

/** The sign-in card: the mark and name above the title, the form below, and the copyright under the card. */
export default function AuthCard({ title, submit, className, children }: Props) {
    return (
        <div className={cn('mx-auto w-full max-w-md', className)}>
            <Form form={{ handleSubmit: submit }} className='w-full'>
                <div className='w-full rounded-[18px] border border-border bg-card p-8 text-card-foreground shadow-lg'>
                    <div className='mb-6 flex select-none flex-col items-center gap-2'>
                        <MonoForgeMark className='block size-12' />
                        <p className='text-lg font-semibold tracking-wide text-foreground'>MonoForge</p>
                    </div>
                    <h2 className='mb-6 text-center text-xl font-medium text-foreground'>{title}</h2>
                    <div className='w-full'>{children}</div>
                </div>
            </Form>
            <p className='text-center text-muted-foreground text-xs mt-4'>
                &copy; <span suppressHydrationWarning>{new Date().getFullYear()}</span> MonoForge
                <span aria-hidden> · </span>
                <a {...discordLinkProps} className='no-underline hover:text-foreground'>
                    Join our Discord
                </a>
            </p>
        </div>
    );
}

export const authLinkClass = 'text-xs text-muted-foreground tracking-wide uppercase no-underline hover:text-foreground';
