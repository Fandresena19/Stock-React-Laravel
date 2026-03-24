import { Head, router } from '@inertiajs/react';
import {
    Boxes,
    PackageSearch,
    TriangleAlert,
    AlertOctagon,
    ShoppingCart,
    PackagePlus,
} from 'lucide-react';
import { route } from 'ziggy-js';
import AppLayout from '@/layouts/app-layout';

const n = (v: any): number => {
    const x = parseFloat(v);
    return isNaN(x) ? 0 : x;
};
const fmtFull = (v: any, dec = 0): string =>
    n(v).toLocaleString('fr-FR', {
        minimumFractionDigits: dec,
        maximumFractionDigits: dec,
    });

export default function Dashboard({
    hierLabel,
    stats,
    ventesHier,
    achatsHier,
    ruptures,
    stocksFaibles,
}: any) {
    return (
        <AppLayout>
            <Head title="Tableau de Bord" />
            <div className="min-h-screen bg-gray-50 p-6 dark:bg-gray-900">
                {/* ── TITRE ── */}
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">
                            Tableau de Bord
                        </h1>
                        <p className="text-sm text-gray-400">
                            Récapitulatif des mouvements du{' '}
                            <span className="font-semibold text-gray-600 dark:text-gray-300">
                                {hierLabel}
                            </span>
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <button
                            onClick={() => router.visit('/achats')}
                            className="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 transition-colors hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                        >
                            Voir les achats
                        </button>
                        <button
                            onClick={() => router.visit(route('stocks.index'))}
                            className="rounded-xl bg-[#7a1a2e] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#6b1525]"
                        >
                            Voir les stocks
                        </button>
                    </div>
                </div>

                {/* ── STAT CARDS — même style que stocks/index ── */}
                <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <StatCard
                        icon={<Boxes size={18} />}
                        label="Total Articles"
                        value={fmtFull(stats?.total_articles)}
                        color="bordeaux"
                    />
                    <StatCard
                        icon={<PackageSearch size={18} />}
                        label="Valeur Stock"
                        value={fmtFull(stats?.valeur_stock) + ' Ar'}
                        color="teal"
                    />
                    <StatCard
                        icon={<AlertOctagon size={18} />}
                        label="Ruptures"
                        value={fmtFull(stats?.ruptures)}
                        color="red"
                    />
                    <StatCard
                        icon={<TriangleAlert size={18} />}
                        label="Stock Faible (≤ 5)"
                        value={fmtFull(stats?.stock_faible)}
                        color="gray"
                    />
                </div>

                {/* ── VENTES HIER + ACHATS HIER ── */}
                <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    {/* Ventes d'hier */}
                    <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <div className="mb-4 flex items-center gap-3">
                            <div className="rounded-xl bg-[#7a1a2e]/10 p-2.5">
                                <ShoppingCart
                                    size={18}
                                    className="text-[#7a1a2e] dark:text-red-400"
                                />
                            </div>
                            <div>
                                <h2 className="font-bold text-gray-800 dark:text-white">
                                    Ventes — {hierLabel}
                                </h2>
                                <p className="text-xs text-gray-400">
                                    {ventesHier?.table}
                                </p>
                            </div>
                        </div>

                        {!ventesHier?.disponible ? (
                            <div className="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:bg-amber-900/20 dark:text-amber-400">
                                Aucune table de ventes pour {hierLabel}.
                                Vérifiez que le logiciel de caisse a fonctionné.
                            </div>
                        ) : (
                            <>
                                <div className="mb-4 grid grid-cols-3 gap-3">
                                    <MiniKpi
                                        label="Montant net"
                                        value={
                                            fmtFull(ventesHier.montant_net) +
                                            ' Ar'
                                        }
                                        highlight
                                    />
                                    <MiniKpi
                                        label="Nb lignes"
                                        value={fmtFull(ventesHier.nb_lignes)}
                                    />
                                    <MiniKpi
                                        label="Qté vendue"
                                        value={fmtFull(
                                            ventesHier.qte_totale,
                                            1,
                                        )}
                                    />
                                </div>

                                {(ventesHier.detail ?? []).length > 0 ? (
                                    <div className="overflow-hidden rounded-xl border border-gray-100 dark:border-gray-700">
                                        <div className="max-h-[280px] overflow-y-auto">
                                            <table className="w-full text-sm">
                                                <thead className="sticky top-0 bg-gray-50 dark:bg-gray-700/50">
                                                    <tr>
                                                        <th className="px-3 py-2 text-left text-xs font-semibold text-gray-400 uppercase">
                                                            Article
                                                        </th>
                                                        <th className="px-3 py-2 text-right text-xs font-semibold text-gray-400 uppercase">
                                                            Qté
                                                        </th>
                                                        <th className="px-3 py-2 text-right text-xs font-semibold text-gray-400 uppercase">
                                                            Montant net
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-gray-50 dark:divide-gray-700">
                                                    {ventesHier.detail.map(
                                                        (a: any, i: number) => (
                                                            <tr
                                                                key={i}
                                                                className="transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/30"
                                                            >
                                                                <td className="px-3 py-2">
                                                                    <p className="max-w-[200px] truncate font-medium text-gray-800 dark:text-gray-200">
                                                                        {
                                                                            a.liblong
                                                                        }
                                                                    </p>
                                                                    <p className="font-mono text-xs text-gray-400">
                                                                        {a.code}
                                                                    </p>
                                                                </td>
                                                                <td className="px-3 py-2 text-right text-gray-600 dark:text-gray-300">
                                                                    {fmtFull(
                                                                        a.qte,
                                                                        1,
                                                                    )}
                                                                </td>
                                                                <td className="px-3 py-2 text-right font-bold text-[#7a1a2e] dark:text-red-400">
                                                                    {fmtFull(
                                                                        a.montant,
                                                                    )}{' '}
                                                                    Ar
                                                                </td>
                                                            </tr>
                                                        ),
                                                    )}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="rounded-xl bg-gray-50 px-4 py-6 text-center text-sm text-gray-400 dark:bg-gray-700/30">
                                        Aucune vente enregistrée
                                    </div>
                                )}
                            </>
                        )}
                    </div>

                    {/* Achats d'hier */}
                    <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <div className="mb-4 flex items-center gap-3">
                            <div className="rounded-xl bg-teal-100 p-2.5 dark:bg-teal-900/30">
                                <PackagePlus
                                    size={18}
                                    className="text-teal-700 dark:text-teal-400"
                                />
                            </div>
                            <div>
                                <h2 className="font-bold text-gray-800 dark:text-white">
                                    Achats — {hierLabel}
                                </h2>
                                <p className="text-xs text-gray-400">
                                    Base principale
                                </p>
                            </div>
                        </div>

                        <div className="mb-4 grid grid-cols-3 gap-3">
                            <MiniKpi
                                label="Montant total"
                                value={fmtFull(achatsHier?.montant) + ' Ar'}
                                highlight
                                color="teal"
                            />
                            <MiniKpi
                                label="Nb lignes"
                                value={fmtFull(achatsHier?.nb_lignes)}
                            />
                            <MiniKpi
                                label="Qté achetée"
                                value={fmtFull(achatsHier?.qte_totale, 1)}
                            />
                        </div>

                        {(achatsHier?.detail ?? []).length > 0 ? (
                            <div className="overflow-hidden rounded-xl border border-gray-100 dark:border-gray-700">
                                <div className="max-h-[280px] overflow-y-auto">
                                    <table className="w-full text-sm">
                                        <thead className="sticky top-0 bg-gray-50 dark:bg-gray-700/50">
                                            <tr>
                                                <th className="px-3 py-2 text-left text-xs font-semibold text-gray-400 uppercase">
                                                    Article
                                                </th>
                                                <th className="px-3 py-2 text-right text-xs font-semibold text-gray-400 uppercase">
                                                    Qté
                                                </th>
                                                <th className="px-3 py-2 text-right text-xs font-semibold text-gray-400 uppercase">
                                                    Montant
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-50 dark:divide-gray-700">
                                            {achatsHier.detail.map(
                                                (a: any, i: number) => (
                                                    <tr
                                                        key={i}
                                                        className="transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/30"
                                                    >
                                                        <td className="px-3 py-2">
                                                            <p className="max-w-[200px] truncate font-medium text-gray-800 dark:text-gray-200">
                                                                {a.Liblong}
                                                            </p>
                                                            <p className="font-mono text-xs text-gray-400">
                                                                {a.Code}
                                                            </p>
                                                        </td>
                                                        <td className="px-3 py-2 text-right text-gray-600 dark:text-gray-300">
                                                            {fmtFull(
                                                                a.QuantiteAchat,
                                                                1,
                                                            )}
                                                        </td>
                                                        <td className="px-3 py-2 text-right font-bold text-teal-700 dark:text-teal-400">
                                                            {fmtFull(a.montant)}{' '}
                                                            Ar
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        ) : (
                            <div className="rounded-xl bg-gray-50 px-4 py-6 text-center text-sm text-gray-400 dark:bg-gray-700/30">
                                Aucun achat enregistré pour cette date
                            </div>
                        )}
                    </div>
                </div>

                {/* ── RUPTURES + STOCKS FAIBLES ── */}
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    {/* Ruptures */}
                    <div className="rounded-2xl border border-red-200 bg-white p-5 shadow-sm dark:border-red-900/30 dark:bg-gray-800">
                        <div className="mb-4 flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <AlertOctagon
                                    size={16}
                                    className="text-red-600"
                                />
                                <h2 className="font-bold text-gray-800 dark:text-white">
                                    Ruptures de stock
                                </h2>
                                <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-bold text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                    {stats?.ruptures ?? 0}
                                </span>
                            </div>
                            <button
                                onClick={() =>
                                    router.visit(
                                        route('stocks.index') + '?max_qte=1',
                                    )
                                }
                                className="text-xs font-semibold text-red-600 hover:underline dark:text-red-400"
                            >
                                Voir tout →
                            </button>
                        </div>
                        <div className="overflow-hidden rounded-xl border border-gray-100 dark:border-gray-700">
                            <table className="w-full text-sm">
                                <thead className="bg-red-50 dark:bg-red-900/10">
                                    <tr>
                                        <th className="px-3 py-2 text-left text-xs font-semibold text-red-400 uppercase">
                                            Article
                                        </th>
                                        <th className="px-3 py-2 text-right text-xs font-semibold text-red-400 uppercase">
                                            Qté
                                        </th>
                                        <th className="px-3 py-2 text-right text-xs font-semibold text-red-400 uppercase">
                                            Prix U.
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50 dark:divide-gray-700">
                                    {(ruptures ?? []).map((s: any) => (
                                        <tr
                                            key={s.Code}
                                            className="transition-colors hover:bg-red-50/50 dark:hover:bg-red-900/10"
                                        >
                                            <td className="px-3 py-2">
                                                <p className="max-w-[200px] truncate font-medium text-gray-800 dark:text-gray-200">
                                                    {s.Liblong}
                                                </p>
                                                <p className="font-mono text-xs text-gray-400">
                                                    {s.Code}
                                                </p>
                                            </td>
                                            <td className="px-3 py-2 text-right font-bold text-red-600 dark:text-red-400">
                                                {fmtFull(s.QuantiteStock)}
                                            </td>
                                            <td className="px-3 py-2 text-right text-gray-500">
                                                {fmtFull(s.PrixU)} Ar
                                            </td>
                                        </tr>
                                    ))}
                                    {!(ruptures ?? []).length && (
                                        <tr>
                                            <td
                                                colSpan={3}
                                                className="py-5 text-center text-sm text-gray-400"
                                            >
                                                ✓ Aucune rupture
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* Stocks faibles */}
                    <div className="rounded-2xl border border-orange-200 bg-white p-5 shadow-sm dark:border-orange-900/30 dark:bg-gray-800">
                        <div className="mb-4 flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <TriangleAlert
                                    size={16}
                                    className="text-orange-500"
                                />
                                <h2 className="font-bold text-gray-800 dark:text-white">
                                    Stocks faibles (≤ 5)
                                </h2>
                                <span className="rounded-full bg-orange-100 px-2 py-0.5 text-xs font-bold text-orange-700 dark:bg-orange-900/30 dark:text-orange-400">
                                    {stats?.stock_faible ?? 0}
                                </span>
                            </div>
                            <button
                                onClick={() =>
                                    router.visit(
                                        route('stocks.index') + '?max_qte=6',
                                    )
                                }
                                className="text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400"
                            >
                                Voir tout →
                            </button>
                        </div>
                        <div className="overflow-hidden rounded-xl border border-gray-100 dark:border-gray-700">
                            <table className="w-full text-sm">
                                <thead className="bg-orange-50 dark:bg-orange-900/10">
                                    <tr>
                                        <th className="px-3 py-2 text-left text-xs font-semibold text-orange-400 uppercase">
                                            Article
                                        </th>
                                        <th className="px-3 py-2 text-right text-xs font-semibold text-orange-400 uppercase">
                                            Qté
                                        </th>
                                        <th className="px-3 py-2 text-right text-xs font-semibold text-orange-400 uppercase">
                                            Prix U.
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50 dark:divide-gray-700">
                                    {(stocksFaibles ?? []).map((s: any) => (
                                        <tr
                                            key={s.Code}
                                            className="transition-colors hover:bg-orange-50/50 dark:hover:bg-orange-900/10"
                                        >
                                            <td className="px-3 py-2">
                                                <p className="max-w-[200px] truncate font-medium text-gray-800 dark:text-gray-200">
                                                    {s.Liblong}
                                                </p>
                                                <p className="font-mono text-xs text-gray-400">
                                                    {s.Code}
                                                </p>
                                            </td>
                                            <td className="px-3 py-2 text-right font-bold text-orange-600 dark:text-orange-400">
                                                {fmtFull(s.QuantiteStock)}
                                            </td>
                                            <td className="px-3 py-2 text-right text-gray-500">
                                                {fmtFull(s.PrixU)} Ar
                                            </td>
                                        </tr>
                                    ))}
                                    {!(stocksFaibles ?? []).length && (
                                        <tr>
                                            <td
                                                colSpan={3}
                                                className="py-5 text-center text-sm text-gray-400"
                                            >
                                                ✓ Tous les stocks sont
                                                suffisants
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

// ── StatCard — identique à stocks/index ──────────────────────────────────────
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
    const bg: Record<string, string> = {
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

// ── MiniKpi ───────────────────────────────────────────────────────────────────
function MiniKpi({
    label,
    value,
    highlight = false,
    color = 'bordeaux',
}: {
    label: string;
    value: string;
    highlight?: boolean;
    color?: 'bordeaux' | 'teal';
}) {
    const highlightColor =
        color === 'teal'
            ? 'border-teal-200 bg-teal-50 dark:border-teal-800 dark:bg-teal-900/20'
            : 'border-[#7a1a2e]/20 bg-[#7a1a2e]/5 dark:border-red-900/30 dark:bg-red-900/10';
    const valueColor =
        color === 'teal'
            ? 'text-teal-700 dark:text-teal-400'
            : 'text-[#7a1a2e] dark:text-red-400';

    return (
        <div
            className={`rounded-xl border p-3 ${highlight ? highlightColor : 'border-gray-100 bg-gray-50 dark:border-gray-700 dark:bg-gray-700/30'}`}
        >
            <p className="text-xs text-gray-400 dark:text-gray-500">{label}</p>
            <p
                className={`mt-1 text-sm font-black tabular-nums ${highlight ? valueColor : 'text-gray-800 dark:text-gray-200'}`}
            >
                {value}
            </p>
        </div>
    );
}
