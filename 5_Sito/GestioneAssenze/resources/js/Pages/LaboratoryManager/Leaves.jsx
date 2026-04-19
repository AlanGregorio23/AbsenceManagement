import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const fallbackItems = [];

const statusOptions = [
    'Tutti',
    'In attesa firma tutore',
    'Firmata',
    'Override firma tutore',
    'Documentazione richiesta',
    'In valutazione',
    'Approvata',
    'Inoltrata in direzione',
];

const ActionGlyph = ({ actionKey, className = 'h-3.5 w-3.5' }) => {
    if (actionKey === 'edit') {
        return (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className={className}>
                <path d="M4 20h4l10-10-4-4L4 16v4zM13 7l4 4M15 5l2-2 4 4-2 2" />
            </svg>
        );
    }

    if (actionKey === 'delete') {
        return (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className={className}>
                <path d="M4 7h16M10 11v6M14 11v6M6 7l1 12h10l1-12M9 7V4h6v3" />
            </svg>
        );
    }

    return null;
};

const leaveActionLabels = (row) => {
    const labels = [];

    if (row.can_edit) {
        labels.push({
            key: 'edit',
            label: 'Modifica',
        });
    }
    if (row.can_delete) {
        labels.push({
            key: 'delete',
            label: 'Elimina',
        });
    }

    return labels;
};

export default function LaboratoryManagerLeaves({ items = fallbackItems, role = '' }) {
    const [query, setQuery] = useState('');
    const [statusFilter, setStatusFilter] = useState('Tutti');
    const [showLatePopup, setShowLatePopup] = useState(false);
    const safeItems = Array.isArray(items) ? items : Object.values(items ?? {});
    const lateItems = useMemo(
        () => safeItems.filter((row) => Boolean(row?.richiesta_tardiva)),
        [safeItems]
    );

    useEffect(() => {
        setShowLatePopup(lateItems.length > 0);
    }, [lateItems.length]);

    const filteredItems = useMemo(() => {
        const normalizedQuery = query.trim().toLowerCase();

        return safeItems.filter((row) => {
            const matchStatus =
                statusFilter === 'Tutti' || row.stato === statusFilter;
            const matchQuery =
                normalizedQuery.length === 0 ||
                (row.studente ?? '').toLowerCase().includes(normalizedQuery) ||
                (row.id ?? '').toLowerCase().includes(normalizedQuery) ||
                (row.classe ?? '').toLowerCase().includes(normalizedQuery);

            return matchStatus && matchQuery;
        });
    }, [safeItems, query, statusFilter]);

    const pageTitle = role === 'teacher' ? 'Congedi Docente' : 'Congedi Capo Laboratorio';

    return (
        <AuthenticatedLayout header="Richieste congedo">
            <Head title={pageTitle} />

            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-900">
                            Richieste congedo aperte
                        </h2>
                        <p className="text-sm text-slate-500">
                            Gestione firma tutore, eccezioni 40 ore e approvazioni.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Link
                            href={route('lab.leaves.create')}
                            className="btn-soft-neutral whitespace-nowrap"
                        >
                            Nuovo congedo
                        </Link>
                        <input
                            className="h-9 rounded-xl border border-slate-200 px-3 text-sm"
                            placeholder="Cerca studente o classe"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                        />
                        <select
                            className="h-9 rounded-xl border border-slate-200 px-3 text-sm"
                            value={statusFilter}
                            onChange={(event) => setStatusFilter(event.target.value)}
                        >
                            {statusOptions.map((option) => (
                                <option key={option}>{option}</option>
                            ))}
                        </select>
                    </div>
                </div>

                <div className="mt-4 overflow-x-auto">
                    <table className="w-full min-w-[1540px] text-sm">
                        <thead className="text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th className="px-3 py-3 text-center align-middle">ID</th>
                                <th className="px-3 py-3 text-center align-middle">Studente</th>
                                <th className="px-3 py-3 text-center align-middle">Classe</th>
                                <th className="px-3 py-3 text-center align-middle">Periodo</th>
                                <th className="px-3 py-3 text-center align-middle">Motivo</th>
                                <th className="px-3 py-3 text-center align-middle">Destinazione</th>
                                <th className="px-3 py-3 text-center align-middle">Firma tutore</th>
                                <th className="px-3 py-3 text-center align-middle">Inviata il</th>
                                <th className="px-3 py-3 text-center align-middle">Stato</th>
                                <th className="w-[28rem] px-3 py-3 text-center align-middle">Azioni</th>
                                <th className="w-[9rem] px-3 py-3 text-center align-middle">Operazioni</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {filteredItems.length === 0 && (
                                <tr>
                                    <td
                                        className="px-3 py-6 text-center text-sm text-slate-400"
                                        colSpan={11}
                                    >
                                        Nessuna richiesta aperta.
                                    </td>
                                </tr>
                            )}
                            {filteredItems.map((row) => (
                                <tr key={row.id} className="text-slate-600">
                                    <td className="px-3 py-3 text-center align-middle font-medium text-slate-800">
                                        {row.id}
                                    </td>
                                    <td className="px-3 py-3 text-center align-middle whitespace-nowrap">{row.studente}</td>
                                    <td className="px-3 py-3 text-center align-middle">{row.classe}</td>
                                    <td className="px-3 py-3 text-center align-middle">{row.periodo}</td>
                                    <td className="px-3 py-3 text-center align-middle">
                                        <span
                                            className="mx-auto block max-w-[14rem] truncate"
                                            title={row.motivo ?? '-'}
                                        >
                                            {row.motivo ?? '-'}
                                        </span>
                                    </td>
                                    <td className="px-3 py-3 text-center align-middle">
                                        {row.destinazione || row.destination || '-'}
                                    </td>
                                    <td className="px-3 py-3 text-center align-middle text-xs">
                                        {row.firma_tutore_label}
                                    </td>
                                    <td className="px-3 py-3 text-center align-middle text-xs whitespace-nowrap">
                                        <div className="flex flex-col items-center gap-1">
                                            <span>{row.richiesta_inviata_il || '-'}</span>
                                            {row.richiesta_tardiva && (
                                                <span className="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold text-rose-700">
                                                    Tardiva
                                                </span>
                                            )}
                                        </div>
                                    </td>
                                    <td className="px-3 py-3 text-center align-middle">
                                        <span
                                            className={`rounded-full px-3 py-1 text-xs font-semibold whitespace-nowrap ${row.badge}`}
                                        >
                                            {row.stato}
                                        </span>
                                    </td>
                                    <td className="px-3 py-3 text-center align-middle">
                                        <Link
                                            href={route('leaves.show', row.leave_id)}
                                            className="btn-soft-neutral whitespace-nowrap"
                                        >
                                            Apri pratica
                                        </Link>
                                    </td>
                                    <td className="px-3 py-3 text-center align-middle">
                                        {(() => {
                                            const actions = leaveActionLabels(row);
                                            const editAction = actions.find((action) => action.key === 'edit') ?? null;
                                            const deleteAction = actions.find((action) => action.key === 'delete') ?? null;

                                            if (!editAction && !deleteAction) {
                                                return <span className="text-xs text-slate-400">-</span>;
                                            }

                                            return (
                                                <div className="inline-flex shrink-0 items-center gap-2">
                                                    {editAction ? (
                                                        <Link
                                                            href={route('leaves.show', {
                                                                leave: row.leave_id,
                                                                action: editAction.key,
                                                            })}
                                                            title={editAction.label}
                                                            aria-label={editAction.label}
                                                            className="btn-soft-icon"
                                                        >
                                                            <ActionGlyph actionKey="edit" className="h-4 w-4" />
                                                        </Link>
                                                    ) : (
                                                        <span className="h-9 w-9" aria-hidden="true" />
                                                    )}
                                                    {deleteAction ? (
                                                        <Link
                                                            href={route('leaves.show', {
                                                                leave: row.leave_id,
                                                                action: deleteAction.key,
                                                            })}
                                                            title={deleteAction.label}
                                                            aria-label={deleteAction.label}
                                                            className="btn-soft-icon-danger"
                                                        >
                                                            <ActionGlyph actionKey="delete" className="h-4 w-4" />
                                                        </Link>
                                                    ) : (
                                                        <span className="h-9 w-9" aria-hidden="true" />
                                                    )}
                                                </div>
                                            );
                                        })()}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>

            {showLatePopup && lateItems.length > 0 && (
                <div className="fixed bottom-4 right-4 z-40 w-full max-w-sm rounded-2xl border border-rose-200 bg-white p-4 shadow-2xl">
                    <p className="text-sm font-semibold text-rose-700">
                        Attenzione: richieste congedo tardive
                    </p>
                    <p className="mt-1 text-xs text-slate-600">
                        Trovate {lateItems.length} richieste inviate oltre il termine minimo di ore lavorative.
                    </p>
                    <p className="mt-2 text-[11px] text-slate-500">
                        {lateItems
                            .slice(0, 3)
                            .map((item) => `${item.id} (${item.studente})`)
                            .join(' | ')}
                    </p>
                    <button
                        type="button"
                        className="mt-3 rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                        onClick={() => setShowLatePopup(false)}
                    >
                        Chiudi
                    </button>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
