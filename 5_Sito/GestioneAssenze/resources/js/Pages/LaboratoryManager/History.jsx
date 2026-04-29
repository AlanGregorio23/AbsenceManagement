import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
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

const rows = [
    {
        id: 'LC-0010',
        leave_id: 10,
        studente: 'Laura Neri',
        classe: 'INF3C',
        periodo: '28-29 Jan',
        motivo: 'Motivi familiari',
        stato: 'Rifiutato',
        badge: 'bg-rose-100 text-rose-700',
        data: '29 Jan 2026',
    },
    {
        id: 'LC-0008',
        leave_id: 8,
        studente: 'Marco Conti',
        classe: 'INF4A',
        periodo: '20-21 Jan',
        motivo: 'Stage',
        stato: 'Approvato',
        badge: 'bg-emerald-100 text-emerald-700',
        data: '21 Jan 2026',
    },
    {
        id: 'LC-0007',
        leave_id: 7,
        studente: 'Giulia Verdi',
        classe: 'INF4A',
        periodo: '18-19 Jan',
        motivo: 'Progetto esterno',
        stato: 'Congedo registrato',
        badge: 'bg-emerald-100 text-emerald-700',
        data: '19 Jan 2026',
    },
];

export default function LaboratoryManagerHistory({ items = rows }) {
    const [query, setQuery] = useState('');
    const [statusFilter, setStatusFilter] = useState('Tutti');

    const filteredItems = useMemo(() => {
        const normalizedQuery = query.trim().toLowerCase();

        return items.filter((row) => {
            if (openStatuses.has(row.stato)) {
                return false;
            }

            const matchStatus =
                statusFilter === 'Tutti' || row.stato === statusFilter;
            const matchQuery =
                normalizedQuery.length === 0 ||
                row.studente.toLowerCase().includes(normalizedQuery) ||
                row.id.toLowerCase().includes(normalizedQuery) ||
                row.classe.toLowerCase().includes(normalizedQuery);

            return matchStatus && matchQuery;
        });
    }, [items, query, statusFilter]);

    const closedStatuses = useMemo(() => {
        const unique = Array.from(new Set(items.map((row) => row.stato))).filter(
            (status) => !openStatuses.has(status)
        );
        return ['Tutti', ...unique];
    }, [items]);

    return (
        <AuthenticatedLayout header="Storico">
            <Head title="Storico Capo Laboratorio" />

            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-900">
                            Storico congedi
                        </h2>
                        <p className="text-sm text-slate-500">
                            Richieste concluse che non compaiono tra le aperte.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <input
                            className="h-9 rounded-xl border border-slate-200 px-3 text-sm"
                            placeholder="Cerca studente o classe"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                        />
                        <select
                            className="h-9 rounded-xl border border-slate-200 px-3 text-sm"
                            value={statusFilter}
                            onChange={(event) =>
                                setStatusFilter(event.target.value)
                            }
                        >
                            {closedStatuses.map((status) => (
                                <option key={status}>{status}</option>
                            ))}
                        </select>
                    </div>
                </div>

                <div className="mt-4 overflow-x-auto">
                    <table className="w-full min-w-[1020px] text-sm">
                        <thead className="text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th className="px-3 py-3 text-center align-middle">ID</th>
                                <th className="px-3 py-3 text-center align-middle">Studente</th>
                                <th className="px-3 py-3 text-center align-middle">Classe</th>
                                <th className="px-3 py-3 text-center align-middle">Periodo</th>
                                <th className="px-3 py-3 text-center align-middle">Motivo</th>
                                <th className="px-3 py-3 text-center align-middle">Stato</th>
                                <th className="px-3 py-3 text-center align-middle">Data</th>
                                <th className="px-3 py-3 text-center align-middle">Azioni</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {filteredItems.length === 0 && (
                                <tr>
                                    <td
                                        className="px-3 py-6 text-center text-sm text-slate-400"
                                        colSpan={8}
                                    >
                                        Nessuna richiesta conclusa.
                                    </td>
                                </tr>
                            )}
                            {filteredItems.map((row) => (
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
                                        <span
                                            className={`rounded-full px-3 py-1 text-xs font-semibold ${row.badge}`}
                                        >
                                            {row.stato}
                                        </span>
                                    </td>
                                    <td className="px-3 py-3 text-center align-middle">{row.data}</td>
                                    <td className="px-3 py-3 text-center align-middle">
                                        {row.leave_id ? (
                                            <Link
                                                href={route('leaves.show', row.leave_id)}
                                                className="btn-soft-primary whitespace-nowrap"
                                            >
                                                Apri pratica
                                            </Link>
                                        ) : (
                                            <span className="text-slate-400">-</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
