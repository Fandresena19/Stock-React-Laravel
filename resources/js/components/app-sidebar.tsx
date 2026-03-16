import { usePage } from '@inertiajs/react';
import { Link, router } from '@inertiajs/react';
import {
    LayoutGrid,
    PackagePlus,
    Receipt,
    ShoppingBasket,
    UsersRound,
    Warehouse,
    Sun,
    Moon,
} from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { NavFooter } from '@/components/nav-footer';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useAppearance } from '@/hooks/use-appearance';
import { dashboard } from '@/routes';

interface AppSidebarProps {
    onVentesClick?: () => void;
}

export function AppSidebar({ onVentesClick }: AppSidebarProps) {
    const { url } = usePage();
    const { appearance, updateAppearance } = useAppearance();

    const isDark = appearance === 'dark';
    const toggleTheme = () => updateAppearance(isDark ? 'light' : 'dark');

    const navItems = [
        { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
        { title: 'Articles', href: '/articles', icon: ShoppingBasket },
        { title: 'Achats', href: '/achats', icon: PackagePlus },
        { title: 'Ventes', href: '/ventes', icon: Receipt, modal: true },
        { title: 'Stocks', href: '/stocks', icon: Warehouse },
        { title: 'Fournisseurs', href: '/fournisseurs', icon: UsersRound },
    ];

    const isActive = (href: string) =>
        url === href || url.startsWith(href + '/');

    return (
        <Sidebar
            collapsible="icon"
            variant="inset"
            className="border-r-0 bg-gray-950 dark:bg-gray-950"
        >
            {/* ── LOGO ── */}
            <SidebarHeader className="bg-gray-950 px-4 py-5">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link
                                href={dashboard().url}
                                prefetch
                                className="flex items-center gap-3"
                            >
                                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-[#7a1a2e]">
                                    <Warehouse
                                        size={16}
                                        className="text-white"
                                    />
                                </div>
                                <span className="text-sm font-bold tracking-wide text-white">
                                    Gestion de stocks
                                </span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            {/* ── NAV ── */}
            <SidebarContent className="bg-gray-950 px-3 py-2">
                <SidebarMenu className="gap-0.5">
                    {navItems.map((item) => {
                        const active = isActive(item.href);
                        return (
                            <SidebarMenuItem key={item.title}>
                                <SidebarMenuButton
                                    asChild
                                    isActive={active}
                                    tooltip={{ children: item.title }}
                                    className={`group rounded-lg px-3 py-2.5 transition-all duration-150 ${
                                        active
                                            ? 'bg-[#7a1a2e] font-semibold text-white'
                                            : 'text-gray-400 hover:bg-[#7a1a2e]/80 hover:text-white'
                                    } `}
                                >
                                    {item.modal ? (
                                        <a
                                            href={item.href}
                                            onClick={(e) => {
                                                e.preventDefault();
                                                onVentesClick?.();
                                            }}
                                            className="flex items-center gap-3"
                                        >
                                            <item.icon size={17} />
                                            <span className="text-sm">
                                                {item.title}
                                            </span>
                                        </a>
                                    ) : (
                                        <Link
                                            href={item.href}
                                            prefetch
                                            className="flex items-center gap-3"
                                        >
                                            <item.icon size={17} />
                                            <span className="text-sm">
                                                {item.title}
                                            </span>
                                        </Link>
                                    )}
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        );
                    })}
                </SidebarMenu>

                {/* ── TOGGLE DARK / LIGHT ── */}
                <div className="mt-6 px-1">
                    <button
                        onClick={toggleTheme}
                        className="flex w-full items-center justify-between rounded-lg border border-gray-800 px-3 py-2.5 text-gray-400 transition-colors hover:border-gray-700 hover:text-white"
                    >
                        <div className="flex items-center gap-3">
                            {isDark ? (
                                <Moon size={16} className="text-blue-400" />
                            ) : (
                                <Sun size={16} className="text-yellow-400" />
                            )}
                            <span className="text-sm">
                                {isDark ? 'Dark mode' : 'Light mode'}
                            </span>
                        </div>
                        {/* Toggle pill */}
                        <div
                            className={`relative h-5 w-9 rounded-full transition-colors ${isDark ? 'bg-[#7a1a2e]' : 'bg-gray-600'}`}
                        >
                            <div
                                className={`absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform ${isDark ? 'translate-x-4' : 'translate-x-0.5'}`}
                            />
                        </div>
                    </button>
                </div>
            </SidebarContent>

            {/* ── FOOTER ── */}
            <SidebarFooter className="border-t border-gray-800 bg-gray-950 pb-3">
                <NavFooter items={[]} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
