import { Head, router, useForm, Link as InertiaLink } from '@inertiajs/react';
import { CirclePlusIcon, Pencil, Trash2 } from 'lucide-react';
import { useState, useRef } from 'react';
import { route } from 'ziggy-js';
import AppLayout from '@/layouts/app-layout';

export default function Index({ fournisseurs, filters }: any) {
    const [search, setSearch] = useState(filters?.search ?? '');
    const [openModal, setOpenModal] = useState(false);
    const [openEditModal, setOpenEditModal] = useState(false);

    // useRef : stable entre re-renders
    const editIdRef = useRef<number | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm({
        fournisseur: '',
    });

    const editForm = useForm({
        fournisseur: '',
    });

    // ── RECHERCHE ─────────────────────────────────────────────────────────────
    const handleSearch = (value: string) => {
        setSearch(value);
        // FIX : était 'fournisseur.index' (sans s) → corrigé en 'fournisseurs.index'
        router.get(
            route('fournisseurs.index'),
            { search: value },
            { preserveState: true, replace: true },
        );
    };

    // ── AJOUT ─────────────────────────────────────────────────────────────────
    const submit = (e: any) => {
        e.preventDefault();
        post(route('fournisseurs.store'), {
            onSuccess: () => {
                reset();
                setOpenModal(false);
            },
        });
    };

    // ── ÉDITION ───────────────────────────────────────────────────────────────
    const openEdit = (f: any) => {
        editIdRef.current = f.id_fournisseur;
        editForm.setData('fournisseur', f.fournisseur ?? '');
        setOpenEditModal(true);
    };

    const closeEditModal = () => {
        setOpenEditModal(false);
        editIdRef.current = null;
        editForm.reset();
    };

    const submitEdit = (e: any) => {
        e.preventDefault();
        const id = editIdRef.current;
        if (!id) return;
        editForm.put(route('fournisseurs.update', id), {
            onSuccess: () => closeEditModal(),
        });
    };

    // ── SUPPRESSION ───────────────────────────────────────────────────────────
    const handleDelete = (id: number) => {
        if (confirm('Confirmer la suppression de ce fournisseur ?')) {
            router.delete(route('fournisseurs.destroy', id));
        }
    };

    const rows = Array.isArray(fournisseurs?.data) ? fournisseurs.data : [];
    const links = Array.isArray(fournisseurs?.links) ? fournisseurs.links : [];

    return (
        <AppLayout>
            <Head title="Gestion Fournisseurs" />

            {/* Recherche + Bouton Ajouter */}
            <div className="flex items-center gap-4 p-4">
                <input
                    type="text"
                    value={search}
                    onChange={(e) => handleSearch(e.target.value)}
                    placeholder="Rechercher fournisseur..."
                    className="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                />
                <button
                    onClick={() => setOpenModal(true)}
                    className="flex items-center gap-1 rounded-lg bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700"
                >
                    <CirclePlusIcon size={16} />
                    Ajouter
                </button>
            </div>

            {/* TABLEAU */}
            <div className="mx-4 mb-4 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <div className="max-h-[430px] overflow-y-auto">
                    <table className="w-full text-sm">
                        <thead className="sticky top-0 bg-gray-800 text-white">
                            <tr className="text-center">
                                <th className="px-4 py-2">Fournisseur</th>
                                <th className="px-4 py-2">Action</th>
                            </tr>
                        </thead>
                        <tbody className="text-gray-700">
                            {rows.length ? (
                                rows.map((f: any) => (
                                    <tr
                                        key={f.id_fournisseur}
                                        className="border-b text-center last:border-none"
                                    >
                                        <td className="px-4 py-2">
                                            {f.fournisseur}
                                        </td>
                                        <td className="px-2 py-2">
                                            <button
                                                onClick={() => openEdit(f)}
                                                className="mr-2 rounded-lg bg-orange-600 p-1.5 text-white hover:opacity-90"
                                            >
                                                <Pencil size={16} />
                                            </button>
                                            <button
                                                onClick={() =>
                                                    handleDelete(
                                                        f.id_fournisseur,
                                                    )
                                                }
                                                className="rounded-lg bg-red-800 p-1.5 text-white hover:opacity-90"
                                            >
                                                <Trash2 size={16} />
                                            </button>
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td
                                        colSpan={2}
                                        className="px-4 py-2 text-center text-gray-500"
                                    >
                                        Aucun fournisseur trouvé
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* PAGINATION */}
            <div className="mb-4 flex justify-end gap-2 px-4">
                {links.map((link: any, index: number) => (
                    <InertiaLink
                        key={index}
                        href={link.url || '#'}
                        className={`rounded border px-3 py-1 ${
                            link.active
                                ? 'bg-blue-600 text-white'
                                : 'bg-white text-gray-700'
                        }`}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ))}
            </div>

            {/* MODAL AJOUT */}
            {openModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
                    <div className="w-[450px] rounded-lg bg-white p-6 text-gray-800">
                        <h2 className="mb-4 text-lg font-semibold">
                            Ajouter fournisseur
                        </h2>
                        <form onSubmit={submit}>
                            <div className="mb-3">
                                <label className="mb-1 block">
                                    Fournisseur
                                </label>
                                <input
                                    type="text"
                                    value={data.fournisseur}
                                    onChange={(e) =>
                                        setData('fournisseur', e.target.value)
                                    }
                                    className="w-full rounded border px-3 py-2"
                                />
                                {errors.fournisseur && (
                                    <p className="text-sm text-red-500">
                                        {errors.fournisseur}
                                    </p>
                                )}
                            </div>
                            <div className="mt-4 flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setOpenModal(false);
                                        reset();
                                    }}
                                    className="rounded bg-gray-300 px-4 py-2"
                                >
                                    Annuler
                                </button>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="rounded bg-blue-600 px-4 py-2 text-white disabled:opacity-60"
                                >
                                    Enregistrer
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* MODAL ÉDITION */}
            {openEditModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
                    <div className="w-[450px] rounded-lg bg-white p-6 text-gray-800">
                        <h2 className="mb-4 text-lg font-semibold">
                            Modifier fournisseur
                        </h2>
                        <form onSubmit={submitEdit}>
                            <div className="mb-3">
                                <label className="mb-1 block">
                                    Fournisseur
                                </label>
                                <input
                                    type="text"
                                    value={editForm.data.fournisseur}
                                    onChange={(e) =>
                                        editForm.setData(
                                            'fournisseur',
                                            e.target.value,
                                        )
                                    }
                                    className="w-full rounded border px-3 py-2"
                                />
                                {editForm.errors.fournisseur && (
                                    <p className="text-sm text-red-500">
                                        {editForm.errors.fournisseur}
                                    </p>
                                )}
                            </div>
                            <div className="mt-4 flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={closeEditModal}
                                    className="rounded bg-gray-300 px-4 py-2"
                                >
                                    Annuler
                                </button>
                                <button
                                    type="submit"
                                    disabled={editForm.processing}
                                    className="rounded bg-orange-600 px-4 py-2 text-white disabled:opacity-60"
                                >
                                    Mettre à jour
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
