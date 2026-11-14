/* oxlint-disable rules/design-tokens -- the sign-in backdrop uses the fixed brand gradient */
import { cn, Main, type ViewProps } from '@/sdk/theme';
import FloatingBlocks from '../brand/FloatingBlocks';
import NoticeStack from './NoticeStack';

/** A full-height page: drifting violet blocks over the brand gradient, the announcements and the sign-in card centred above them. */
export default function AuthLayout({ model, className, children }: ViewProps<'layout.auth'>) {
    return (
        <div
            className={cn(
                'relative isolate flex min-h-screen items-center justify-center overflow-hidden px-4 py-10',
                'bg-[radial-gradient(120%_120%_at_80%_0%,#241a44,#0e0d15_60%)]',
                className
            )}
        >
            <FloatingBlocks />
            <Main className='relative z-10 w-full'>
                <NoticeStack
                    notices={model.notices}
                    onDismiss={model.dismissNotice}
                    className='mx-auto mb-4 w-full max-w-md'
                />
                {children}
            </Main>
        </div>
    );
}
