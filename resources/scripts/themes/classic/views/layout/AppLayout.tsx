import { useEffect, useRef, useState } from 'react';
import { Menu, Moon, Sun } from 'lucide-react';
import { cn, DropdownMenu, Icon, SpinnerOverlay, usePanelLocation } from '@/sdk';
import { Main, type AppLayoutModel, type ViewProps } from '@/sdk/theme';
import MonoForgeMark from '../brand/MonoForgeMark';
import SidebarNav, { sidebarItemClass } from './SidebarNav';

const schemeLabels = { dark: 'Dark', light: 'Light' } as const;

/** Only rendered when the theme offers more than one scheme. */
function SchemeMenu({
    model,
    setScheme,
}: {
    model: AppLayoutModel;
    setScheme: NonNullable<AppLayoutModel['setScheme']>;
}) {
    return (
        <DropdownMenu
            triggerClassName={sidebarItemClass}
            triggerContent={
                <>
                    <Icon icon={model.scheme === 'light' ? Sun : Moon} className='size-4 shrink-0' aria-hidden />
                    <span className='sr-only'>Color scheme</span>
                </>
            }
        >
            {model.schemes.map((scheme) => (
                <DropdownMenu.Item key={scheme} checked={scheme === model.preference} onClick={() => setScheme(scheme)}>
                    {schemeLabels[scheme]}
                </DropdownMenu.Item>
            ))}
            <DropdownMenu.Item checked={model.preference === 'system'} onClick={() => setScheme('system')}>
                System
            </DropdownMenu.Item>
        </DropdownMenu>
    );
}

/**
 * Fades the page in again after each navigation to another path. Remounting `<Main>` would replay its
 * `animate-fade-in` too, but would also drop the state of what stays mounted, such as a server's console.
 */
function usePageFade(pathname: string) {
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        const page = document.getElementById('main');
        const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

        // jsdom and older engines have no Web Animations API.
        if (!reduced && page && 'animate' in page) {
            page.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 200, easing: 'ease-out' });
        }
    }, [pathname]);
}

/** A sticky sidebar beside the page from `lg` up, and a drawer behind a menu button below it. */
export default function AppLayout({ model, className, children }: ViewProps<'layout.app'>) {
    const [open, setOpen] = useState(false);
    const { pathname } = usePanelLocation();

    // Close the drawer when one of its links navigates.
    useEffect(() => setOpen(false), [pathname]);
    usePageFade(pathname);

    return (
        <div className={cn('min-h-dvh lg:flex', className)}>
            <SpinnerOverlay visible={model.signingOut} fixed />
            <div className='flex h-14 items-center gap-3 border-b border-sidebar-border bg-sidebar px-4 lg:hidden'>
                <button
                    type='button'
                    aria-expanded={open}
                    aria-controls='sidebar'
                    onClick={() => setOpen((value) => !value)}
                    className='flex items-center gap-2 rounded-md px-3 py-2 text-sm text-sidebar-foreground transition-colors duration-150 hover:bg-(--hover-row) hover:text-foreground'
                >
                    <Icon icon={Menu} className='size-4' aria-hidden />
                    Menu
                </button>
                {!model.brand.logoUrl && (
                    <span aria-hidden className='ml-auto'>
                        <MonoForgeMark className='size-6' />
                    </span>
                )}
            </div>
            <aside
                id='sidebar'
                className={cn(
                    'flex-col border-r border-sidebar-border bg-sidebar p-3 text-sidebar-foreground',
                    'lg:sticky lg:top-0 lg:flex lg:h-dvh lg:w-sidebar lg:shrink-0 lg:overflow-y-auto',
                    open ? 'flex animate-fade-in' : 'hidden'
                )}
            >
                <SidebarNav model={model} />
                {model.setScheme && (
                    <div className='pt-2'>
                        <SchemeMenu model={model} setScheme={model.setScheme} />
                    </div>
                )}
            </aside>
            <Main className='min-w-0 flex-1 animate-fade-in'>{children}</Main>
        </div>
    );
}
