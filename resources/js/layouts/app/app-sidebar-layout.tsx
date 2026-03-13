import { useState } from 'react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import VentesModal from '@/components/VentesModal';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const [showVentes, setShowVentes] = useState(false);

    return (
        <AppShell variant="sidebar">
            <AppSidebar onVentesClick={() => setShowVentes(true)} />
            <AppContent variant="sidebar" className="overflow-x-hidden">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>

            <VentesModal
                open={showVentes}
                onClose={() => setShowVentes(false)}
            />
        </AppShell>
    );
}
