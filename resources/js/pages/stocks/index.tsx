import { Head, router } from '@inertiajs/react';
import {
    Boxes,
    PackageSearch,
    TriangleAlert,
    Users,
    RefreshCw,
    FileSpreadsheet,
    CheckCircle,
} from 'lucide-react';
import { useState, useRef, useEffect } from 'react';
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

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    if (match) return decodeURIComponent(match[1]);
    return (
        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)
            ?.content ?? ''
    );
}

function buildExportUrl(
    base: string,
    search: string,
    fournisseur: string,
    maxQte: string,
    minQte: string,
): string {
    const params = new URLSearchParams();
    if (search) params.set('search', search);
    if (fournisseur) params.set('fournisseur', fournisseur);
    if (maxQte) params.set('max_qte', maxQte);
    if (minQte) params.set('min_qte', minQte);
    const qs = params.toString();
    return qs ? `${base}?${qs}` : base;
}

// ─────────────────────────────────────────────────────────────────────────────
// Hook polling sync status toutes les 5s
// ─────────────────────────────────────────────────────────────────────────────
function useSyncStatus(initialEnCours: boolean) {
    const [status, setStatus] = useState<'running' | 'done' | 'pending'>(
        initialEnCours ? 'running' : 'done',
    );

    useEffect(() => {
        if (status === 'done') return;

        const poll = async () => {
            try {
                const res = await fetch('/stocks/sync-status', {
                    headers: { Accept: 'application/json' },
                });
                const data = await res.json();
                setStatus(data.status);
                if (data.status === 'done') {
                    router.reload({
                        only: ['stocks', 'stats', 'sync_en_cours'],
                    });
                }
            } catch {
                // réseau instable, on réessaie
            }
        };

        const interval = setInterval(poll, 5000);
        return () => clearInterval(interval);
    }, [status]);

    return status;
}

// ─────────────────────────────────────────────────────────────────────────────
// PAGE
// ─────────────────────────────────────────────────────────────────────────────
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
    const [minQte, setMinQte] = useState(filters?.min_qte ?? '');
    const [sortBy, setSortBy] = useState(filters?.sort_by ?? 'Code');
    const [editingCode, setEditingCode] = useState<string | null>(null);
    const [editQte, setEditQte] = useState('');
    const [saving, setSaving] = useState(false);
    const [savedCode, setSavedCode] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const syncStatus = useSyncStatus(sync_en_cours);

    // On passe toujours les valeurs courantes EN DUR dans `o` pour éviter
    // le bug setState asynchrone (la valeur lue depuis le state est l'ancienne).
    const apply = (o: any = {}) => {
        const params = {
            search,
            fournisseur,
            max_qte: maxQte,
            min_qte: minQte,
            sort_by: sortBy,
            ...o,
        };
        // Nettoyer les paramètres vides pour une URL propre
        Object.keys(params).forEach((k) => {
            if (
                params[k] === '' ||
                params[k] === null ||
                params[k] === undefined
            ) {
                delete params[k];
            }
        });
        router.get(route('stocks.index'), params, {
            preserveState: true,
            replace: true,
        });
    };

    const startEdit = (s: any) => {
        setEditingCode(s.Code);
        setEditQte(String(n(s.QuantiteStock)));
        setTimeout(() => inputRef.current?.focus(), 50);
    };

    const saveEdit = async (Code: string) => {
        setSaving(true);
        try {
            const res = await fetch('/stocks/update', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    Code,
                    quantite: parseFloat(editQte) || 0,
                }),
            });
            const text = await res.text();
            let json: any = {};
            try {
                json = JSON.parse(text);
            } catch {
                console.error('Non-JSON:', text);
            }

            if (res.ok && json.success) {
                setSavedCode(Code);
                setTimeout(() => setSavedCode(null), 2000);
                setEditingCode(null);
                router.reload({ only: ['stocks', 'stats'] });
            } else {
                alert(json.message ?? `Erreur ${res.status}`);
            }
        } catch {
            alert('Erreur réseau — vérifiez la console (F12).');
        } finally {
            setSaving(false);
        }
    };

    const handleExportExcel = () => {
        window.location.href = buildExportUrl(
            '/stocks/export',
            search,
            fournisseur,
            maxQte,
            minQte,
        );
    };

    const rows = Array.isArray(stocks?.data) ? stocks.data : [];
    const links = Array.isArray(stocks?.links) ? stocks.links : [];

    // FIX : le filtre quantité est toujours visible (plus conditionnel au fournisseur)
    const hasFilters = !!(search || fournisseur || maxQte || minQte);

    return (
        <AppLayout>
            <Head title="Stocks" />
            <div className="min-h-screen bg-gray-50 p-6 dark:bg-gray-900">
                {/* ── TITRE + EXPORT ── */}
                <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">
                            Gestion des Stocks
                        </h1>
                        <p className="text-sm text-gray-400">
                            Cliquez sur une quantité pour la modifier
                            manuellement
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {hasFilters && (
                            <span className="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                Export filtré
                            </span>
                        )}
                        <button
                            onClick={handleExportExcel}
                            title="Exporter le stock en Excel (.xlsx)"
                            className="flex items-center gap-2 rounded-xl border border-green-200 bg-white px-4 py-2.5 text-sm font-semibold text-green-700 shadow-sm transition-all hover:border-green-300 hover:bg-green-50 active:scale-95 dark:border-green-800 dark:bg-gray-800 dark:text-green-400 dark:hover:bg-green-900/20"
                        >
                            <FileSpreadsheet size={15} />
                            Excel
                        </button>
                    </div>
                </div>

                {/* ── BANDEAU SYNC ── */}
                {syncStatus === 'running' && (
                    <div className="mb-4 flex items-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300">
                        <RefreshCw
                            size={14}
                            className="shrink-0 animate-spin"
                        />
                        <span>Synchronisation du stock en cours…</span>
                        <span className="ml-auto text-xs opacity-60">
                            Mise à jour automatique toutes les 5 s
                        </span>
                    </div>
                )}
                {syncStatus === 'done' && sync_en_cours && (
                    <div className="mb-4 flex items-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-950/30 dark:text-green-300">
                        <CheckCircle size={14} className="shrink-0" />
                        <span>Stock synchronisé avec succès.</span>
                    </div>
                )}

                {/* ── STATS ── */}
                <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {/* Carte 1 : Total articles */}
                    <StatCard
                        icon={<Boxes size={18} />}
                        label="Total articles"
                        value={fmt(stats?.total_articles)}
                        color="bordeaux"
                        tooltip="Nombre d'articles dans le catalogue"
                    />
                    {/* Carte 2 : Quantité totale en stock */}
                    <StatCard
                        icon={<PackageSearch size={18} />}
                        label="Qté totale stock"
                        value={fmt(stats?.total_qte_stock, 0)}
                        color="teal"
                        tooltip="Somme de toutes les quantités en stock"
                    />
                    {/* Carte 3 : Ruptures */}
                    <StatCard
                        icon={<TriangleAlert size={18} />}
                        label="Ruptures"
                        value={fmt(stats?.ruptures)}
                        color="red"
                        tooltip="Articles avec quantité ≤ 0"
                    />
                    {/* Carte 4 : Fournisseurs */}
                    <StatCard
                        icon={<Users size={18} />}
                        label="Fournisseurs"
                        value={fmt(stats?.fournisseurs_nb)}
                        color="gray"
                    />
                </div>

                {/* Valeur totale du stock en petit sous les stats */}
                <div className="mb-4 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-600 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                    Valeur totale du stock :{' '}
                    <span className="font-bold text-gray-800 dark:text-white">
                        {fmt(stats?.total_valeur, 2)} Ar
                    </span>
                </div>

                {/* ── FILTRES ── */}
                <div className="mb-4 space-y-2">
                    {/* Ligne 1 : Recherche + Fournisseur + Tri */}
                    <div className="flex flex-wrap gap-3">
                        <div className="min-w-50 flex-1">
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
                                const val = e.target.value;
                                setFournisseur(val);
                                apply({ fournisseur: val });
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
                        <select
                            value={sortBy}
                            onChange={(e) => {
                                setSortBy(e.target.value);
                                apply({ sort_by: e.target.value });
                            }}
                            className="rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-[#7a1a2e] focus:ring-2 focus:ring-[#7a1a2e]/30 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                        >
                            <option value="Code">Trier par Code</option>
                            <option value="fournisseur">
                                Trier par Fournisseur
                            </option>
                            <option value="Liblong">
                                Trier par Désignation
                            </option>
                            <option value="QuantiteStock">
                                Trier par Quantité
                            </option>
                            <option value="PrixTotal">Trier par Valeur</option>
                        </select>
                        {hasFilters && (
                            <button
                                onClick={() => {
                                    setSearch('');
                                    setFournisseur('');
                                    setMaxQte('');
                                    setMinQte('');
                                    setSortBy('Code');
                                    apply({
                                        search: '',
                                        fournisseur: '',
                                        max_qte: '',
                                        min_qte: '',
                                        sort_by: 'Code',
                                    });
                                }}
                                className="rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-xs font-semibold text-gray-500 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
                            >
                                ✕ Effacer filtres
                            </button>
                        )}
                    </div>

                    {/* Ligne 2 : Filtre quantité — toujours visible, combinable avec tout */}
                    <div
                        className={`flex items-center gap-3 rounded-xl border px-4 py-2.5 transition-colors ${
                            maxQte
                                ? 'border-[#7a1a2e]/40 bg-[#7a1a2e]/5 dark:border-[#7a1a2e]/50 dark:bg-[#7a1a2e]/10'
                                : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'
                        }`}
                    >
                        <span className="text-xs font-semibold tracking-wide text-gray-400 uppercase dark:text-gray-500">
                            Filtrer par quantité
                        </span>
                        <div className="h-4 w-px bg-gray-200 dark:bg-gray-600" />
                        <label className="text-sm text-gray-500 dark:text-gray-400">
                            Qté inférieure à
                        </label>
                        <input
                            type="number"
                            min="0"
                            value={maxQte}
                            onChange={(e) => {
                                setMaxQte(e.target.value);
                                apply({ max_qte: e.target.value });
                            }}
                            placeholder="ex: 10"
                            className={`w-28 rounded-lg border px-3 py-1.5 text-sm focus:ring-2 focus:outline-none dark:bg-gray-700 dark:text-white ${
                                maxQte
                                    ? 'border-[#7a1a2e]/50 focus:ring-[#7a1a2e]/30'
                                    : 'border-gray-200 focus:ring-[#7a1a2e]/20 dark:border-gray-600'
                            }`}
                        />
                        <div className="h-4 w-px bg-gray-200 dark:bg-gray-600" />
                        <label className="text-sm text-gray-500 dark:text-gray-400">
                            Qté supérieure à
                        </label>
                        <input
                            type="number"
                            min="0"
                            value={minQte}
                            onChange={(e) => {
                                setMinQte(e.target.value);
                                apply({ min_qte: e.target.value });
                            }}
                            placeholder="ex: 100"
                            className={`w-28 rounded-lg border px-3 py-1.5 text-sm focus:ring-2 focus:outline-none dark:bg-gray-700 dark:text-white ${
                                minQte
                                    ? 'border-[#7a1a2e]/50 focus:ring-[#7a1a2e]/30'
                                    : 'border-gray-200 focus:ring-[#7a1a2e]/20 dark:border-gray-600'
                            }`}
                        />
                        {/* Contexte actif : montre avec quoi on combine */}
                        {(maxQte || minQte) && (
                            <span className="ml-1 text-xs text-[#7a1a2e] dark:text-red-400">
                                {maxQte && minQte
                                    ? `→ qté entre ${minQte} et ${maxQte}`
                                    : maxQte
                                      ? `→ qté < ${maxQte}`
                                      : `→ qté > ${minQte}`}
                                {fournisseur
                                    ? ` · fournisseur « ${fournisseur} »`
                                    : ''}
                                {search ? ` · « ${search} »` : ''}
                            </span>
                        )}
                        {(maxQte || minQte) && (
                            <button
                                onClick={() => {
                                    setMaxQte('');
                                    setMinQte('');
                                    apply({ max_qte: '', min_qte: '' });
                                }}
                                className="ml-auto text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                title="Effacer les filtres quantité"
                            >
                                ✕
                            </button>
                        )}
                    </div>
                </div>

                {/* ── TABLE ── */}
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
                                                className={`cursor-pointer rounded-lg px-2 py-1 font-bold transition-colors hover:bg-[#7a1a2e]/10 hover:text-[#7a1a2e] ${
                                                    isRupture
                                                        ? 'text-red-600 dark:text-red-400'
                                                        : 'text-gray-800 dark:text-gray-200'
                                                }`}
                                            >
                                                {fmt(s.QuantiteStock, 3)}
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
    tooltip,
}: {
    icon: React.ReactNode;
    label: string;
    value: string;
    color: string;
    tooltip?: string;
}) {
    const bg: any = {
        bordeaux: 'bg-[#7a1a2e]',
        teal: 'bg-teal-700',
        red: 'bg-red-700',
        gray: 'bg-gray-700',
    };
    return (
        <div
            className={`rounded-2xl p-4 text-white shadow-sm ${bg[color]}`}
            title={tooltip}
        >
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
