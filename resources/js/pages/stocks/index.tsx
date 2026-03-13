import { Head, router } from '@inertiajs/react';
import {
    PackageSearch,
    TriangleAlert,
    Boxes,
    Users,
    RefreshCw,
} from 'lucide-react';
import { useState, useRef } from 'react';
import { route } from 'ziggy-js';
import AppLayout from '@/layouts/app-layout';

const n = (val: any): number => {
    const v = parseFloat(val);
    return isNaN(v) ? 0 : v;
};

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
    const [editQte, setEditQte] = useState<string>('');
    const [saving, setSaving] = useState(false);
    const [savedCode, setSavedCode] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const applyFilters = (overrides: Record<string, any> = {}) => {
        router.get(
            route('stocks.index'),
            { search, fournisseur, max_qte: maxQte, ...overrides },
            { preserveState: true, replace: true },
        );
    };

    const handleSearch = (v: string) => {
        setSearch(v);
        applyFilters({ search: v });
    };
    const handleFournisseur = (v: string) => {
        setFournisseur(v);
        applyFilters({ fournisseur: v });
    };
    const handleMaxQte = (v: string) => {
        setMaxQte(v);
        applyFilters({ max_qte: v });
    };

    const startEdit = (stock: any) => {
        setEditingCode(stock.Code);
        // CORRIGÉ : QuantiteStock avec majuscule exacte
        setEditQte(String(n(stock.QuantiteStock)));
        setTimeout(() => inputRef.current?.focus(), 50);
    };

    const cancelEdit = () => {
        setEditingCode(null);
        setEditQte('');
    };

    const saveEdit = async (Code: string) => {
        setSaving(true);
        try {
            const res = await fetch(route('stocks.update'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        (
                            document.querySelector(
                                'meta[name="csrf-token"]',
                            ) as HTMLMetaElement
                        )?.content ?? '',
                },
                body: JSON.stringify({ Code, quantite: editQte }),
            });
            if (res.ok) {
                setSavedCode(Code);
                setTimeout(() => setSavedCode(null), 1500);
                setEditingCode(null);
                router.reload({ only: ['stocks', 'stats'] });
            }
        } finally {
            setSaving(false);
        }
    };

    const rows = Array.isArray(stocks?.data) ? stocks.data : [];
    const links = Array.isArray(stocks?.links) ? stocks.links : [];

    return (
        <AppLayout>
            <Head title="Gestion Stocks" />

            {/* ── BANDEAU SYNC EN COURS ─────────────────────────────────────── */}
            {sync_en_cours && (
                <div className="mx-4 mt-4 flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm text-blue-700">
                    <RefreshCw size={14} className="animate-spin" />
                    <span>
                        Synchronisation du stock en cours en arrière-plan…
                    </span>
                    <button
                        onClick={() =>
                            router.reload({
                                only: ['stocks', 'stats', 'sync_en_cours'],
                            })
                        }
                        className="ml-auto text-xs underline hover:no-underline"
                    >
                        Rafraîchir
                    </button>
                </div>
            )}

            {/* ── STATS ─────────────────────────────────────────────────────── */}
            <div className="grid grid-cols-2 gap-3 p-4 md:grid-cols-4">
                <StatCard
                    icon={<Boxes size={20} />}
                    label="Total articles"
                    value={stats?.total_articles ?? 0}
                    color="blue"
                />
                <StatCard
                    icon={<PackageSearch size={20} />}
                    label="Valeur totale"
                    value={`${n(stats?.total_valeur).toLocaleString('fr-FR', { minimumFractionDigits: 2 })} Ar`}
                    color="green"
                />
                <StatCard
                    icon={<TriangleAlert size={20} />}
                    label="Ruptures"
                    value={stats?.ruptures ?? 0}
                    color="red"
                />
                <StatCard
                    icon={<Users size={20} />}
                    label="Fournisseurs"
                    value={stats?.fournisseurs_nb ?? 0}
                    color="purple"
                />
            </div>

            {/* ── FILTRES ───────────────────────────────────────────────────── */}
            <div className="flex flex-wrap items-center gap-3 px-4 pb-3">
                <input
                    type="text"
                    value={search}
                    onChange={(e) => handleSearch(e.target.value)}
                    placeholder="Rechercher Code, libellé, fournisseur…"
                    className="min-w-[180px] flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                />
                <select
                    value={fournisseur}
                    onChange={(e) => handleFournisseur(e.target.value)}
                    className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                >
                    <option value="">Tous les fournisseurs</option>
                    {(fournisseursList ?? []).map((f: string) => (
                        <option key={f} value={f}>
                            {f}
                        </option>
                    ))}
                </select>
                <div className="flex items-center gap-1.5">
                    <span className="text-sm whitespace-nowrap text-gray-500">
                        Qté &lt;
                    </span>
                    <input
                        type="number"
                        min="0"
                        value={maxQte}
                        onChange={(e) => handleMaxQte(e.target.value)}
                        placeholder="Valeur…"
                        className="w-28 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-orange-400 focus:outline-none"
                    />
                </div>
            </div>

            {/* ── TABLEAU ───────────────────────────────────────────────────── */}
            <div className="mx-4 mb-4 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <div className="max-h-[430px] overflow-y-auto">
                    <table className="w-full text-sm">
                        <thead className="sticky top-0 bg-gray-800 text-white">
                            <tr>
                                <th className="px-4 py-2 text-left">Code</th>
                                <th className="px-4 py-2 text-left">Article</th>
                                <th className="px-4 py-2 text-left">
                                    Fournisseur
                                </th>
                                <th className="px-4 py-2 text-right">
                                    Qté Stock
                                </th>
                                <th className="px-4 py-2 text-right">Prix U</th>
                                <th className="px-4 py-2 text-right">
                                    Prix Total
                                </th>
                            </tr>
                        </thead>
                        <tbody className="text-gray-700">
                            {rows.length ? (
                                rows.map((stock: any) => {
                                    const isEditing =
                                        editingCode === stock.Code;
                                    const wasSaved = savedCode === stock.Code;
                                    // CORRIGÉ : QuantiteStock avec majuscule exacte
                                    const isRupture =
                                        n(stock.QuantiteStock) <= 0;

                                    return (
                                        <tr
                                            key={stock.Code}
                                            className={`border-b transition-colors last:border-none ${isRupture ? 'bg-red-50' : ''} ${wasSaved ? '!bg-green-50' : ''}`}
                                        >
                                            <td className="px-4 py-2 font-mono text-xs text-gray-500">
                                                {stock.Code}
                                            </td>
                                            <td className="px-4 py-2">
                                                {stock.Liblong}
                                            </td>
                                            <td className="px-4 py-2 text-gray-500">
                                                {stock.fournisseur ?? '—'}
                                            </td>

                                            {/* Quantité — éditable inline au clic */}
                                            <td className="px-4 py-2 text-right">
                                                {isEditing ? (
                                                    <div className="flex items-center justify-end gap-1.5">
                                                        <input
                                                            ref={inputRef}
                                                            type="number"
                                                            min="0"
                                                            value={editQte}
                                                            onChange={(e) =>
                                                                setEditQte(
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            onKeyDown={(e) => {
                                                                if (
                                                                    e.key ===
                                                                    'Enter'
                                                                )
                                                                    saveEdit(
                                                                        stock.Code,
                                                                    );
                                                                if (
                                                                    e.key ===
                                                                    'Escape'
                                                                )
                                                                    cancelEdit();
                                                            }}
                                                            className="w-24 rounded border border-blue-400 px-2 py-1 text-right text-sm focus:ring-2 focus:ring-blue-400 focus:outline-none"
                                                        />
                                                        <button
                                                            disabled={saving}
                                                            onClick={() =>
                                                                saveEdit(
                                                                    stock.Code,
                                                                )
                                                            }
                                                            className="rounded bg-blue-600 px-2 py-1 text-xs text-white hover:bg-blue-700 disabled:opacity-50"
                                                        >
                                                            ✓
                                                        </button>
                                                        <button
                                                            onClick={cancelEdit}
                                                            className="rounded bg-gray-200 px-2 py-1 text-xs hover:bg-gray-300"
                                                        >
                                                            ✕
                                                        </button>
                                                    </div>
                                                ) : (
                                                    <span
                                                        onClick={() =>
                                                            startEdit(stock)
                                                        }
                                                        title="Cliquer pour modifier"
                                                        className={`cursor-pointer rounded px-2 py-0.5 font-medium transition-colors hover:bg-blue-50 hover:text-blue-700 ${isRupture ? 'text-red-600' : 'text-gray-800'}`}
                                                    >
                                                        {/* CORRIGÉ : QuantiteStock */}
                                                        {n(
                                                            stock.QuantiteStock,
                                                        ).toLocaleString(
                                                            'fr-FR',
                                                        )}
                                                    </span>
                                                )}
                                            </td>

                                            {/* CORRIGÉ : PrixU et PrixTotal */}
                                            <td className="px-4 py-2 text-right text-gray-600">
                                                {n(stock.PrixU).toLocaleString(
                                                    'fr-FR',
                                                    {
                                                        minimumFractionDigits: 2,
                                                    },
                                                )}
                                            </td>
                                            <td className="px-4 py-2 text-right font-semibold text-gray-800">
                                                {n(
                                                    stock.PrixTotal,
                                                ).toLocaleString('fr-FR', {
                                                    minimumFractionDigits: 2,
                                                })}
                                            </td>
                                        </tr>
                                    );
                                })
                            ) : (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-6 text-center text-gray-400"
                                    >
                                        Aucun article trouvé
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* ── PAGINATION ────────────────────────────────────────────────── */}
            {links.length > 3 && (
                <div className="flex flex-wrap justify-center gap-1 pb-4">
                    {links.map((link: any, i: number) => (
                        <button
                            key={`page-${i}`}
                            disabled={!link.url}
                            onClick={() => link.url && router.get(link.url)}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                            className={`rounded px-3 py-1 text-sm ${link.active ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 disabled:opacity-40'}`}
                        />
                    ))}
                </div>
            )}
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
    value: string | number;
    color: 'blue' | 'green' | 'red' | 'purple';
}) {
    const colors = {
        blue: 'bg-blue-50 text-blue-600',
        green: 'bg-green-50 text-green-600',
        red: 'bg-red-50 text-red-600',
        purple: 'bg-purple-50 text-purple-600',
    };
    return (
        <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div className={`mb-2 inline-flex rounded-lg p-2 ${colors[color]}`}>
                {icon}
            </div>
            <p className="text-xs text-gray-500">{label}</p>
            <p className="text-lg font-bold text-gray-800">{value}</p>
        </div>
    );
}
