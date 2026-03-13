import { Head, router, Link as InertiaLink, useForm } from '@inertiajs/react';
import {
    Upload,
    FolderSync,
    Trash2,
    Search,
    PackageOpen,
    CalendarDays,
    FileSpreadsheet,
    TrendingUp,
    ChevronDown,
    X,
    AlertCircle,
    CheckCircle2,
} from 'lucide-react';
import { useState, useRef } from 'react';
import { route } from 'ziggy-js';
import AppLayout from '@/layouts/app-layout';

// ─── Types ───────────────────────────────────────────────────────────────────

interface Achat {
    id: number;
    Code: string;
    Liblong: string;
    PrixU: number;
    QuantiteAchat: number;
    montant: number;
    date: string;
}

interface ImportedFile {
    filename: string;
    total_rows: number;
    imported_at: string;
}

interface Stats {
    total_lignes: number;
    total_montant: number;
    derniere_date: string | null;
    fichiers: number;
}

interface Props {
    achats: { data: Achat[]; links: any[] };
    importHistory: ImportedFile[];
    stats: Stats;
    filters: { search: string; date: string };
    watchFolder: string;
    flash?: { success?: string; error?: string; info?: string };
}

// ─── Composant principal ─────────────────────────────────────────────────────

export default function Index({
    achats,
    importHistory,
    stats,
    filters,
    watchFolder,
}: Props) {
    const [search, setSearch] = useState(filters?.search ?? '');
    const [date, setDate] = useState(filters?.date ?? '');
    const [showImportModal, setShowImportModal] = useState(false);
    const [showHistory, setShowHistory] = useState(false);
    const [dragOver, setDragOver] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    // Formulaire import manuel
    const { data, setData, post, processing, errors, reset } = useForm<{
        files: File[];
        date: string;
    }>({
        files: [],
        date: (() => {
            const d = new Date();
            d.setDate(d.getDate() - 1);
            return d.toISOString().split('T')[0];
        })(),
    });

    // ── Recherche ────────────────────────────────────────────────────────────
    const handleSearch = (value: string) => {
        setSearch(value);
        router.get(
            route('achats.index'),
            { search: value, date },
            { preserveState: true, replace: true },
        );
    };

    const handleDateFilter = (value: string) => {
        setDate(value);
        router.get(
            route('achats.index'),
            { search, date: value },
            { preserveState: true, replace: true },
        );
    };

    const clearFilters = () => {
        setSearch('');
        setDate('');
        router.get(
            route('achats.index'),
            {},
            { preserveState: true, replace: true },
        );
    };

    // ── Import manuel ────────────────────────────────────────────────────────
    const handleFiles = (files: FileList | null) => {
        if (!files) return;
        setData('files', Array.from(files));
    };

    const submitImport = (e: any) => {
        e.preventDefault();
        const fd = new FormData();
        data.files.forEach((f) => fd.append('files[]', f));
        fd.append('date', data.date);
        router.post(route('achats.import'), fd as any, {
            onSuccess: () => {
                reset();
                setShowImportModal(false);
            },
        });
    };

    // ── Import automatique ───────────────────────────────────────────────────
    const runAutoImport = () => {
        if (
            confirm(
                `Lancer l'import depuis :\n${watchFolder}\n\nDate des achats : hier (${getYesterday()})`,
            )
        ) {
            router.post(
                route('achats.import-auto'),
                {},
                {
                    preserveScroll: true,
                },
            );
        }
    };

    const getYesterday = () => {
        const d = new Date();
        d.setDate(d.getDate() - 1);
        return d.toLocaleDateString('fr-FR');
    };

    // ── Suppression ──────────────────────────────────────────────────────────
    const handleDelete = (id: number) => {
        if (confirm("Supprimer cette ligne d'achat ?")) {
            router.delete(route('achats.destroy', id), {
                preserveScroll: true,
            });
        }
    };

    const rows = Array.isArray(achats?.data) ? achats.data : [];
    const links = Array.isArray(achats?.links) ? achats.links : [];
    const hasFilters = search !== '' || date !== '';

    // Format nombre
    const fmt = (n: number) =>
        new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2 }).format(n);

    return (
        <AppLayout>
            <Head title="Achats" />

            <div className="space-y-4 p-4">
                {/* ── STATS ─────────────────────────────────────────────── */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <StatCard
                        icon={<PackageOpen size={18} />}
                        label="Lignes total"
                        value={stats.total_lignes.toLocaleString('fr-FR')}
                        color="blue"
                    />
                    <StatCard
                        icon={<TrendingUp size={18} />}
                        label="Montant total"
                        value={fmt(stats.total_montant) + ' Ar'}
                        color="green"
                    />
                    <StatCard
                        icon={<CalendarDays size={18} />}
                        label="Dernier import"
                        value={stats.derniere_date ?? '—'}
                        color="orange"
                    />
                    <StatCard
                        icon={<FileSpreadsheet size={18} />}
                        label="Fichiers importés"
                        value={stats.fichiers.toString()}
                        color="purple"
                    />
                </div>

                {/* ── BARRE D'ACTIONS ───────────────────────────────────── */}
                <div className="flex flex-wrap items-center gap-2">
                    {/* Recherche */}
                    <div className="relative min-w-[200px] flex-1">
                        <Search
                            size={15}
                            className="absolute top-1/2 left-3 -translate-y-1/2 text-gray-400"
                        />
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => handleSearch(e.target.value)}
                            placeholder="Rechercher code, libellé..."
                            className="w-full rounded-lg border border-gray-300 py-2 pr-3 pl-9 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                        />
                    </div>

                    {/* Filtre date */}
                    <input
                        type="date"
                        value={date}
                        onChange={(e) => handleDateFilter(e.target.value)}
                        className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                    />

                    {hasFilters && (
                        <button
                            onClick={clearFilters}
                            className="flex items-center gap-1 text-sm text-gray-500 hover:text-red-500"
                        >
                            <X size={14} /> Effacer
                        </button>
                    )}

                    <div className="ml-auto flex gap-2">
                        {/* Import auto */}
                        <button
                            onClick={runAutoImport}
                            className="flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-sm text-white hover:bg-emerald-700"
                        >
                            <FolderSync size={15} />
                            Import auto
                        </button>

                        {/* Import manuel */}
                        <button
                            onClick={() => setShowImportModal(true)}
                            className="flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700"
                        >
                            <Upload size={15} />
                            Import manuel
                        </button>

                        {/* Historique */}
                        <button
                            onClick={() => setShowHistory(!showHistory)}
                            className="flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                        >
                            Historique
                            <ChevronDown
                                size={14}
                                className={`transition-transform ${showHistory ? 'rotate-180' : ''}`}
                            />
                        </button>
                    </div>
                </div>

                {/* ── HISTORIQUE ────────────────────────────────────────── */}
                {showHistory && (
                    <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                        <h3 className="mb-3 font-medium text-gray-700">
                            Derniers fichiers importés
                        </h3>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-gray-500">
                                        <th className="pr-4 pb-2">Fichier</th>
                                        <th className="pr-4 pb-2">Lignes</th>
                                        <th className="pb-2">Importé le</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {importHistory.map((f, i) => (
                                        <tr
                                            key={i}
                                            className="border-b text-gray-700 last:border-none"
                                        >
                                            <td className="py-1.5 pr-4 font-mono text-xs">
                                                {f.filename}
                                            </td>
                                            <td className="py-1.5 pr-4">
                                                {f.total_rows.toLocaleString(
                                                    'fr-FR',
                                                )}
                                            </td>
                                            <td className="py-1.5 text-gray-500">
                                                {new Date(
                                                    f.imported_at,
                                                ).toLocaleString()}
                                            </td>
                                        </tr>
                                    ))}
                                    {importHistory.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={3}
                                                className="py-3 text-center text-gray-400"
                                            >
                                                Aucun fichier importé
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <p className="mt-2 text-xs text-gray-400">
                            Dossier auto :{' '}
                            <span className="font-mono">{watchFolder}</span>
                        </p>
                    </div>
                )}

                {/* ── TABLEAU ───────────────────────────────────────────── */}
                <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div className="max-h-[500px] overflow-y-auto">
                        <table className="w-full text-sm">
                            <thead className="sticky top-0 bg-gray-800 text-white">
                                <tr>
                                    <th className="px-4 py-2.5 text-left">
                                        Code
                                    </th>
                                    <th className="px-4 py-2.5 text-left">
                                        Libellé
                                    </th>
                                    <th className="px-4 py-2.5 text-right">
                                        Prix U
                                    </th>
                                    <th className="px-4 py-2.5 text-right">
                                        Quantité
                                    </th>
                                    <th className="px-4 py-2.5 text-right">
                                        Montant
                                    </th>
                                    <th className="px-4 py-2.5 text-left">
                                        Date
                                    </th>
                                    <th className="px-4 py-2.5 text-left">
                                        Action
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="text-gray-700">
                                {rows.length ? (
                                    rows.map((achat) => (
                                        <tr
                                            key={achat.id}
                                            className="border-b last:border-none hover:bg-gray-50"
                                        >
                                            <td className="px-4 py-2 font-mono text-xs">
                                                {achat.Code}
                                            </td>
                                            <td className="px-4 py-2">
                                                {achat.Liblong}
                                            </td>
                                            <td className="px-4 py-2 text-right tabular-nums">
                                                {fmt(achat.PrixU)}
                                            </td>
                                            <td className="px-4 py-2 text-right tabular-nums">
                                                {fmt(achat.QuantiteAchat)}
                                            </td>
                                            <td className="px-4 py-2 text-right font-medium tabular-nums">
                                                {fmt(achat.montant)}
                                            </td>
                                            <td className="px-4 py-2 text-gray-500">
                                                {achat.date}
                                            </td>
                                            <td className="px-2 py-2">
                                                <button
                                                    onClick={() =>
                                                        handleDelete(achat.id)
                                                    }
                                                    className="rounded-lg bg-red-800 p-1.5 text-white hover:opacity-90"
                                                    title="Supprimer"
                                                >
                                                    <Trash2 size={14} />
                                                </button>
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-4 py-8 text-center text-gray-400"
                                        >
                                            {hasFilters
                                                ? 'Aucun résultat pour ces filtres'
                                                : 'Aucun achat importé'}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* ── PAGINATION ────────────────────────────────────────── */}
                <div className="flex justify-end gap-2">
                    {links.map((link: any, index: number) => (
                        <InertiaLink
                            key={index}
                            href={link.url || '#'}
                            className={`rounded border px-3 py-1 text-sm ${
                                link.active
                                    ? 'bg-blue-600 text-white'
                                    : 'bg-white text-gray-700 hover:bg-gray-50'
                            } ${!link.url ? 'pointer-events-none opacity-40' : ''}`}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </div>
            </div>

            {/* ── MODAL IMPORT MANUEL ───────────────────────────────────── */}
            {showImportModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
                    <div className="w-[500px] rounded-xl bg-white p-6 shadow-xl">
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="text-lg font-semibold text-gray-800">
                                Import manuel
                            </h2>
                            <button
                                onClick={() => setShowImportModal(false)}
                                className="text-gray-400 hover:text-gray-600"
                            >
                                <X size={20} />
                            </button>
                        </div>

                        <form onSubmit={submitImport}>
                            {/* Date des achats */}
                            <div className="mb-4">
                                <label className="mb-1 block text-sm font-medium text-gray-700">
                                    Date des achats
                                </label>
                                <input
                                    type="date"
                                    value={data.date}
                                    onChange={(e) =>
                                        setData('date', e.target.value)
                                    }
                                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                />
                                <p className="mt-1 text-xs text-gray-400">
                                    Par défaut : hier
                                </p>
                            </div>

                            {/* Zone de drop */}
                            <div
                                onDragOver={(e) => {
                                    e.preventDefault();
                                    setDragOver(true);
                                }}
                                onDragLeave={() => setDragOver(false)}
                                onDrop={(e) => {
                                    e.preventDefault();
                                    setDragOver(false);
                                    handleFiles(e.dataTransfer.files);
                                }}
                                onClick={() => fileInputRef.current?.click()}
                                className={`mb-4 cursor-pointer rounded-xl border-2 border-dashed p-8 text-center transition-colors ${
                                    dragOver
                                        ? 'border-blue-400 bg-blue-50'
                                        : data.files.length > 0
                                          ? 'border-emerald-400 bg-emerald-50'
                                          : 'border-gray-300 hover:border-blue-400 hover:bg-gray-50'
                                }`}
                            >
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept=".xlsx,.xls,.csv"
                                    multiple
                                    className="hidden"
                                    onChange={(e) =>
                                        handleFiles(e.target.files)
                                    }
                                />
                                {data.files.length > 0 ? (
                                    <div>
                                        <CheckCircle2
                                            size={32}
                                            className="mx-auto mb-2 text-emerald-500"
                                        />
                                        <p className="font-medium text-emerald-700">
                                            {data.files.length} fichier(s)
                                            sélectionné(s)
                                        </p>
                                        <ul className="mt-1 text-xs text-emerald-600">
                                            {data.files.map((f, i) => (
                                                <li key={i}>{f.name}</li>
                                            ))}
                                        </ul>
                                    </div>
                                ) : (
                                    <div>
                                        <Upload
                                            size={32}
                                            className="mx-auto mb-2 text-gray-400"
                                        />
                                        <p className="text-sm text-gray-500">
                                            Glissez vos fichiers ici ou{' '}
                                            <span className="text-blue-600">
                                                cliquez pour parcourir
                                            </span>
                                        </p>
                                        <p className="mt-1 text-xs text-gray-400">
                                            xlsx, xls, csv — max 20 Mo
                                        </p>
                                    </div>
                                )}
                            </div>

                            {/* Info déduplication */}
                            <div className="mb-4 flex gap-2 rounded-lg bg-blue-50 p-3 text-xs text-blue-700">
                                <AlertCircle
                                    size={14}
                                    className="mt-0.5 shrink-0"
                                />
                                <span>
                                    Les lignes déjà importées (même fichier)
                                    seront automatiquement ignorées.
                                </span>
                            </div>

                            <div className="flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setShowImportModal(false);
                                        reset();
                                    }}
                                    className="rounded-lg bg-gray-200 px-4 py-2 text-sm text-gray-700 hover:bg-gray-300"
                                >
                                    Annuler
                                </button>
                                <button
                                    type="submit"
                                    disabled={
                                        processing || data.files.length === 0
                                    }
                                    className="flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
                                >
                                    {processing ? (
                                        <>
                                            <span className="animate-spin">
                                                ⏳
                                            </span>{' '}
                                            Import en cours...
                                        </>
                                    ) : (
                                        <>
                                            <Upload size={14} /> Importer
                                        </>
                                    )}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}

// ─── Composant StatCard ───────────────────────────────────────────────────────

function StatCard({
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
        <div className="rounded-xl border border-gray-200 bg-white p-3 shadow-sm">
            <div className={`mb-2 inline-flex rounded-lg p-2 ${colors[color]}`}>
                {icon}
            </div>
            <p className="text-xs text-gray-500">{label}</p>
            <p className="mt-0.5 text-sm font-semibold text-gray-800 tabular-nums">
                {value}
            </p>
        </div>
    );
}
