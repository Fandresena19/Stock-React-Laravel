import { Head, router } from '@inertiajs/react';
import {
    Boxes,
    PackageSearch,
    TriangleAlert,
    Users,
    RefreshCw,
} from 'lucide-react';
import { useState, useRef } from 'react';
import { route } from 'ziggy-js';
import {
    DataTable,
    Tr,
    Td,
    Pagination,
    SearchInput,
} from '@/components/ui/page-components';
import AppLayout from '@/layouts/app-layout';

const n = (v: any) => {
    const x = parseFloat(v);
    return isNaN(x) ? 0 : x;
};
const fmt = (v: any, d = 0) =>
    n(v).toLocaleString('fr-FR', { minimumFractionDigits: d });

// Lit le CSRF token depuis le cookie XSRF-TOKEN (standard Laravel/Inertia)
function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    if (match) return decodeURIComponent(match[1]);
    // Fallback : meta tag
    return (
        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)
            ?.content ?? ''
    );
}

export default function Index({
    stocks,
    fournisseursList,
    stats,
    filters,
    sync_en_cours,
}: any) {
    const [search, setSearch] = useState(filters?.search ?? '');
    const [fournisseur, setFournisseur] = useState(filters?.fournisseur ?? '');
    const [maxQte, setMaxQte] = useState(filters?.max_qte ?? '');
    const [editingCode, setEditingCode] = useState<string | null>(null);
    const [editQte, setEditQte] = useState('');
    const [saving, setSaving] = useState(false);
    const [savedCode, setSavedCode] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const apply = (o: any = {}) =>
        router.get(
            route('stocks.index'),
            { search, fournisseur, max_qte: maxQte, ...o },
            { preserveState: true, replace: true },
        );

    const startEdit = (s: any) => {
        setEditingCode(s.Code);
        setEditQte(String(n(s.QuantiteStock)));
        setTimeout(() => inputRef.current?.focus(), 50);
    };

    const saveEdit = async (Code: string) => {
        setSaving(true);
        try {
            // Lire le token depuis le cookie XSRF-TOKEN (méthode Inertia/Laravel)
            const csrf = getCsrfToken();
            const body = JSON.stringify({
                Code,
                quantite: parseFloat(editQte) || 0,
            });

            const res = await fetch('/stocks/update', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body,
            });

            const text = await res.text();
            let json: any = {};
            try {
                json = JSON.parse(text);
            } catch {
                console.error('Réponse non-JSON:', text);
            }

            if (res.ok && json.success) {
                setSavedCode(Code);
                setTimeout(() => setSavedCode(null), 2000);
                setEditingCode(null);
                router.reload({ only: ['stocks', 'stats'] });
            } else {
                console.error('Erreur update stock:', res.status, text);
                alert(json.message ?? `Erreur ${res.status}`);
            }
        } catch (e) {
            console.error('Erreur réseau saveEdit:', e);
            alert('Erreur réseau — vérifiez la console (F12).');
        } finally {
            setSaving(false);
        }
    };

    const rows = Array.isArray(stocks?.data) ? stocks.data : [];
    const links = Array.isArray(stocks?.links) ? stocks.links : [];

    return (
        <AppLayout>
            <Head title="Stocks" />
            <div className="min-h-screen bg-gray-50 p-6 dark:bg-gray-900">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold text-gray-800 dark:text-white">
                        Gestion des Stocks
                    </h1>
                    <p className="text-sm text-gray-400">
                        Cliquez sur une quantité pour la modifier manuellement
                    </p>
                </div>

                {/* Bandeau sync */}
                {sync_en_cours && (
                    <div className="mb-4 flex items-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300">
                        <RefreshCw
                            size={14}
                            className="flex-shrink-0 animate-spin"
                        />
                        <span>
                            Synchronisation du stock en cours en arrière-plan…
                        </span>
                        <button
                            onClick={() =>
                                router.reload({
                                    only: ['stocks', 'stats', 'sync_en_cours'],
                                })
                            }
                            className="ml-auto rounded-lg border border-blue-300 px-2.5 py-1 text-xs font-semibold hover:bg-blue-100"
                        >
                            Rafraîchir
                        </button>
                    </div>
                )}

                {/* Stats */}
                <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <StatCard
                        icon={<Boxes size={18} />}
                        label="Total articles"
                        value={fmt(stats?.total_articles)}
                        color="bordeaux"
                    />
                    <StatCard
                        icon={<PackageSearch size={18} />}
                        label="Valeur totale"
                        value={`${fmt(stats?.total_valeur, 0)} Ar`}
                        color="teal"
                    />
                    <StatCard
                        icon={<TriangleAlert size={18} />}
                        label="Ruptures"
                        value={fmt(stats?.ruptures)}
                        color="red"
                    />
                    <StatCard
                        icon={<Users size={18} />}
                        label="Fournisseurs"
                        value={fmt(stats?.fournisseurs_nb)}
                        color="gray"
                    />
                </div>

                {/* Filtres */}
                <div className="mb-4 flex flex-wrap gap-3">
                    <div className="min-w-[200px] flex-1">
                        <SearchInput
                            value={search}
                            onChange={(v) => {
                                setSearch(v);
                                apply({ search: v });
                            }}
                            placeholder="Rechercher code, article, fournisseur…"
                        />
                    </div>
                    <select
                        value={fournisseur}
                        onChange={(e) => {
                            setFournisseur(e.target.value);
                            apply({ fournisseur: e.target.value });
                        }}
                        className="rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-[#7a1a2e] focus:ring-2 focus:ring-[#7a1a2e]/30 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                    >
                        <option value="">Tous les fournisseurs</option>
                        {(fournisseursList ?? []).map((f: string) => (
                            <option key={f} value={f}>
                                {f}
                            </option>
                        ))}
                    </select>
                    <div className="flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <span className="text-sm text-gray-400">Qté &lt;</span>
                        <input
                            type="number"
                            min="0"
                            value={maxQte}
                            onChange={(e) => {
                                setMaxQte(e.target.value);
                                apply({ max_qte: e.target.value });
                            }}
                            placeholder="Valeur…"
                            className="w-24 bg-transparent py-2.5 text-sm focus:outline-none dark:text-white"
                        />
                    </div>
                </div>

                <DataTable
                    headers={[
                        { label: 'Code' },
                        { label: 'Article' },
                        { label: 'Fournisseur' },
                        { label: 'Qté Stock', align: 'right' },
                        { label: 'Prix U.', align: 'right' },
                        { label: 'Prix Total', align: 'right' },
                    ]}
                >
                    {rows.length ? (
                        rows.map((s: any) => {
                            const isEditing = editingCode === s.Code;
                            const isRupture = n(s.QuantiteStock) <= 0;
                            const wasSaved = savedCode === s.Code;
                            return (
                                <Tr
                                    key={s.Code}
                                    highlight={
                                        wasSaved
                                            ? 'green'
                                            : isRupture
                                              ? 'red'
                                              : undefined
                                    }
                                >
                                    <Td mono muted>
                                        {s.Code}
                                    </Td>
                                    <Td>{s.Liblong}</Td>
                                    <Td muted>{s.fournisseur ?? '—'}</Td>
                                    <td className="px-4 py-2.5 text-right">
                                        {isEditing ? (
                                            <div className="flex items-center justify-end gap-1.5">
                                                <input
                                                    ref={inputRef}
                                                    type="number"
                                                    min="0"
                                                    value={editQte}
                                                    onChange={(e) =>
                                                        setEditQte(
                                                            e.target.value,
                                                        )
                                                    }
                                                    onKeyDown={(e) => {
                                                        if (e.key === 'Enter')
                                                            saveEdit(s.Code);
                                                        if (e.key === 'Escape')
                                                            setEditingCode(
                                                                null,
                                                            );
                                                    }}
                                                    className="w-24 rounded-lg border border-[#7a1a2e] px-2 py-1 text-right text-sm focus:ring-2 focus:ring-[#7a1a2e]/30 focus:outline-none"
                                                />
                                                <button
                                                    disabled={saving}
                                                    onClick={() =>
                                                        saveEdit(s.Code)
                                                    }
                                                    className="rounded-lg bg-[#7a1a2e] px-2 py-1 text-xs font-bold text-white hover:bg-[#6b1525] disabled:opacity-50"
                                                >
                                                    ✓
                                                </button>
                                                <button
                                                    onClick={() =>
                                                        setEditingCode(null)
                                                    }
                                                    className="rounded-lg bg-gray-200 px-2 py-1 text-xs font-bold hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600"
                                                >
                                                    ✕
                                                </button>
                                            </div>
                                        ) : (
                                            <span
                                                onClick={() => startEdit(s)}
                                                title="Cliquer pour modifier"
                                                className={`cursor-pointer rounded-lg px-2 py-1 font-bold transition-colors hover:bg-[#7a1a2e]/10 hover:text-[#7a1a2e] ${isRupture ? 'text-red-600 dark:text-red-400' : 'text-gray-800 dark:text-gray-200'}`}
                                            >
                                                {fmt(s.QuantiteStock)}
                                            </span>
                                        )}
                                    </td>
                                    <Td align="right" muted>
                                        {fmt(s.PrixU, 2)}
                                    </Td>
                                    <td className="px-4 py-2.5 text-right font-bold text-gray-800 dark:text-gray-200">
                                        {fmt(s.PrixTotal, 2)}
                                    </td>
                                </Tr>
                            );
                        })
                    ) : (
                        <tr>
                            <td
                                colSpan={6}
                                className="py-10 text-center text-gray-400"
                            >
                                Aucun article trouvé
                            </td>
                        </tr>
                    )}
                </DataTable>

                <Pagination links={links} />
            </div>
        </AppLayout>
    );
}

function StatCard({
    icon,
    label,
    value,
    color,
}: {
    icon: React.ReactNode;
    label: string;
    value: string;
    color: string;
}) {
    const bg: any = {
        bordeaux: 'bg-[#7a1a2e]',
        teal: 'bg-teal-700',
        red: 'bg-red-700',
        gray: 'bg-gray-700',
    };
    return (
        <div className={`rounded-2xl p-4 text-white shadow-sm ${bg[color]}`}>
            <div className="mb-2 flex items-center justify-between">
                <p className="text-xs font-semibold tracking-wide uppercase opacity-75">
                    {label}
                </p>
                <div className="rounded-lg bg-white/15 p-1.5">{icon}</div>
            </div>
            <p className="text-2xl font-bold tracking-tight">{value}</p>
        </div>
    );
}
