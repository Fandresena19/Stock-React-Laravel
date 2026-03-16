// resources/js/components/ui/page-components.tsx
// Composants réutilisables partagés entre toutes les pages

import { ReactNode } from 'react';
import { router } from '@inertiajs/react';

// ── PageHeader ────────────────────────────────────────────────────────────────
export function PageHeader({ title, subtitle, action }: {
    title: string; subtitle?: string; action?: ReactNode;
}) {
    return (
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 className="text-2xl font-bold text-gray-800 dark:text-white">{title}</h1>
                {subtitle && <p className="mt-0.5 text-sm text-gray-400">{subtitle}</p>}
            </div>
            {action && <div>{action}</div>}
        </div>
    );
}

// ── BtnPrimary ────────────────────────────────────────────────────────────────
export function BtnPrimary({ children, onClick, type = 'button', disabled = false, small = false }: {
    children: ReactNode; onClick?: () => void; type?: 'button' | 'submit';
    disabled?: boolean; small?: boolean;
}) {
    return (
        <button type={type} onClick={onClick} disabled={disabled}
            className={`flex items-center gap-2 rounded-xl bg-[#7a1a2e] font-semibold text-white shadow-sm transition-all hover:bg-[#6b1525] active:scale-95 disabled:opacity-50
                ${small ? 'px-3 py-1.5 text-xs' : 'px-4 py-2.5 text-sm'}`}>
            {children}
        </button>
    );
}

// ── BtnSecondary ──────────────────────────────────────────────────────────────
export function BtnSecondary({ children, onClick, type = 'button' }: {
    children: ReactNode; onClick?: () => void; type?: 'button' | 'submit';
}) {
    return (
        <button type={type} onClick={onClick}
            className="rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-600 shadow-sm transition-all hover:bg-gray-50 active:scale-95 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
            {children}
        </button>
    );
}

// ── BtnDanger ─────────────────────────────────────────────────────────────────
export function BtnIcon({ onClick, color, children }: {
    onClick: () => void; color: 'orange' | 'red'; children: ReactNode;
}) {
    const c = { orange: 'bg-orange-500 hover:bg-orange-600', red: 'bg-red-700 hover:bg-red-800' };
    return (
        <button onClick={onClick}
            className={`rounded-lg p-1.5 text-white transition-colors active:scale-95 ${c[color]}`}>
            {children}
        </button>
    );
}

// ── DataTable ─────────────────────────────────────────────────────────────────
export function DataTable({ headers, children, empty = 'Aucun résultat' }: {
    headers: { label: string; align?: 'left' | 'right' | 'center' }[];
    children: ReactNode; empty?: string;
}) {
    return (
        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div className="max-h-[500px] overflow-y-auto">
                <table className="w-full text-sm">
                    <thead className="sticky top-0 z-10 bg-[#7a1a2e] text-white">
                        <tr>
                            {headers.map((h, i) => (
                                <th key={i} className={`px-4 py-3 text-${h.align ?? 'left'} text-xs font-semibold uppercase tracking-wide`}>
                                    {h.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50 dark:divide-gray-700">
                        {children}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

// ── Tr ────────────────────────────────────────────────────────────────────────
export function Tr({ children, highlight }: { children: ReactNode; highlight?: 'red' | 'green' }) {
    const bg = highlight === 'red' ? 'bg-red-50 dark:bg-red-950/20'
             : highlight === 'green' ? '!bg-green-50 dark:!bg-green-950/20'
             : 'hover:bg-gray-50 dark:hover:bg-gray-700/30';
    return <tr className={`transition-colors ${bg}`}>{children}</tr>;
}

// ── Td ────────────────────────────────────────────────────────────────────────
export function Td({ children, align = 'left', mono = false, muted = false }: {
    children: ReactNode; align?: 'left' | 'right' | 'center'; mono?: boolean; muted?: boolean;
}) {
    return (
        <td className={`px-4 py-2.5 text-${align} ${mono ? 'font-mono text-xs' : ''} ${muted ? 'text-gray-400' : 'text-gray-700 dark:text-gray-300'}`}>
            {children}
        </td>
    );
}

// ── Pagination ────────────────────────────────────────────────────────────────
export function Pagination({ links }: { links: any[] }) {
    if (!links || links.length <= 3) return null;
    return (
        <div className="mt-4 flex flex-wrap justify-center gap-1">
            {links.map((link: any, i: number) => (
                <button key={i} disabled={!link.url}
                    onClick={() => link.url && router.get(link.url)}
                    dangerouslySetInnerHTML={{ __html: link.label }}
                    className={`rounded-lg px-3 py-1.5 text-sm font-medium transition-colors
                        ${link.active
                            ? 'bg-[#7a1a2e] text-white'
                            : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 disabled:opacity-40 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300'
                        }`}
                />
            ))}
        </div>
    );
}

// ── SearchInput ───────────────────────────────────────────────────────────────
export function SearchInput({ value, onChange, placeholder }: {
    value: string; onChange: (v: string) => void; placeholder?: string;
}) {
    return (
        <div className="relative">
            <svg className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            <input type="text" value={value} onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder ?? 'Rechercher…'}
                className="w-full rounded-xl border border-gray-200 bg-white py-2.5 pl-9 pr-4 text-sm shadow-sm transition-all focus:ring-2 focus:ring-[#7a1a2e]/30 focus:border-[#7a1a2e] focus:outline-none dark:bg-gray-800 dark:border-gray-700 dark:text-white dark:placeholder-gray-500" />
        </div>
    );
}

// ── Modal ─────────────────────────────────────────────────────────────────────
export function Modal({ open, onClose, title, children, width = 'max-w-md' }: {
    open: boolean; onClose: () => void; title: string; children: ReactNode; width?: string;
}) {
    if (!open) return null;
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
            onClick={(e) => e.target === e.currentTarget && onClose()}>
            <div className={`w-full ${width} rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-900`}>
                <div className="mb-5 flex items-center justify-between">
                    <h2 className="text-lg font-bold text-gray-800 dark:text-white">{title}</h2>
                    <button onClick={onClose} className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800">
                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}

// ── FormField ─────────────────────────────────────────────────────────────────
export function FormField({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return (
        <div>
            <label className="mb-1.5 block text-sm font-semibold text-gray-700 dark:text-gray-300">{label}</label>
            {children}
            {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
        </div>
    );
}

export const inputCls = "w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm transition-all focus:ring-2 focus:ring-[#7a1a2e]/30 focus:border-[#7a1a2e] focus:outline-none dark:bg-gray-800 dark:border-gray-700 dark:text-white";

// ── ModalActions ──────────────────────────────────────────────────────────────
export function ModalActions({ onCancel, loading, label, color = 'bordeaux' }: {
    onCancel: () => void; loading: boolean; label: string; color?: 'bordeaux' | 'orange';
}) {
    const c = { bordeaux: 'bg-[#7a1a2e] hover:bg-[#6b1525]', orange: 'bg-orange-500 hover:bg-orange-600' };
    return (
        <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onCancel}
                className="rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                Annuler
            </button>
            <button type="submit" disabled={loading}
                className={`rounded-xl px-4 py-2.5 text-sm font-semibold text-white transition-all active:scale-95 disabled:opacity-50 ${c[color]}`}>
                {loading ? 'Enregistrement…' : label}
            </button>
        </div>
    );
}