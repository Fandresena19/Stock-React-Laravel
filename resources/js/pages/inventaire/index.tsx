import { Head, router } from '@inertiajs/react';
import {
    ClipboardList,
    Upload,
    Trash2,
    CheckCircle2,
    LayoutList,
} from 'lucide-react';
import { useState, useRef } from 'react';
import AppLayout from '@/layouts/app-layout';

// ── Helpers ───────────────────────────────────────────────────────────────────
const getCsrf = () =>
    decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');

// ── Types ─────────────────────────────────────────────────────────────────────
interface HistoriqueItem {
    id: number;
    date_inventaire: string;
    type: 'total' | 'partiel';
    filename: string | null;
    nb_lignes_modifiees: number;
    nb_lignes_ignorees: number;
    notes: string | null;
    created_at: string;
}

// ── Composant principal ───────────────────────────────────────────────────────
export default function Index({
    historique,
}: {
    historique: HistoriqueItem[];
}) {
    const [openImport, setOpenImport] = useState(false);
    const [file, setFile] = useState<File | null>(null);
    const [date, setDate] = useState('');
    const [type, setType] = useState<'partiel' | 'total'>('partiel');
    const [loading, setLoading] = useState(false);
    const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
    const fileRef = useRef<HTMLInputElement>(null);

    const handleImport = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!file || !date) return;
        setLoading(true);
        setMsg(null);

        const fd = new FormData();
        fd.append('file', file);
        fd.append('date', date);
        fd.append('type', type);

        try {
            const res = await fetch('/inventaire/import', {
                method: 'POST',
                headers: {
                    'X-XSRF-TOKEN': getCsrf(),
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: fd,
            });
            const json = await res.json();
            setMsg({
                text: json.message ?? (res.ok ? 'Importé.' : 'Erreur.'),
                ok: res.ok,
            });
            if (res.ok) {
                setFile(null);
                setDate('');
                if (fileRef.current) fileRef.current.value = '';
                router.reload({ only: ['historique'] });
            }
        } catch {
            setMsg({ text: 'Erreur réseau.', ok: false });
        } finally {
            setLoading(false);
        }
    };

    const handleDelete = (id: number) => {
        if (!confirm('Supprimer cet historique ?')) return;
        router.delete(`/inventaire/${id}`);
    };

    return (
        <AppLayout>
            <Head title="Inventaire" />
            <div className="min-h-screen bg-gray-50 p-6 dark:bg-gray-900">
                {/* Titre */}
                <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">
                            Inventaire
                        </h1>
                        <p className="text-sm text-gray-400">
                            Importez un inventaire Excel pour mettre à jour les
                            quantités en stock
                        </p>
                    </div>
                    <button
                        onClick={() => {
                            setOpenImport(true);
                            setMsg(null);
                        }}
                        className="flex items-center gap-2 rounded-xl bg-[#7a1a2e] px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#6b1525]"
                    >
                        <Upload size={15} />
                        Importer un inventaire
                    </button>
                </div>

                {/* Types expliqués */}
                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="rounded-2xl border border-teal-200 bg-teal-50 p-4 dark:border-teal-800 dark:bg-teal-900/20">
                        <div className="mb-2 flex items-center gap-2">
                            <LayoutList
                                size={16}
                                className="text-teal-700 dark:text-teal-400"
                            />
                            <p className="text-sm font-bold text-teal-700 dark:text-teal-400">
                                Inventaire partiel
                            </p>
                        </div>
                        <p className="text-xs text-teal-700/80 dark:text-teal-400/80">
                            Met à jour uniquement les articles présents dans le
                            fichier. Les autres articles restent inchangés.
                        </p>
                    </div>
                    <div className="rounded-2xl border border-orange-200 bg-orange-50 p-4 dark:border-orange-800 dark:bg-orange-900/20">
                        <div className="mb-2 flex items-center gap-2">
                            <CheckCircle2
                                size={16}
                                className="text-orange-700 dark:text-orange-400"
                            />
                            <p className="text-sm font-bold text-orange-700 dark:text-orange-400">
                                Inventaire total
                            </p>
                        </div>
                        <p className="text-xs text-orange-700/80 dark:text-orange-400/80">
                            Remet tous les stocks à 0 puis applique les valeurs
                            du fichier. Les articles absents du fichier auront
                            une quantité de 0.
                        </p>
                    </div>
                </div>

                {/* Historique */}
                <div className="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div className="flex items-center gap-2 border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                        <ClipboardList size={16} className="text-[#7a1a2e]" />
                        <h2 className="font-bold text-gray-800 dark:text-white">
                            Historique des imports
                        </h2>
                        <span className="ml-auto rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                            {historique.length} import(s)
                        </span>
                    </div>

                    {historique.length === 0 ? (
                        <div className="py-12 text-center text-sm text-gray-400">
                            Aucun inventaire importé pour l'instant
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-gray-50 dark:bg-gray-700/50">
                                    <tr>
                                        {[
                                            'Date inventaire',
                                            'Type',
                                            'Fichier',
                                            'Lignes modifiées',
                                            'Ignorées',
                                            'Importé le',
                                            '',
                                        ].map((h) => (
                                            <th
                                                key={h}
                                                className="px-4 py-2.5 text-left text-xs font-semibold text-gray-400 uppercase"
                                            >
                                                {h}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50 dark:divide-gray-700">
                                    {historique.map((item) => (
                                        <tr
                                            key={item.id}
                                            className="transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/30"
                                        >
                                            <td className="px-4 py-3 font-semibold text-gray-800 dark:text-gray-200">
                                                {item.date_inventaire}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span
                                                    className={`rounded-full px-2.5 py-0.5 text-xs font-bold ${
                                                        item.type === 'total'
                                                            ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400'
                                                            : 'bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-400'
                                                    }`}
                                                >
                                                    {item.type === 'total'
                                                        ? 'Total'
                                                        : 'Partiel'}
                                                </span>
                                            </td>
                                            <td className="max-w-[200px] truncate px-4 py-3 font-mono text-xs text-gray-500 dark:text-gray-400">
                                                {item.filename ?? '—'}
                                            </td>
                                            <td className="px-4 py-3 text-right font-bold text-[#7a1a2e] dark:text-red-400">
                                                {item.nb_lignes_modifiees.toLocaleString(
                                                    'fr-FR',
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right text-gray-400">
                                                {item.nb_lignes_ignorees > 0 ? (
                                                    <span className="text-amber-600">
                                                        {
                                                            item.nb_lignes_ignorees
                                                        }
                                                    </span>
                                                ) : (
                                                    '0'
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-xs text-gray-400">
                                                {item.created_at}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                <button
                                                    onClick={() =>
                                                        handleDelete(item.id)
                                                    }
                                                    className="rounded-lg p-1.5 text-gray-300 transition-colors hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-900/20"
                                                >
                                                    <Trash2 size={14} />
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>

            {/* Modal import */}
            {openImport && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
                    onClick={(e) =>
                        e.target === e.currentTarget && setOpenImport(false)
                    }
                >
                    <div className="w-full max-w-lg rounded-2xl bg-white shadow-2xl dark:bg-gray-900">
                        {/* En-tête modal */}
                        <div className="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-800">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-[#7a1a2e]/10 p-2 text-[#7a1a2e]">
                                    <Upload size={18} />
                                </div>
                                <h3 className="font-bold text-gray-800 dark:text-white">
                                    Importer un inventaire
                                </h3>
                            </div>
                            <button
                                onClick={() => setOpenImport(false)}
                                className="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 dark:hover:bg-gray-800"
                            >
                                ✕
                            </button>
                        </div>

                        {/* Formulaire */}
                        <form onSubmit={handleImport} className="space-y-5 p-6">
                            {/* Type */}
                            <div>
                                <label className="mb-2 block text-sm font-semibold text-gray-700 dark:text-gray-300">
                                    Type d'inventaire
                                </label>
                                <div className="grid grid-cols-2 gap-3">
                                    {(['partiel', 'total'] as const).map(
                                        (t) => (
                                            <button
                                                key={t}
                                                type="button"
                                                onClick={() => setType(t)}
                                                className={`rounded-xl border-2 px-4 py-3 text-sm font-semibold transition-all ${
                                                    type === t
                                                        ? t === 'total'
                                                            ? 'border-orange-400 bg-orange-50 text-orange-700 dark:border-orange-600 dark:bg-orange-900/20 dark:text-orange-400'
                                                            : 'border-teal-400 bg-teal-50 text-teal-700 dark:border-teal-600 dark:bg-teal-900/20 dark:text-teal-400'
                                                        : 'border-gray-200 bg-white text-gray-500 hover:border-gray-300 dark:border-gray-700 dark:bg-gray-800'
                                                }`}
                                            >
                                                {t === 'partiel'
                                                    ? '📋 Partiel'
                                                    : '✅ Total'}
                                            </button>
                                        ),
                                    )}
                                </div>
                                <p className="mt-2 text-xs text-gray-400">
                                    {type === 'total'
                                        ? '⚠️ Tous les stocks seront remis à 0 avant import.'
                                        : 'Seuls les articles du fichier seront mis à jour.'}
                                </p>
                            </div>

                            {/* Date */}
                            <div>
                                <label className="mb-1.5 block text-sm font-semibold text-gray-700 dark:text-gray-300">
                                    Date de l'inventaire
                                </label>
                                <input
                                    type="date"
                                    value={date}
                                    onChange={(e) => setDate(e.target.value)}
                                    required
                                    className="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm focus:border-[#7a1a2e] focus:ring-2 focus:ring-[#7a1a2e]/30 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                                />
                            </div>

                            {/* Fichier */}
                            <div>
                                <label className="mb-1.5 block text-sm font-semibold text-gray-700 dark:text-gray-300">
                                    Fichier Excel / CSV
                                </label>
                                <input
                                    ref={fileRef}
                                    type="file"
                                    accept=".xlsx,.xls,.csv"
                                    onChange={(e) =>
                                        setFile(e.target.files?.[0] ?? null)
                                    }
                                    required
                                    className="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-[#7a1a2e] file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                                />
                                <p className="mt-1 text-xs text-gray-400">
                                    Colonnes requises :{' '}
                                    <span className="font-mono">Code</span>,{' '}
                                    <span className="font-mono">
                                        QuantiteStock
                                    </span>{' '}
                                    (ou qte, stock, qty).{' '}
                                    <span className="font-mono">PrixU</span>{' '}
                                    optionnel.
                                </p>
                            </div>

                            {/* Message résultat */}
                            {msg && (
                                <div
                                    className={`rounded-xl px-4 py-3 text-sm font-medium ${
                                        msg.ok
                                            ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/20 dark:text-teal-400'
                                            : 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400'
                                    }`}
                                >
                                    {msg.text}
                                </div>
                            )}

                            {/* Boutons */}
                            <div className="flex justify-end gap-3 pt-1">
                                <button
                                    type="button"
                                    onClick={() => setOpenImport(false)}
                                    className="rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300"
                                >
                                    Annuler
                                </button>
                                <button
                                    type="submit"
                                    disabled={loading || !file || !date}
                                    className="flex items-center gap-2 rounded-xl bg-[#7a1a2e] px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#6b1525] disabled:opacity-50"
                                >
                                    <Upload size={14} />
                                    {loading ? 'Import en cours…' : 'Importer'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
