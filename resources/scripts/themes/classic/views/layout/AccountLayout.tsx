import { Main, type ViewProps } from '@/sdk/theme';

/** The sidebar shell draws this area's navigation, so the layout only places its pages. */
export default function AccountLayout({ className, children }: ViewProps<'layout.account'>) {
    return <Main className={className}>{children}</Main>;
}
