import { lazyRouteComponent } from '@tanstack/react-router';
import { hasBootstrapSession } from '@/bootstrap';
import { layoutRoute } from '@/router/viewRoutes';

const AppLayout = layoutRoute('layout.app');
// Its own chunk, so neither the panel nor the sign-in screen ships the homepage.
const HomePage = lazyRouteComponent(() => import('@/components/home/HomePage'));

/**
 * The signed-in panel's layout. A visitor without a session only gets here at "/" (every other
 * path behind the session sends them to sign in), where the public homepage takes its place.
 */
export default function AuthenticatedLayout() {
    return hasBootstrapSession() ? <AppLayout /> : <HomePage />;
}

AuthenticatedLayout.preload = async (): Promise<void> => {
    await (hasBootstrapSession() ? AppLayout.preload() : HomePage.preload?.());
};
