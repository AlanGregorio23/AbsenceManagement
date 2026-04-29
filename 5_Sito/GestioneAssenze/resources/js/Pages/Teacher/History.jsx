import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const fallbackItems = [];
const typeOptions = ['Tutti', 'Assenza', 'Ritardo'];
const baseBadge = 'bg-slate-100 text-slate-700';

const resolveRequestHref = (row) => {
    if (row.tipo === 'Assenza' && row.absence_id) {
        return route('teacher.absences.show', row.absence_id);
    }

    if (row.tipo === 'Ritardo' && row.delay_id) {
        return route('teacher.delays.show', row.delay_id);
    }

    return null;
};

export default function TeacherHistory({ items = fallbackItems }) {
    const [query, setQuery] = useState('');
    const [typeFilter, setTypeFilter] = useState('Tutti');
    const safeItems = Array.isArray(items) ? items : Object.values(items ?? {});

    const filteredItems = useMemo(() => {
        const normalizedQuery = query.trim().toLowerCase();

        return safeItems.filter((row) => {
            const matchType =
                typeFilter === 'Tutti' || row.tipo === typeFilter;
            const matchQuery =
                normalizedQuery.length === 0 ||
                String(row.id ?? '').toLowerCase().includes(normalizedQuery) ||
                String(row.studente ?? '').toLowerCase().includes(normalizedQuery) ||
                String(row.classe ?? '').toLowerCase().includes(normalizedQuery);

            return matchType && matchQuery;
        });
    }, [safeItems, query, typeFilter]);

    return (
        <AuthenticatedLayout header="Storico">
            <Head title="Storico Docenti" />

            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-900">
                            Storico richieste
                        </h2>
                        <p className="text-sm text-slate-500">
                            Mostra solo richieste gia gestite e chiuse.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <input
                            className="h-9 rounded-xl border border-slate-200 px-3 text-sm"
                            placeholder="Cerca ID, studente o classe"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                        />
                        <select
                            className="h-9 rounded-xl border border-slate-200 px-3 text-sm"
                            value={typeFilter}
                            onChange={(event) =>
                                setTypeFilter(event.target.value)
                            }
                        >
                            {typeOptions.map((option) => (
                                <option key={option}>{option}</option>
                            ))}
                        </select>
                    </div>
                </div>

                <div className="mt-4 overflow-x-auto">
                    <table className="w-full min-w-[980px] text-sm">
                        <thead className="text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th className="px-3 py-3 text-center align-middle">ID</th>
                                <th className="px-3 py-3 text-center align-middle">Studente</th>
                                <th className="px-3 py-3 text-center align-middle">Classe</th>
                                <th className="px-3 py-3 text-center align-middle">Tipo</th>
                                <th className="px-3 py-3 text-center align-middle">Durata</th>
                                <th className="px-3 py-3 text-center align-middle">Data</th>
                                <th className="px-3 py-3 text-center align-middle">Certificato</th>
                                <th className="px-3 py-3 text-center align-middle">Stato</th>
                                <th className="px-3 py-3 text-center align-middle">Azioni</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {filteredItems.length === 0 && (
                                <tr>
                                    <td
                                        className="px-3 py-6 text-center text-sm text-slate-400"
                                        colSpan={9}
                                    >
                                        Nessuna richiesta trovata.
                                    </td>
                                </tr>
                            )}
                            {filteredItems.map((row) => {
                                const requestHref = resolveRequestHref(row);

                                return (
                                    <tr key={row.id} className="text-slate-600">
                                        <td className="px-3 py-3 text-center align-middle font-medium text-slate-800">
                                            {row.id}
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle">
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
                                        <td className="px-3 py-3 text-center align-middle">{row.classe ?? '-'}</td>
                                        <td className="px-3 py-3 text-center align-middle">{row.tipo}</td>
                                        <td className="px-3 py-3 text-center align-middle">{row.durata}</td>
                                        <td className="px-3 py-3 text-center align-middle">{row.data}</td>
                                        <td className="px-3 py-3 text-center align-middle">
                                            {row.tipo === 'Assenza' ? (
                                                <span
                                                    className={`rounded-full px-3 py-1 text-xs font-semibold ${row.certificato_obbligo_badge ?? baseBadge}`}
                                                >
                                                    {row.certificato_obbligo_short ??
                                                        'Non richiesto'}
                                                </span>
                                            ) : (
                                                <span className="text-slate-400">-</span>
                                            )}
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle">
                                            <span
                                                className={`rounded-full px-3 py-1 text-xs font-semibold ${row.badge}`}
                                            >
                                                {row.stato}
                                            </span>
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle">
                                            {requestHref ? (
                                                <Link
                                                    href={requestHref}
                                                    className="btn-soft-primary whitespace-nowrap"
                                                >
                                                    Apri pratica
                                                </Link>
                                            ) : (
                                                <span className="text-slate-400">-</span>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
