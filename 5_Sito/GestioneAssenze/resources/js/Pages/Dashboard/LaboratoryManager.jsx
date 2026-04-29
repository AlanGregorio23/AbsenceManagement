import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardStatCard from '@/Components/DashboardStatCard';
import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const openStatuses = new Set([
    'In valutazione',
    'Documentazione richiesta',
    'In attesa firma tutore',
    'Firmata',
    'Override firma tutore',
    'Approvata',
]);

const fallbackStats = [
    { label: 'Richieste da valutare', value: '0', helper: 'Settimana' },
    { label: 'In attesa firma', value: '0', helper: 'Tutori' },
    { label: 'Attesa documenti', value: '0', helper: 'Priorita alta' },
    { label: 'Senza firma', value: '0', helper: 'Scadute' },
];

const fallbackRows = [
    {
        id: 'C-0001',
        leave_id: 1,
        studente: 'Giulia Verdi',
        classe: 'INF4A',
        periodo: '04-06 Feb',
        motivo: 'Visita medica',
        destinazione: 'Lugano',
        firma_tutore_label: 'Assente',
        conteggio_40_ore: true,
        conteggio_40_ore_label: 'Da calcolare a pratica conclusa',
        stato: 'In valutazione',
        badge: 'bg-indigo-100 text-indigo-700',
        data: '04 Feb 2026',
        richiesta_inviata_il: '03 Feb 2026 10:15',
        richiesta_tardiva: false,
        richiesta_tardiva_label: '',
        can_pre_approve: true,
        can_approve: true,
        can_request_documentation: true,
        can_reject: true,
    },
    {
        id: 'C-0002',
        leave_id: 2,
        studente: 'Andrea Galli',
        classe: 'INF4B',
        periodo: '02-03 Feb',
        motivo: 'Motivi familiari',
        destinazione: 'Milano',
        firma_tutore_label: 'Firmato da Anna Galli (03 Feb 2026 09:22)',
        conteggio_40_ore: false,
        conteggio_40_ore_label: 'Da calcolare a pratica conclusa',
        stato: 'Documentazione richiesta',
        badge: 'bg-fuchsia-100 text-fuchsia-700',
        data: '03 Feb 2026',
        richiesta_inviata_il: '02 Feb 2026 18:30',
        richiesta_tardiva: true,
        richiesta_tardiva_label: 'Inviata oltre il termine minimo di 24 ore lavorative',
        can_pre_approve: true,
        can_approve: true,
        can_request_documentation: false,
        can_reject: true,
    },
    {
        id: 'C-0003',
        leave_id: 3,
        studente: 'Laura Neri',
        classe: 'INF3C',
        periodo: '28-29 Gen',
        motivo: 'Esame esterno',
        destinazione: 'Zurigo',
        firma_tutore_label: 'Firmato da Marco Neri (29 Jan 2026 12:14)',
        conteggio_40_ore: true,
        conteggio_40_ore_label: 'Da calcolare a pratica conclusa',
        stato: 'Override firma tutore',
        badge: 'bg-yellow-100 text-emerald-700',
        data: '29 Jan 2026',
        richiesta_inviata_il: '28 Jan 2026 08:40',
        richiesta_tardiva: false,
        richiesta_tardiva_label: '',
        can_pre_approve: false,
        can_approve: true,
        can_request_documentation: true,
        can_reject: true,
    },
];
const statDecorations = [
    { icon: 'requests', tone: 'sky' },
    { icon: 'signature', tone: 'amber' },
    { icon: 'warning', tone: 'violet' },
    { icon: 'docs', tone: 'emerald' },
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

const LeaveOpenButton = ({ row }) => (
    <Link
        href={route('leaves.show', row.leave_id)}
        className="btn-soft-primary whitespace-nowrap"
    >
        Apri pratica
    </Link>
);

const LeaveOperationButtons = ({ row }) => {
    const actions = leaveActionLabels(row);
    const editAction = actions.find((action) => action.key === 'edit') ?? null;
    const deleteAction = actions.find((action) => action.key === 'delete') ?? null;

    if (!editAction && !deleteAction) {
        return <span className="text-xs text-slate-400">-</span>;
    }

    return (
        <div className="inline-flex items-center justify-center gap-1.5 whitespace-nowrap">
            {editAction && (
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
            )}
            {deleteAction && (
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
            )}
        </div>
    );
};

export default function LaboratoryManagerDashboard({
    stats = fallbackStats,
    rows = fallbackRows,
}) {
    const [query, setQuery] = useState('');

    const filteredRows = useMemo(() => {
        const normalizedQuery = query.trim().toLowerCase();

        return rows.filter((row) => {
            if (!openStatuses.has(row.stato)) {
                return false;
            }

            if (normalizedQuery.length === 0) {
                return true;
            }

            return (
                row.studente.toLowerCase().includes(normalizedQuery) ||
                row.classe.toLowerCase().includes(normalizedQuery)
            );
        });
    }, [rows, query]);

    return (
        <AuthenticatedLayout header="Dashboard">
            <Head title="Dashboard Capo Laboratorio" />

            <div className="space-y-6">
                <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    {stats.map((stat, index) => (
                        <DashboardStatCard
                            key={stat.label}
                            label={stat.label}
                            value={stat.value}
                            helper={stat.helper}
                            icon={statDecorations[index % statDecorations.length].icon}
                            tone={statDecorations[index % statDecorations.length].tone}
                        />
                    ))}
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="flex items-center justify-between">
                        <div>
                            <h3 className="text-lg font-semibold text-slate-900">
                                Richieste congedo aperte
                            </h3>
                            <p className="text-sm text-slate-500">
                                Dashboard operativa: qui vedi solo richieste su cui puoi ancora intervenire.
                            </p>
                        </div>
                        <div className="flex gap-2">
                            <Link
                                href={route('lab.leaves.create')}
                                className="btn-soft-neutral px-4 text-sm"
                            >
                                Nuovo congedo
                            </Link>
                            <input
                                className="h-9 rounded-lg border border-slate-200 px-3 text-sm"
                                placeholder="Cerca..."
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                            />
                            <button
                                className="btn-soft-neutral px-4 text-sm"
                                onClick={() => setQuery('')}
                                type="button"
                            >
                                Reset
                            </button>
                        </div>
                    </div>

                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full min-w-[1420px] text-sm">
                            <thead className="text-xs uppercase tracking-wide text-slate-400">
                                <tr>
                                    <th className="px-3 py-3 text-center align-middle">ID</th>
                                    <th className="px-3 py-3 text-center align-middle">Studente</th>
                                    <th className="px-3 py-3 text-center align-middle">Classe</th>
                                    <th className="px-3 py-3 text-center align-middle">Periodo</th>
                                    <th className="px-3 py-3 text-center align-middle">Motivo</th>
                                    <th className="px-3 py-3 text-center align-middle">Destinazione</th>
                                    <th className="px-3 py-3 text-center align-middle">Richiesta inviata</th>
                                    <th className="px-3 py-3 text-center align-middle">Stato</th>
                                    <th className="w-[6rem] px-3 py-3 text-center align-middle">Operazioni</th>
                                    <th className="w-[9rem] px-3 py-3 text-center align-middle">Pratica</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {filteredRows.length === 0 && (
                                    <tr>
                                        <td
                                            className="px-3 py-6 text-center text-sm text-slate-400"
                                            colSpan={10}
                                        >
                                            Nessuna richiesta aperta.
                                        </td>
                                    </tr>
                                )}
                                {filteredRows.map((row, index) => (
                                        <tr key={`${row.id}-${index}`} className="transition-colors hover:bg-slate-50/60">
                                            <td className="px-3 py-3 text-center align-middle font-medium text-slate-800 whitespace-nowrap">
                                                {row.id}
                                            </td>
                                            <td className="px-3 py-3 text-center align-middle font-medium text-slate-800 whitespace-nowrap">
                                                {row.student_id ? (
                                                    <Link
                                                        href={route('students.profile.show', row.student_id)}
                                                        className="text-slate-900 underline decoration-dotted underline-offset-2 transition-colors hover:text-indigo-700 hover:decoration-indigo-500"
                                                    >
                                                        {row.studente}
                                                    </Link>
                                                ) : (
                                                    row.studente
                                                )}
                                            </td>
                                            <td className="px-3 py-3 text-center align-middle">{row.classe}</td>
                                            <td className="px-3 py-3 text-center align-middle">{row.periodo}</td>
                                            <td className="px-3 py-3 text-center align-middle">
                                                <span
                                                    className="mx-auto block max-w-[15rem] truncate"
                                                    title={row.motivo ?? '-'}
                                                >
                                                    {row.motivo ?? '-'}
                                                </span>
                                            </td>
                                            <td className="px-3 py-3 text-center align-middle">
                                                {row.destinazione || row.destination || '-'}
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
                                                <LeaveOperationButtons row={row} />
                                            </td>
                                            <td className="px-3 py-3 text-center align-middle">
                                                <LeaveOpenButton row={row} />
                                            </td>
                                        </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
