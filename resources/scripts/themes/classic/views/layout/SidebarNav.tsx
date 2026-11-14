import type { ReactNode } from 'react';
import {
    Archive,
    ArrowLeft,
    Blocks,
    CalendarClock,
    Database,
    Egg,
    ExternalLink,
    Folder,
    FolderTree,
    History,
    KeyRound,
    Layers,
    LayoutDashboard,
    LogOut,
    MapPin,
    MessageCircle,
    Network,
    Palette,
    Rocket,
    Server,
    Settings,
    ShieldCheck,
    SquareTerminal,
    Tag,
    Terminal,
    UserRound,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { Avatar, Badge, cn, Icon, NamedIcon, PanelLink } from '@/sdk';
import {
    Navigation,
    NavigationLink,
    Slot,
    useNavigation,
    View,
    type AccountArea,
    type AdminArea,
    type AppLayoutLink,
    type AppLayoutModel,
    type NavItem,
    type ServerArea,
} from '@/sdk/theme';
import MonoForgeMark from '../brand/MonoForgeMark';
import { discordLinkProps } from '../brand/links';

// The panel's own items render these directly, so they never wait for the icon catalog.
const icons = new Map<string, LucideIcon>([
    ['layers', Layers],
    ['user-round', UserRound],
    ['history', History],
    ['key-round', KeyRound],
    ['terminal', Terminal],
    ['square-terminal', SquareTerminal],
    ['folder', Folder],
    ['database', Database],
    ['calendar-clock', CalendarClock],
    ['users', Users],
    ['archive', Archive],
    ['network', Network],
    ['rocket', Rocket],
    ['settings', Settings],
    ['external-link', ExternalLink],
    ['layout-dashboard', LayoutDashboard],
    ['map-pin', MapPin],
    ['server', Server],
    ['folder-tree', FolderTree],
    ['egg', Egg],
    ['tag', Tag],
    ['blocks', Blocks],
    ['palette', Palette],
    ['user-cog', ShieldCheck],
]);

/** A sidebar row: hover tints it, the active one is lit and carries a violet bar at its left edge. */
export const sidebarItemClass = [
    'relative flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm text-sidebar-foreground no-underline',
    'transition-colors duration-150 hover:bg-(--hover-row) hover:text-foreground',
    'before:absolute before:inset-y-1.5 before:left-0 before:w-0.75 before:rounded-full before:bg-sidebar-primary',
    'before:opacity-0 before:shadow-sm before:shadow-sidebar-primary/60 before:transition-opacity before:duration-150',
    'data-[status=active]:bg-(--active-item) data-[status=active]:text-(--active-item-foreground)',
    'data-[status=active]:before:opacity-100',
].join(' ');

// Extensions mount links of their own in the navigation slots; they get the same rows.
const slotClass = [
    'contents',
    '[&_a]:flex [&_a]:items-center [&_a]:gap-3 [&_a]:rounded-md [&_a]:px-3 [&_a]:py-2 [&_a]:text-sm [&_a]:no-underline',
    '[&_a]:text-sidebar-foreground [&_a]:transition-colors [&_a]:duration-150',
    '[&_a:hover]:bg-(--hover-row) [&_a:hover]:text-foreground',
    '[&_a[data-status=active]]:bg-(--active-item) [&_a[data-status=active]]:text-(--active-item-foreground)',
].join(' ');

const groupLabelClass = 'px-3 pt-4 pb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase';

function Glyph({ name }: { name: string | null }) {
    const glyph = name ? icons.get(name) : undefined;

    if (glyph) {
        return <Icon icon={glyph} className='size-4 shrink-0' aria-hidden />;
    }

    return name ? <NamedIcon name={name} size={16} className='shrink-0' aria-hidden /> : null;
}

/** An area item, under its own label unless the sidebar names it. */
function ItemLink({ item, label, badge }: { item: NavItem; label?: string; badge?: ReactNode }) {
    return (
        <NavigationLink item={item} className={sidebarItemClass} title={item.group ?? undefined}>
            <Glyph name={item.icon} />
            <span className='min-w-0 flex-1 truncate'>{label ?? item.label}</span>
            {badge}
        </NavigationLink>
    );
}

function AppLink({ link, label, children }: { link: AppLayoutLink; label: string; children?: ReactNode }) {
    return (
        <PanelLink destination={link.destination} exact={link.exact} className={sidebarItemClass}>
            <Glyph name={link.icon} />
            <span className='min-w-0 flex-1 truncate'>{label}</span>
            {children}
        </PanelLink>
    );
}

function BackLink({ label }: { label: string }) {
    return (
        <PanelLink destination={{ to: '/' }} exact className={cn(sidebarItemClass, 'text-muted-foreground')}>
            <Icon icon={ArrowLeft} className='size-4 shrink-0' aria-hidden />
            <span className='min-w-0 flex-1 truncate'>{label}</span>
        </PanelLink>
    );
}

/** The slots of the area's navigation, which its own layout rendered when it drew the navigation. */
function AreaSlot({ area, at }: { area: AccountArea | ServerArea | AdminArea; at: 'before' | 'after' }) {
    let slot: ReactNode;

    if (area.kind === 'server') {
        slot =
            at === 'before' ? (
                <Slot name='server.navigation.before' data={area.server} />
            ) : (
                <Slot name='server.navigation.after' data={area.server} />
            );
    } else if (area.kind === 'account') {
        slot = at === 'before' ? <Slot name='account.navigation.before' /> : <Slot name='account.navigation.after' />;
    } else {
        slot = at === 'before' ? <Slot name='panel.navigation.before' /> : <Slot name='panel.navigation.after' />;
    }

    return <div className={slotClass}>{slot}</div>;
}

function MainSlot({ at }: { at: 'before' | 'after' }) {
    return (
        <div className={slotClass}>
            {at === 'before' ? <Slot name='nav.items.before' /> : <Slot name='nav.items.after' />}
        </div>
    );
}

/** The core account pages the sidebar names itself, by their route. */
const ACCOUNT_PAGES = new Set(['index', 'activity', 'api', 'ssh']);

const accountPage = (items: readonly NavItem[], id: string) =>
    items.find((item) => item.extensionId === undefined && item.id === id);

/** The account's pages: the overview and activity, then its keys and extension pages under Developer. */
function AccountItems({ items, accountLink }: { items: readonly NavItem[]; accountLink?: AppLayoutLink }) {
    const overview = accountPage(items, 'index');
    const activity = accountPage(items, 'activity');
    const keys = [
        { item: accountPage(items, 'api'), label: 'API keys' },
        { item: accountPage(items, 'ssh'), label: 'SSH keys' },
    ].flatMap(({ item, label }) => (item ? [{ item, label }] : []));
    const rest = items.filter((item) => item.extensionId !== undefined || !ACCOUNT_PAGES.has(item.id));

    return (
        <>
            {overview && <ItemLink item={overview} label='Account' />}
            {!overview && accountLink && <AppLink link={accountLink} label='Account' />}
            {activity && <ItemLink item={activity} label='Activity' />}
            {keys.length + rest.length > 0 && <p className={groupLabelClass}>Developer</p>}
            {keys.map(({ item, label }) => (
                <ItemLink key={item.id} item={item} label={label} />
            ))}
            <Navigation items={rest}>{(item, { badge }) => <ItemLink item={item} badge={badge} />}</Navigation>
        </>
    );
}

/** On the dashboard and in the account: the servers, the account's pages and, for admins, the admin area. */
function PanelNav({ model, area }: { model: AppLayoutModel; area: AccountArea | null }) {
    const published = useNavigation('account');
    const link = (id: AppLayoutLink['id']) => model.links.find((each) => each.id === id);
    const dashboard = link('dashboard');
    const admin = link('admin');

    return (
        <nav aria-label='Main' className='flex flex-col gap-0.5'>
            <MainSlot at='before' />
            {dashboard && (
                <AppLink link={dashboard} label='Servers'>
                    {model.serverCount !== null && (
                        <>
                            <Badge size='sm' variant='soft' data-testid='server-count' aria-hidden>
                                {model.serverCount}
                            </Badge>
                            <span className='sr-only'>
                                , {model.serverCount} {model.serverCount === 1 ? 'server' : 'servers'}
                            </span>
                        </>
                    )}
                </AppLink>
            )}
            {area && <AreaSlot area={area} at='before' />}
            <AccountItems items={area?.nav.items ?? published.items} accountLink={link('account')} />
            {area && <AreaSlot area={area} at='after' />}
            {admin && (
                <>
                    <p className={groupLabelClass}>Admin</p>
                    <AppLink link={admin} label='Admin area' />
                </>
            )}
            <MainSlot at='after' />
        </nav>
    );
}

const stateTone = { running: 'success', starting: 'warning', stopping: 'warning', offline: 'neutral' } as const;
// The same words as the server cards' status pills.
const stateLabel = { running: 'Online', starting: 'Starting', stopping: 'Stopping', offline: 'Offline' } as const;

function ServerNav({ area }: { area: ServerArea }) {
    const items = area.adminLink ? [...area.nav.items, area.adminLink] : area.nav.items;

    return (
        <>
            <nav aria-label='Main' className='flex flex-col gap-0.5'>
                <MainSlot at='before' />
                <BackLink label='All servers' />
                <MainSlot at='after' />
            </nav>
            <nav aria-label='Server' className='flex flex-col gap-0.5'>
                <p className='flex items-center gap-2 px-3 pt-2 pb-1 text-sm font-semibold text-foreground'>
                    <span className='min-w-0 flex-1 truncate'>{area.name}</span>
                    <Badge size='sm' variant='soft' tone={area.state ? stateTone[area.state] : 'neutral'}>
                        {area.state ? stateLabel[area.state] : 'Unknown'}
                    </Badge>
                </p>
                <AreaSlot area={area} at='before' />
                <Navigation items={items}>{(item, { badge }) => <ItemLink item={item} badge={badge} />}</Navigation>
                <AreaSlot area={area} at='after' />
            </nav>
        </>
    );
}

function AdminNav({ area }: { area: AdminArea }) {
    return (
        <>
            <nav aria-label='Main' className='flex flex-col gap-0.5'>
                <MainSlot at='before' />
                <BackLink label='Back to panel' />
                <MainSlot at='after' />
            </nav>
            <nav aria-label='Administration' className='flex flex-col gap-0.5'>
                <p className={groupLabelClass}>Administration</p>
                <AreaSlot area={area} at='before' />
                <Navigation items={area.nav.items}>
                    {(item, { badge }) => <ItemLink item={item} badge={badge} />}
                </Navigation>
                <AreaSlot area={area} at='after' />
            </nav>
        </>
    );
}

function AreaNav({ model }: { model: AppLayoutModel }) {
    const { area } = model;

    if (area.kind === 'server') {
        return <ServerNav area={area} />;
    }

    if (area.kind === 'admin') {
        return <AdminNav area={area} />;
    }

    return <PanelNav model={model} area={area.kind === 'account' ? area : null} />;
}

/** The sidebar's content: the brand and search, every area's navigation, and the user. */
export default function SidebarNav({ model }: { model: AppLayoutModel }) {
    const { brand, user } = model;

    return (
        <div className='flex min-h-full flex-col gap-2'>
            <PanelLink
                destination={{ to: '/' }}
                className='flex h-12 items-center gap-2.5 px-3 font-header text-lg font-semibold text-foreground no-underline'
            >
                {brand.logoUrl ? (
                    <img src={brand.logoUrl} alt={brand.name} className='h-8 w-auto' />
                ) : (
                    <>
                        <span aria-hidden className='contents'>
                            <MonoForgeMark className='size-6 shrink-0' />
                        </span>
                        <span className='min-w-0 truncate'>{brand.name}</span>
                    </>
                )}
            </PanelLink>
            <View name='app.search' className={sidebarItemClass} />
            <div className='flex flex-col gap-2'>
                <AreaNav model={model} />
            </div>
            <div className='flex flex-col gap-0.5'>
                <a {...discordLinkProps} className={sidebarItemClass}>
                    <Icon icon={MessageCircle} className='size-4 shrink-0' aria-hidden />
                    <span className='min-w-0 flex-1 truncate'>Join our Discord</span>
                </a>
            </div>
            <div className='mt-auto flex items-center gap-3 border-t border-sidebar-border px-1 pt-4'>
                <Avatar name={user.uuid} size={32} />
                <div className='min-w-0 flex-1'>
                    <p className='truncate text-sm text-foreground'>{user.username}</p>
                    {user.rootAdmin && <p className='truncate text-xs text-muted-foreground'>Administrator</p>}
                </div>
                <button
                    type='button'
                    aria-label='Sign out'
                    title='Sign out'
                    onClick={model.signOut}
                    className='rounded-md p-2 text-sidebar-foreground transition-colors duration-150 hover:bg-(--hover-row) hover:text-foreground'
                >
                    <Icon icon={LogOut} className='size-4' aria-hidden />
                </button>
            </div>
        </div>
    );
}
