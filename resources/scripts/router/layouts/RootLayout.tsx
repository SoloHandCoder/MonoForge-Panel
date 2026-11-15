import { lazy, Suspense } from 'react';
import { Outlet, useRouterState } from '@tanstack/react-router';
import { usePendingLegalDocuments } from '@/api/legal';
import { HttpProgressBar } from '@/components/elements/ProgressBar';
import { RouteAnnouncer, SkipLink } from '@/views/a11y';

const LegalAcceptanceGate = lazy(() => import('@/components/legal/LegalAcceptanceGate'));

/** The updated-terms prompt, loaded only when the signed-in user has documents to accept. */
function LegalGate() {
    const pending = usePendingLegalDocuments();
    const pathname = useRouterState({ select: (state) => state.location.pathname });

    if (pending.length === 0) {
        return null;
    }

    return (
        <Suspense fallback={null}>
            <LegalAcceptanceGate pathname={pathname} />
        </Suspense>
    );
}

export default function RootLayout() {
    return (
        <>
            <SkipLink />
            <HttpProgressBar />
            <div className='mx-auto w-auto'>
                <Outlet />
            </div>
            <RouteAnnouncer />
            <LegalGate />
        </>
    );
}
