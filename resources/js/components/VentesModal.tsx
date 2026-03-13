import {
    X,
    Receipt,
    TrendingUp,
    PackageOpen,
    CalendarDays,
    Trophy,
    Search,
    ChevronLeft,
    ChevronRight,
    AlertCircle,
    Loader2,
} from 'lucide-react';
import { useState, useEffect, useCallback } from 'react';

// ─── Types ───────────────────────────────────────────────────────────────────

interface Vente {
    idquand: string;
    idcint: string;
    idlib: string;
    E1: number;
    idmttnet: number;
}

interface Stats {
    total_lignes: number;
    total_montant: number;
    top_produit: string;
    date_affichee: string;
}

interface PaginatedVentes {
    data: Vente[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface VentesModalProps {
    open: boolean;
    onClose: () => void;
}

// ─── Composant ───────────────────────────────────────────────────────────────

export default function VentesModal({ open, onClose }: VentesModalProps) {
    const [date, setDate] = useState<string>(getYesterday());
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [ventes, setVentes] = useState<PaginatedVentes | null>(null);
    const [stats, setStats] = useState<Stats | null>(null);

    const fmt = (n: number) =>
        new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2 }).format(
            n ?? 0,
        );

    // ── Fetch ────────────────────────────────────────────────────────────────

    const fetchVentes = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const params = new URLSearchParams({
                date,
                search,
                page: String(page),
            });
            const res = await fetch(`/ventes?${params}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const json = await res.json();

            if (!json.success) {
                setError(json.message ?? 'Erreur inconnue.');
                setVentes(null);
                setStats(null);
            } else {
                setVentes(json.ventes);
                setStats(json.stats);
            }
        } catch {
            setError('Impossible de charger les ventes.');
        } finally {
            setLoading(false);
        }
    }, [date, search, page]);

    useEffect(() => {
        if (open) fetchVentes();
    }, [open, fetchVentes]);
    useEffect(() => {
        setPage(1);
    }, [date, search]);
    useEffect(() => {
        const handleKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        if (open) window.addEventListener('keydown', handleKey);
        return () => window.removeEventListener('keydown', handleKey);
    }, [open, onClose]);

    if (!open) return null;

    const rows = ventes?.data ?? [];

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            onClick={(e) => e.target === e.currentTarget && onClose()}
        >
            <div className="flex h-[90vh] w-full max-w-5xl flex-col rounded-2xl bg-white shadow-2xl">
                {/* ── EN-TÊTE ──────────────────────────────────────────────── */}
                <div className="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <div className="flex items-center gap-2">
                        <div className="rounded-lg bg-emerald-50 p-2 text-emerald-600">
                            <Receipt size={20} />
                        </div>
                        <div>
                            <h2 className="text-lg font-semibold text-gray-800">
                                Ventes du jour
                            </h2>
                            <p className="text-xs text-gray-400">
                                Table dynamique servmcljournal
                            </p>
                        </div>
                    </div>
                    <button
                        onClick={onClose}
                        className="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                    >
                        <X size={20} />
                    </button>
                </div>

                {/* ── FILTRES ──────────────────────────────────────────────── */}
                <div className="flex flex-wrap items-center gap-3 border-b border-gray-100 px-6 py-3">
                    <div className="flex items-center gap-2">
                        <CalendarDays size={16} className="text-gray-400" />
                        <input
                            type="date"
                            value={date}
                            onChange={(e) => setDate(e.target.value)}
                            className="rounded-lg border border-gray-200 px-3 py-1.5 text-sm focus:ring-2 focus:ring-emerald-400 focus:outline-none"
                        />
                    </div>
                    <div className="relative flex-1">
                        <Search
                            size={14}
                            className="absolute top-1/2 left-3 -translate-y-1/2 text-gray-400"
                        />
                        <input
                            type="text"
                            placeholder="Rechercher article, code…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-full rounded-lg border border-gray-200 py-1.5 pr-3 pl-8 text-sm focus:ring-2 focus:ring-emerald-400 focus:outline-none"
                        />
                    </div>
                </div>

                {/* ── STATS ────────────────────────────────────────────────── */}
                {stats && (
                    <div className="grid grid-cols-2 gap-3 px-6 py-3 sm:grid-cols-4">
                        <MiniStat
                            icon={<PackageOpen size={14} />}
                            label="Lignes"
                            value={stats.total_lignes.toLocaleString('fr-FR')}
                            color="blue"
                        />
                        <MiniStat
                            icon={<TrendingUp size={14} />}
                            label="Montant"
                            value={fmt(stats.total_montant) + ' Ar'}
                            color="green"
                        />
                        <MiniStat
                            icon={<Trophy size={14} />}
                            label="Top article"
                            value={stats.top_produit}
                            color="orange"
                        />
                        <MiniStat
                            icon={<CalendarDays size={14} />}
                            label="Date"
                            value={stats.date_affichee}
                            color="purple"
                        />
                    </div>
                )}

                {/* ── TABLEAU ──────────────────────────────────────────────── */}
                <div className="min-h-0 flex-1 overflow-auto px-6">
                    {loading ? (
                        <div className="flex h-full items-center justify-center gap-2 text-gray-400">
                            <Loader2 size={20} className="animate-spin" />
                            <span className="text-sm">Chargement…</span>
                        </div>
                    ) : error ? (
                        <div className="flex h-full items-center justify-center">
                            <div className="flex items-center gap-2 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-700">
                                <AlertCircle size={16} />
                                {error}
                            </div>
                        </div>
                    ) : rows.length === 0 ? (
                        <div className="flex h-full items-center justify-center text-sm text-gray-400">
                            Aucune vente trouvée pour cette date.
                        </div>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="sticky top-0 bg-white text-left text-xs text-gray-400 uppercase">
                                    <th className="py-2 pr-4 font-medium">
                                        Heure
                                    </th>
                                    <th className="py-2 pr-4 font-medium">
                                        Code
                                    </th>
                                    <th className="py-2 pr-4 font-medium">
                                        Désignation
                                    </th>
                                    <th className="py-2 pr-4 text-right font-medium">
                                        Qté
                                    </th>
                                    <th className="py-2 text-right font-medium">
                                        Montant
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {rows.map((v, rowIdx) => (
                                    <tr
                                        key={`vente-${rowIdx}`}
                                        className="hover:bg-gray-50"
                                    >
                                        <td className="py-2 pr-4 text-gray-400 tabular-nums">
                                            {v.idquand ?? '—'}
                                        </td>
                                        <td className="py-2 pr-4 font-mono text-xs text-gray-600">
                                            {v.idcint}
                                        </td>
                                        <td className="py-2 pr-4 text-gray-800">
                                            {v.idlib}
                                        </td>
                                        <td className="py-2 pr-4 text-right tabular-nums">
                                            {v.E1}
                                        </td>
                                        <td className="py-2 text-right text-gray-700 tabular-nums">
                                            {fmt(v.idmttnet)} Ar
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                {/* ── PAGINATION ───────────────────────────────────────────── */}
                {ventes && ventes.last_page > 1 && (
                    <div className="flex items-center justify-center gap-2 border-t border-gray-100 px-6 py-3">
                        <button
                            disabled={page <= 1}
                            onClick={() => setPage((p) => p - 1)}
                            className="rounded-lg border px-2 py-1 text-sm hover:bg-gray-50 disabled:opacity-40"
                        >
                            <ChevronLeft size={16} />
                        </button>
                        <span className="text-sm text-gray-500">
                            Page {ventes.current_page} / {ventes.last_page}
                        </span>
                        <button
                            disabled={page >= ventes.last_page}
                            onClick={() => setPage((p) => p + 1)}
                            className="rounded-lg border px-2 py-1 text-sm hover:bg-gray-50 disabled:opacity-40"
                        >
                            <ChevronRight size={16} />
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

// ─── MiniStat ────────────────────────────────────────────────────────────────

function MiniStat({
    icon,
    label,
    value,
    color,
}: {
    icon: React.ReactNode;
    label: string;
    value: string;
    color: 'blue' | 'green' | 'orange' | 'purple';
}) {
    const colors = {
        blue: 'bg-blue-50 text-blue-600',
        green: 'bg-emerald-50 text-emerald-600',
        orange: 'bg-orange-50 text-orange-600',
        purple: 'bg-purple-50 text-purple-600',
    };
    return (
        <div className="rounded-xl border border-gray-100 bg-gray-50 p-3">
            <div
                className={`mb-1.5 inline-flex rounded-md p-1.5 ${colors[color]}`}
            >
                {icon}
            </div>
            <p className="text-[10px] text-gray-400 uppercase">{label}</p>
            <p className="truncate text-sm font-semibold text-gray-700 tabular-nums">
                {value}
            </p>
        </div>
    );
}

// ─── Helper ──────────────────────────────────────────────────────────────────

function getYesterday(): string {
    const d = new Date();
    d.setDate(d.getDate() - 1);
    return d.toISOString().split('T')[0];
}
