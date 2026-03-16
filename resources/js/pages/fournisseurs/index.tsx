import { Head, router, useForm, Link as InertiaLink } from '@inertiajs/react';
import { CirclePlus, Pencil, Trash2 } from 'lucide-react';
import { useState, useRef } from 'react';
import { route } from 'ziggy-js';
import AppLayout from '@/layouts/app-layout';
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

export default function Index({ fournisseurs, filters }: any) {
    const [search, setSearch] = useState(filters?.search ?? '');
    const [openAdd, setOpenAdd] = useState(false);
    const [openEdit, setOpenEdit] = useState(false);
    const editIdRef = useRef<number | null>(null);

    const addForm = useForm({ fournisseur: '' });
    const editForm = useForm({ fournisseur: '' });

    const handleSearch = (v: string) => {
        setSearch(v);
        router.get(
            route('fournisseurs.index'),
            { search: v },
            { preserveState: true, replace: true },
        );
    };

    const submitAdd = (e: any) => {
        e.preventDefault();
        addForm.post(route('fournisseurs.store'), {
            onSuccess: () => {
                addForm.reset();
                setOpenAdd(false);
            },
        });
    };

    const openEditModal = (f: any) => {
        editIdRef.current = f.id_fournisseur;
        editForm.setData('fournisseur', f.fournisseur ?? '');
        setOpenEdit(true);
    };

    const submitEdit = (e: any) => {
        e.preventDefault();
        editForm.put(route('fournisseurs.update', editIdRef.current!), {
            onSuccess: () => {
                setOpenEdit(false);
                editIdRef.current = null;
            },
        });
    };

    const handleDelete = (id: number) => {
        if (confirm('Supprimer ce fournisseur ?'))
            router.delete(route('fournisseurs.destroy', id));
    };

    const rows = Array.isArray(fournisseurs?.data) ? fournisseurs.data : [];
    const links = Array.isArray(fournisseurs?.links) ? fournisseurs.links : [];

    return (
        <AppLayout>
            <Head title="Fournisseurs" />
            <div className="min-h-screen bg-gray-50 p-6 dark:bg-gray-900">
                <PageHeader
                    title="Gestion des Fournisseurs"
                    subtitle={`${fournisseurs?.total ?? 0} fournisseur(s)`}
                    action={
                        <BtnPrimary onClick={() => setOpenAdd(true)}>
                            <CirclePlus size={16} /> Ajouter
                        </BtnPrimary>
                    }
                />

                <div className="mb-4">
                    <SearchInput
                        value={search}
                        onChange={handleSearch}
                        placeholder="Rechercher fournisseur…"
                    />
                </div>

                <DataTable
                    headers={[
                        { label: 'Fournisseur' },
                        { label: 'Actions', align: 'center' },
                    ]}
                >
                    {rows.length ? (
                        rows.map((f: any) => (
                            <Tr key={f.id_fournisseur}>
                                <Td>{f.fournisseur}</Td>
                                <td className="px-4 py-2.5 text-center">
                                    <span className="inline-flex gap-1.5">
                                        <BtnIcon
                                            color="orange"
                                            onClick={() => openEditModal(f)}
                                        >
                                            <Pencil size={14} />
                                        </BtnIcon>
                                        <BtnIcon
                                            color="red"
                                            onClick={() =>
                                                handleDelete(f.id_fournisseur)
                                            }
                                        >
                                            <Trash2 size={14} />
                                        </BtnIcon>
                                    </span>
                                </td>
                            </Tr>
                        ))
                    ) : (
                        <tr>
                            <td
                                colSpan={2}
                                className="py-10 text-center text-gray-400"
                            >
                                Aucun fournisseur trouvé
                            </td>
                        </tr>
                    )}
                </DataTable>

                <Pagination links={links} />
            </div>

            <Modal
                open={openAdd}
                onClose={() => {
                    setOpenAdd(false);
                    addForm.reset();
                }}
                title="Ajouter un fournisseur"
            >
                <form onSubmit={submitAdd} className="space-y-4">
                    <FormField
                        label="Nom du fournisseur"
                        error={addForm.errors.fournisseur}
                    >
                        <input
                            type="text"
                            value={addForm.data.fournisseur}
                            onChange={(e) =>
                                addForm.setData('fournisseur', e.target.value)
                            }
                            className={inputCls}
                            placeholder="Ex: PROPACK"
                            autoFocus
                        />
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

            <Modal
                open={openEdit}
                onClose={() => setOpenEdit(false)}
                title="Modifier le fournisseur"
            >
                <form onSubmit={submitEdit} className="space-y-4">
                    <FormField
                        label="Nom du fournisseur"
                        error={editForm.errors.fournisseur}
                    >
                        <input
                            type="text"
                            value={editForm.data.fournisseur}
                            onChange={(e) =>
                                editForm.setData('fournisseur', e.target.value)
                            }
                            className={inputCls}
                            autoFocus
                        />
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
