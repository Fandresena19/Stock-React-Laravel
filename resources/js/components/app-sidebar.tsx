import { Link } from '@inertiajs/react';
import {
    LayoutGrid,
    PackagePlus,
    Receipt,
    ShoppingBasket,
    UsersRound,
    Warehouse,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
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
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

// ─── Props ───────────────────────────────────────────────────────────────────

interface AppSidebarProps {
    onVentesClick?: () => void;
}

// ─── Composant ───────────────────────────────────────────────────────────────

export function AppSidebar({ onVentesClick }: AppSidebarProps) {
    const mainNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: 'Articles',
            href: '/articles',
            icon: ShoppingBasket,
        },
        {
            title: 'Fournisseurs',
            href: '/fournisseurs',
            icon: UsersRound,
        },
        {
            title: 'Achats',
            href: '/achats',
            icon: PackagePlus,
        },
        {
            title: 'Stocks',
            href: '/stocks',
            icon: Warehouse,
        },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />

                {/* Ventes : même apparence que NavMain mais déclenche une modale */}
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton asChild tooltip="Ventes">
                            {/*
                                On utilise un <a> avec href="/ventes" pour que :
                                - L'item soit actif visuellement quand on est sur /ventes
                                - Le clic ouvre la modale (preventDefault) sans naviguer
                                - Le style reste identique aux autres items NavMain
                            */}
                            <a
                                href="/ventes"
                                onClick={(e) => {
                                    e.preventDefault();
                                    onVentesClick?.();
                                }}
                                className="flex items-center gap-2"
                            >
                                <Receipt />
                                <span>Ventes</span>
                            </a>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={[]} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
