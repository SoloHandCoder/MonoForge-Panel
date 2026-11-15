/* oxlint-disable rules/design-tokens -- the card radius is the brand's fixed 18px */
import { Fragment, type ReactNode } from 'react';
import { cn, Form } from '@/sdk';
import MonoForgeMark from '../brand/MonoForgeMark';
import { discordLinkProps, LEGAL_LINKS, legalLinkProps } from '../brand/links';

interface Props {
    title: string;
    submit: () => void;
    className?: string;
    children: ReactNode;
}

/** The sign-in card: the mark and name above the title, the form below, and the copyright and legal links under the card. */
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
            <nav aria-label='Legal' className='mt-1 text-center text-muted-foreground text-xs'>
                {LEGAL_LINKS.map((link, index) => (
                    <Fragment key={link.id}>
                        {index > 0 && <span aria-hidden> · </span>}
                        <a {...legalLinkProps(link.href)} className='no-underline hover:text-foreground'>
                            {link.label}
                        </a>
                    </Fragment>
                ))}
            </nav>
        </div>
    );
}

export const authLinkClass = 'text-xs text-muted-foreground tracking-wide uppercase no-underline hover:text-foreground';
