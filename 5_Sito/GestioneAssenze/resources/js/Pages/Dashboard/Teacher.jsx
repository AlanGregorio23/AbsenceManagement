import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardStatCard from '@/Components/DashboardStatCard';
import { Head, Link } from '@inertiajs/react';

const fallbackStats = [
    { label: 'Richieste aperte', value: '0', helper: 'Classi 0' },
    { label: 'In attesa firma', value: '0', helper: 'Assenze da confermare' },
    { label: 'Scadenze prossime', value: '0', helper: 'Entro 48 ore' },
    { label: 'Scadute', value: '0', helper: 'Arbitrarie da prorogare' },
];

const fallbackRows = [];
const statDecorations = [
    { icon: 'requests', tone: 'sky' },
    { icon: 'clock', tone: 'indigo' },
    { icon: 'calendar', tone: 'emerald' },
    { icon: 'warning', tone: 'rose' },
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

const requestOperationLabels = (row) => {
    const labels = [];

    if (row.tipo === 'Assenza') {
        if (row.can_edit_absence) {
            labels.push({
                key: 'edit',
                label: 'Modifica assenza',
            });
        }
        if (row.can_delete_absence) {
            labels.push({
                key: 'delete',
                label: 'Elimina',
            });
        }
    }

    if (row.tipo === 'Ritardo') {
        if (row.can_edit_delay) {
            labels.push({
                key: 'edit',
                label: 'Modifica ritardo',
            });
        }
        if (row.can_delete_delay) {
            labels.push({
                key: 'delete',
                label: 'Elimina',
            });
        }
    }

    return labels;
};

const requestHref = (row, actionKey = null) => {
    const params = actionKey ? { action: actionKey } : {};

    if (row.tipo === 'Assenza') {
        return route('teacher.absences.show', {
            absence: row.absence_id,
            ...params,
        });
    }

    return route('teacher.delays.show', {
        delay: row.delay_id,
        ...params,
    });
};

const RequestOpenButton = ({ row }) => (
    <Link
        href={requestHref(row)}
        className="btn-soft-primary whitespace-nowrap"
    >
        Apri pratica
    </Link>
);

const RequestOperationButtons = ({ row }) => {
    const operations = requestOperationLabels(row);
    const editAction = operations.find((action) => action.key === 'edit') ?? null;
    const deleteAction = operations.find((action) => action.key === 'delete') ?? null;

    if (!editAction && !deleteAction) {
        return <span className="text-xs text-slate-400">-</span>;
    }

    return (
        <div className="inline-flex items-center justify-center gap-1.5 whitespace-nowrap">
            {editAction && (
                <Link
                    href={requestHref(row, editAction.key)}
                    title={editAction.label}
                    aria-label={editAction.label}
                    className="btn-soft-icon"
                >
                    <ActionGlyph actionKey={editAction.key} className="h-4 w-4" />
                </Link>
            )}
            {deleteAction && (
                <Link
                    href={requestHref(row, deleteAction.key)}
                    title={deleteAction.label}
                    aria-label={deleteAction.label}
                    className="btn-soft-icon-danger"
                >
                    <ActionGlyph actionKey={deleteAction.key} className="h-4 w-4" />
                </Link>
            )}
        </div>
    );
};

export default function TeacherDashboard({
    stats = fallbackStats,
    rows = fallbackRows,
}) {
    return (
        <AuthenticatedLayout header="Dashboard">
            <Head title="Dashboard Docente" />

            <div className="space-y-6">
                <section className="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
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

                <section className="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <div className="flex items-center justify-between">
                        <h3 className="text-lg font-semibold text-slate-900">
                            Richieste da gestire
                        </h3>
                    </div>

                    <div className="mt-4 space-y-3 2xl:hidden">
                        {rows.length === 0 && (
                            <div className="rounded-xl border border-dashed border-slate-200 px-4 py-6 text-center text-sm text-slate-400">
                                Nessuna richiesta da gestire.
                            </div>
                        )}

                        {rows.map((row, index) => (
                            <article
                                key={`${row.studente}-${index}-card`}
                                className="rounded-xl border border-slate-200 bg-white p-4"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p className="text-sm text-slate-400">ID</p>
                                        <p className="font-semibold text-slate-900">
                                            {row.id || '-'}
                                        </p>
                                    </div>
                                    <span
                                        className={`rounded-full px-3 py-1 text-xs font-semibold ${row.badge}`}
                                    >
                                        {row.stato}
                                    </span>
                                </div>

                                <div className="mt-3 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <p className="text-slate-400">Studente</p>
                                        <Link
                                            href={route('students.profile.show', row.student_id)}
                                            className="font-medium text-slate-900 underline decoration-dotted underline-offset-2 transition-colors hover:text-indigo-700 hover:decoration-indigo-500"
                                        >
                                            {row.studente}
                                        </Link>
                                    </div>
                                    <div>
                                        <p className="text-slate-400">Classe</p>
                                        <p className="text-slate-700">{row.classe ?? '-'}</p>
                                    </div>
                                    <div>
                                        <p className="text-slate-400">Tipo</p>
                                        <p className="text-slate-700">{row.tipo}</p>
                                    </div>
                                    <div>
                                        <p className="text-slate-400">Tempo</p>
                                        <p className="text-slate-700">{row.durata}</p>
                                    </div>
                                    <div>
                                        <p className="text-slate-400">Data</p>
                                        <p className="text-slate-700">{row.data}</p>
                                    </div>
                                    <div>
                                        <p className="text-slate-400">Scadenza</p>
                                        <p className="text-slate-700">{row.scadenza}</p>
                                    </div>
                                    <div className="sm:col-span-2 lg:col-span-3">
                                        <p className="text-slate-400">Obbligo certificato</p>
                                        {row.tipo === 'Assenza' ? (
                                            <span
                                                className={`mt-1 inline-flex rounded-full px-3 py-1 text-xs font-semibold ${row.certificato_obbligo_badge ?? 'bg-slate-100 text-slate-700'}`}
                                            >
                                                {row.certificato_obbligo_short ??
                                                    'Non richiesto'}
                                            </span>
                                        ) : (
                                            <p className="text-slate-400">-</p>
                                        )}
                                    </div>
                                </div>

                                <div className="mt-4 flex flex-wrap items-center justify-end gap-2">
                                    <RequestOperationButtons row={row} />
                                    <RequestOpenButton row={row} />
                                </div>
                            </article>
                        ))}
                    </div>

                    <div className="mt-4 hidden min-w-0 overflow-x-auto 2xl:block">
                        <table className="w-full min-w-[1240px] text-sm">
                            <thead className="text-xs uppercase tracking-wide text-slate-400">
                                <tr>
                                    <th className="px-3 py-3 text-center align-middle">ID</th>
                                    <th className="px-3 py-3 text-center align-middle">Studente</th>
                                    <th className="px-3 py-3 text-center align-middle">Classe</th>
                                    <th className="px-3 py-3 text-center align-middle">Tipo</th>
                                    <th className="px-3 py-3 text-center align-middle">Tempo</th>
                                    <th className="px-3 py-3 text-center align-middle">Data</th>
                                    <th className="px-3 py-3 text-center align-middle">Scadenza</th>
                                    <th className="px-3 py-3 text-center align-middle">Obbligo certificato</th>
                                    <th className="px-3 py-3 text-center align-middle">Stato</th>
                                    <th className="w-[6rem] px-3 py-3 text-center align-middle">Operazioni</th>
                                    <th className="w-[9rem] px-3 py-3 text-center align-middle">Pratica</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.length === 0 && (
                                    <tr>
                                        <td
                                            className="px-3 py-6 text-center text-sm text-slate-400"
                                            colSpan={11}
                                        >
                                            Nessuna richiesta da gestire.
                                        </td>
                                    </tr>
                                )}

                                {rows.map((row, index) => (
                                    <tr key={`${row.studente}-${index}`} className="transition-colors hover:bg-slate-50/60">
                                        <td className="px-3 py-3 text-center align-middle font-medium text-slate-800 whitespace-nowrap">
                                            {row.id || '-'}
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle font-medium text-slate-800">
                                            <Link
                                                href={route('students.profile.show', row.student_id)}
                                                className="text-slate-900 underline decoration-dotted underline-offset-2 transition-colors hover:text-indigo-700 hover:decoration-indigo-500"
                                            >
                                                {row.studente}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle">{row.classe ?? '-'}</td>
                                        <td className="px-3 py-3 text-center align-middle">
                                            {row.tipo}
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle">{row.durata}</td>
                                        <td className="px-3 py-3 text-center align-middle whitespace-nowrap">{row.data}</td>
                                        <td className="px-3 py-3 text-center align-middle whitespace-nowrap">{row.scadenza}</td>
                                        <td className="px-3 py-3 text-center align-middle">
                                            {row.tipo === 'Assenza' ? (
                                                <span
                                                    className={`rounded-full px-3 py-1 text-xs font-semibold whitespace-nowrap ${row.certificato_obbligo_badge ?? 'bg-slate-100 text-slate-700'}`}
                                                >
                                                    {row.certificato_obbligo_short ??
                                                        'Non richiesto'}
                                                </span>
                                            ) : (
                                                <span className="text-xs text-slate-400">
                                                    -
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle">
                                            <span
                                                className={`rounded-full px-3 py-1 text-xs font-semibold whitespace-nowrap ${row.badge}`}
                                            >
                                                {row.stato}
                                            </span>
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle">
                                            <RequestOperationButtons row={row} />
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle">
                                            <RequestOpenButton row={row} />
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
