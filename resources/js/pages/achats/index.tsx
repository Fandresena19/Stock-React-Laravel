import { Head, router, useForm } from '@inertiajs/react';
import { Upload, Trash2, RefreshCw, Calendar } from 'lucide-react';
import { useState } from 'react';
import { route } from 'ziggy-js';
import {
    PageHeader,
    BtnPrimary,
    BtnSecondary,
    BtnIcon,
    DataTable,
    Tr,
    Td,
    Pagination,
    SearchInput,
    Modal,
    FormField,
    inputCls,
} from '@/components/ui/page-components';
import AppLayout from '@/layouts/app-layout';

const fmt = (v: any) =>
    parseFloat(v || 0).toLocaleString('fr-FR', { minimumFractionDigits: 0 });

export default function Index({ achats, stats, filters, watchFolder }: any) {
    const [search, setSearch] = useState(filters?.search ?? '');
    const [date, setDate] = useState(filters?.date ?? '');
    const [openImport, setOpenImport] = useState(false);
    const [importFiles, setImportFiles] = useState<File[]>([]);
    const [importDate, setImportDate] = useState('');

    const handleSearch = (v: string) => {
        setSearch(v);
        router.get(
            route('achats.index'),
            { search: v, date },
            { preserveState: true, replace: true },
        );
    };

    const handleDate = (v: string) => {
        setDate(v);
        router.get(
            route('achats.index'),
            { search, date: v },
            { preserveState: true, replace: true },
        );
    };

    const handleDelete = (achat: any) => {
        if (confirm('Supprimer cette ligne ? Le stock sera ajusté.')) {
            router.post(route('achats.destroy'), {
                Code: achat.Code,
                date: achat.dateRaw, // date format Y-m-d
                PrixU: achat.PrixU,
                QuantiteAchat: achat.QuantiteAchat,
            });
        }
    };

    const handleImportAuto = () => {
        if (
            confirm(
                `Importer automatiquement les fichiers d'hier depuis :\n${watchFolder} ?`,
            )
        )
            router.post(route('achats.import-auto'));
    };

    const submitImport = (e: any) => {
        e.preventDefault();
        if (!importFiles.length) return;
        const fd = new FormData();
        importFiles.forEach((f) => fd.append('files[]', f));
        if (importDate) fd.append('date', importDate);
        fd.append(
            '_token',
            (
                document.querySelector(
                    'meta[name="csrf-token"]',
                ) as HTMLMetaElement
            )?.content ?? '',
        );
        fetch(route('achats.import'), { method: 'POST', body: fd }).finally(
            () => {
                setOpenImport(false);
                setImportFiles([]);
                setImportDate('');
                router.reload();
            },
        );
    };

    const rows = Array.isArray(achats?.data) ? achats.data : [];
    const links = Array.isArray(achats?.links) ? achats.links : [];

    return (
        <AppLayout>
            <Head title="Achats" />
            <div className="min-h-screen bg-gray-50 p-6 dark:bg-gray-900">
                <PageHeader
                    title="Suivi des Achats"
                    subtitle={`${stats?.total_lignes ?? 0} ligne(s) — Total : ${fmt(stats?.total_montant)} Ar`}
                    action={
                        <div className="flex gap-2">
                            <BtnSecondary onClick={handleImportAuto}>
                                <RefreshCw size={15} /> Import Auto
                            </BtnSecondary>
                            <BtnPrimary onClick={() => setOpenImport(true)}>
                                <Upload size={15} /> Importer Excel
                            </BtnPrimary>
                        </div>
                    }
                />

                {/* Mini stats */}
                <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {[
                        { label: 'Lignes', value: fmt(stats?.total_lignes) },
                        {
                            label: 'Montant total',
                            value: `${fmt(stats?.total_montant)} Ar`,
                        },
                        {
                            label: 'Dernière date',
                            value: stats?.derniere_date ?? '—',
                        },
                        { label: 'Fichiers', value: fmt(stats?.fichiers) },
                    ].map((s) => (
                        <div
                            key={s.label}
                            className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800"
                        >
                            <p className="text-xs text-gray-400">{s.label}</p>
                            <p className="mt-1 truncate text-base font-bold text-gray-800 dark:text-white">
                                {s.value}
                            </p>
                        </div>
                    ))}
                </div>

                {/* Filtres */}
                <div className="mb-4 flex flex-wrap gap-3">
                    <div className="min-w-[200px] flex-1">
                        <SearchInput
                            value={search}
                            onChange={handleSearch}
                            placeholder="Rechercher code, désignation…"
                        />
                    </div>
                    <div className="flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <Calendar size={15} className="text-gray-400" />
                        <input
                            type="date"
                            value={date}
                            onChange={(e) => handleDate(e.target.value)}
                            className="bg-transparent py-2.5 text-sm focus:outline-none dark:text-white"
                        />
                    </div>
                </div>

                <DataTable
                    headers={[
                        { label: 'Code' },
                        { label: 'Désignation' },
                        { label: 'Prix U.', align: 'right' },
                        { label: 'Quantité', align: 'right' },
                        { label: 'Montant', align: 'right' },
                        { label: 'Date', align: 'center' },
                        { label: '', align: 'center' },
                    ]}
                >
                    {rows.length ? (
                        rows.map((a: any) => (
                            <Tr key={a.id}>
                                <Td mono muted>
                                    {a.Code}
                                </Td>
                                <Td>{a.Liblong}</Td>
                                <Td align="right" muted>
                                    {fmt(a.PrixU)}
                                </Td>
                                <Td align="right">{a.QuantiteAchat}</Td>
                                <td className="px-4 py-2.5 text-right font-bold text-[#7a1a2e] dark:text-red-400">
                                    {fmt(a.montant)} Ar
                                </td>
                                <Td align="center" muted>
                                    {a.date}
                                </Td>
                                <td className="px-4 py-2.5 text-center">
                                    <BtnIcon
                                        color="red"
                                        onClick={() => handleDelete(a)}
                                    >
                                        <Trash2 size={14} />
                                    </BtnIcon>
                                </td>
                            </Tr>
                        ))
                    ) : (
                        <tr>
                            <td
                                colSpan={7}
                                className="py-10 text-center text-gray-400"
                            >
                                Aucun achat trouvé
                            </td>
                        </tr>
                    )}
                </DataTable>

                <Pagination links={links} />
            </div>

            {/* Modal Import */}
            <Modal
                open={openImport}
                onClose={() => setOpenImport(false)}
                title="Importer des achats Excel"
            >
                <form onSubmit={submitImport} className="space-y-4">
                    <FormField label="Fichiers Excel / CSV">
                        <input
                            type="file"
                            multiple
                            accept=".xlsx,.xls,.csv"
                            onChange={(e) =>
                                setImportFiles(Array.from(e.target.files ?? []))
                            }
                            className="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-[#7a1a2e] file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white dark:border-gray-700"
                        />
                    </FormField>
                    <FormField label="Date des achats (optionnel — défaut : hier)">
                        <input
                            type="date"
                            value={importDate}
                            onChange={(e) => setImportDate(e.target.value)}
                            className={inputCls}
                        />
                    </FormField>
                    <div className="flex justify-end gap-3 pt-2">
                        <button
                            type="button"
                            onClick={() => setOpenImport(false)}
                            className="rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300"
                        >
                            Annuler
                        </button>
                        <button
                            type="submit"
                            disabled={!importFiles.length}
                            className="flex items-center gap-2 rounded-xl bg-[#7a1a2e] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#6b1525] disabled:opacity-50"
                        >
                            <Upload size={14} /> Importer
                        </button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
