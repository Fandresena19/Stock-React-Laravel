import { Head, router, useForm } from '@inertiajs/react';
import { CirclePlus, Pencil, Trash2 } from 'lucide-react';
import { useState, useRef } from 'react';
import { route } from 'ziggy-js';
import {
    PageHeader,
    BtnPrimary,
    BtnIcon,
    DataTable,
    Tr,
    Td,
    Pagination,
    SearchInput,
    Modal,
    FormField,
    ModalActions,
    inputCls,
} from '@/components/ui/page-components';
import AppLayout from '@/layouts/app-layout';

export default function Index({ articles, fournisseurs, filters }: any) {
    const [search, setSearch] = useState(filters?.search ?? '');
    const [openAdd, setOpenAdd] = useState(false);
    const [openEdit, setOpenEdit] = useState(false);
    const editCodeRef = useRef<string | null>(null);

    const addForm = useForm({ code: '', Liblong: '', fournisseur: '' });
    const editForm = useForm({ Liblong: '', fournisseur: '' });

    const handleSearch = (v: string) => {
        setSearch(v);
        router.get(
            route('articles.index'),
            { search: v },
            { preserveState: true, replace: true },
        );
    };

    const submitAdd = (e: any) => {
        e.preventDefault();
        addForm.post(route('articles.store'), {
            onSuccess: () => {
                addForm.reset();
                setOpenAdd(false);
            },
        });
    };

    const openEditModal = (a: any) => {
        editCodeRef.current = a.Code;
        editForm.setData({
            Liblong: a.Liblong,
            fournisseur: a.fournisseur ?? '',
        });
        setOpenEdit(true);
    };

    const submitEdit = (e: any) => {
        e.preventDefault();
        editForm.put(route('articles.update', editCodeRef.current!), {
            onSuccess: () => {
                setOpenEdit(false);
            },
        });
    };

    const handleDelete = (code: string) => {
        if (confirm('Supprimer cet article ?'))
            router.delete(route('articles.destroy', code));
    };

    const rows = Array.isArray(articles?.data) ? articles.data : [];
    const links = Array.isArray(articles?.links) ? articles.links : [];

    return (
        <AppLayout>
            <Head title="Articles" />
            <div className="min-h-screen bg-gray-50 p-6 dark:bg-gray-900">
                <PageHeader
                    title="Gestion des Articles"
                    subtitle={`${articles?.total ?? 0} article(s) enregistré(s)`}
                    action={
                        <BtnPrimary onClick={() => setOpenAdd(true)}>
                            <CirclePlus size={16} /> Ajouter Article
                        </BtnPrimary>
                    }
                />

                <div className="mb-4">
                    <SearchInput
                        value={search}
                        onChange={handleSearch}
                        placeholder="Rechercher code, désignation…"
                    />
                </div>

                <DataTable
                    headers={[
                        { label: 'Code' },
                        { label: 'Désignation' },
                        { label: 'Fournisseur' },
                        { label: 'Actions', align: 'center' },
                    ]}
                >
                    {rows.length ? (
                        rows.map((a: any) => (
                            <Tr key={a.Code}>
                                <Td mono muted>
                                    {a.Code}
                                </Td>
                                <Td>{a.Liblong}</Td>
                                <Td muted>{a.fournisseur ?? '—'}</Td>
                                <td className="px-4 py-2.5 text-center">
                                    <BtnIcon
                                        color="orange"
                                        onClick={() => openEditModal(a)}
                                    >
                                        <Pencil size={14} />
                                    </BtnIcon>
                                    <BtnIcon
                                        color="red"
                                        onClick={() => handleDelete(a.Code)}
                                    >
                                        <Trash2 size={14} />
                                    </BtnIcon>
                                </td>
                            </Tr>
                        ))
                    ) : (
                        <tr>
                            <td
                                colSpan={4}
                                className="py-10 text-center text-gray-400"
                            >
                                Aucun article trouvé
                            </td>
                        </tr>
                    )}
                </DataTable>

                <Pagination links={links} />
            </div>

            {/* Modal Ajout */}
            <Modal
                open={openAdd}
                onClose={() => {
                    setOpenAdd(false);
                    addForm.reset();
                }}
                title="Ajouter un article"
            >
                <form onSubmit={submitAdd} className="space-y-4">
                    <FormField label="Code article" error={addForm.errors.code}>
                        <input
                            type="text"
                            value={addForm.data.code}
                            onChange={(e) =>
                                addForm.setData('code', e.target.value)
                            }
                            className={inputCls}
                            placeholder="Ex: 10311"
                            autoFocus
                        />
                    </FormField>
                    <FormField
                        label="Désignation"
                        error={addForm.errors.Liblong}
                    >
                        <input
                            type="text"
                            value={addForm.data.Liblong}
                            onChange={(e) =>
                                addForm.setData('Liblong', e.target.value)
                            }
                            className={inputCls}
                        />
                    </FormField>
                    <FormField
                        label="Fournisseur"
                        error={addForm.errors.fournisseur}
                    >
                        <select
                            value={addForm.data.fournisseur}
                            onChange={(e) =>
                                addForm.setData('fournisseur', e.target.value)
                            }
                            className={inputCls}
                        >
                            <option value="">
                                Sélectionner un fournisseur…
                            </option>
                            {(fournisseurs ?? []).map((f: any) => (
                                <option
                                    key={f.id_fournisseur}
                                    value={f.fournisseur}
                                >
                                    {f.fournisseur}
                                </option>
                            ))}
                        </select>
                    </FormField>
                    <ModalActions
                        onCancel={() => {
                            setOpenAdd(false);
                            addForm.reset();
                        }}
                        loading={addForm.processing}
                        label="Enregistrer"
                    />
                </form>
            </Modal>

            {/* Modal Édition */}
            <Modal
                open={openEdit}
                onClose={() => setOpenEdit(false)}
                title="Modifier l'article"
            >
                <form onSubmit={submitEdit} className="space-y-4">
                    <FormField
                        label="Désignation"
                        error={editForm.errors.Liblong}
                    >
                        <input
                            type="text"
                            value={editForm.data.Liblong}
                            onChange={(e) =>
                                editForm.setData('Liblong', e.target.value)
                            }
                            className={inputCls}
                            autoFocus
                        />
                    </FormField>
                    <FormField
                        label="Fournisseur"
                        error={editForm.errors.fournisseur}
                    >
                        <select
                            value={editForm.data.fournisseur}
                            onChange={(e) =>
                                editForm.setData('fournisseur', e.target.value)
                            }
                            className={inputCls}
                        >
                            <option value="">Sélectionner…</option>
                            {(fournisseurs ?? []).map((f: any) => (
                                <option
                                    key={f.id_fournisseur}
                                    value={f.fournisseur}
                                >
                                    {f.fournisseur}
                                </option>
                            ))}
                        </select>
                    </FormField>
                    <ModalActions
                        onCancel={() => setOpenEdit(false)}
                        loading={editForm.processing}
                        label="Mettre à jour"
                        color="orange"
                    />
                </form>
            </Modal>
        </AppLayout>
    );
}
