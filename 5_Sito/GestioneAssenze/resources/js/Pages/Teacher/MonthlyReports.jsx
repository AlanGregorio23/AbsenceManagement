import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const bucketOptions = [
    { value: 'all', label: 'Tutti' },
    { value: 'missing', label: 'Mancanti' },
    { value: 'pending', label: 'Da approvare' },
    { value: 'completed', label: 'Completati' },
];

export default function TeacherMonthlyReports({ items = [], stats = {} }) {
    const [query, setQuery] = useState('');
    const [bucket, setBucket] = useState('all');

    const filtered = useMemo(() => {
        const normalizedQuery = query.trim().toLowerCase();

        return items.filter((item) => {
            const matchesBucket = bucket === 'all' || item.bucket === bucket;
            const matchesQuery =
                normalizedQuery === '' ||
                String(item.code ?? '')
                    .toLowerCase()
                    .includes(normalizedQuery) ||
                String(item.student_name ?? '')
                    .toLowerCase()
                    .includes(normalizedQuery) ||
                String(item.class_label ?? '')
                    .toLowerCase()
                    .includes(normalizedQuery) ||
                String(item.month ?? '')
                    .toLowerCase()
                    .includes(normalizedQuery);

            return matchesBucket && matchesQuery;
        });
    }, [items, query, bucket]);

    return (
        <AuthenticatedLayout header="Report mensili">
            <Head title="Report mensili docente" />

            <div className="space-y-6">
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard label="Totali" value={stats.total ?? 0} />
                    <StatCard label="Mancanti" value={stats.missing ?? 0} />
                    <StatCard label="Da approvare" value={stats.pending ?? 0} />
                    <StatCard label="Completati" value={stats.completed ?? 0} />
                </div>

                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-900">
                            Report mensili
                        </h2>
                        <div className="mt-4 grid gap-2 md:grid-cols-[minmax(0,1fr)_220px]">
                            <input
                                className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                placeholder="Cerca studente, classe o codice"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                            />
                            <select
                                className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                value={bucket}
                                onChange={(event) => setBucket(event.target.value)}
                            >
                                {bucketOptions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-xs uppercase tracking-wide text-slate-400">
                                <tr>
                                    <th className="py-3 text-center align-middle">Report</th>
                                    <th className="py-3 text-center align-middle">Studente</th>
                                    <th className="py-3 text-center align-middle">Classe</th>
                                    <th className="py-3 text-center align-middle">Mese</th>
                                    <th className="py-3 text-center align-middle">Stato</th>
                                    <th className="py-3 text-center align-middle">Data generato</th>
                                    <th className="py-3 text-center align-middle">Azioni</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {filtered.length === 0 && (
                                    <tr>
                                        <td
                                            className="py-6 text-center text-sm text-slate-400"
                                            colSpan={7}
                                        >
                                            Nessun report trovato.
                                        </td>
                                    </tr>
                                )}

                                {filtered.map((item) => (
                                    <tr key={item.report_id} className="text-slate-600">
                                        <td className="py-3 text-center align-middle font-medium text-slate-900">
                                            <Link
                                                href={item.details_url}
                                                className="underline decoration-dotted underline-offset-2 transition-colors hover:text-indigo-700 hover:decoration-indigo-500"
                                            >
                                                {item.code}
                                            </Link>
                                        </td>
                                        <td className="py-3 text-center align-middle">
                                            {item.student_id ? (
                                                <Link
                                                    href={route('students.profile.show', item.student_id)}
                                                    className="text-slate-900 underline decoration-dotted underline-offset-2 transition-colors hover:text-indigo-700 hover:decoration-indigo-500"
                                                >
                                                    {item.student_name}
                                                </Link>
                                            ) : (
                                                item.student_name
                                            )}
                                        </td>
                                        <td className="py-3 text-center align-middle">{item.class_label}</td>
                                        <td className="py-3 text-center align-middle">{item.month}</td>
                                        <td className="py-3 text-center align-middle">
                                            <span
                                                className={`rounded-full px-3 py-1 text-xs font-semibold ${item.badge}`}
                                            >
                                                {item.status_label}
                                            </span>
                                        </td>
                                        <td className="py-3 text-center align-middle text-xs">
                                            {item.generated_at ?? '-'}
                                        </td>
                                        <td className="py-3 text-center align-middle">
                                            <div className="inline-flex flex-nowrap items-center justify-center gap-2 overflow-x-auto whitespace-nowrap pb-1 text-xs">
                                                {item.can_resend_email && (
                                                    <Link
                                                        href={route(
                                                            'teacher.monthly-reports.resend-email',
                                                            item.report_id
                                                        )}
                                                        method="post"
                                                        as="button"
                                                        className="btn-soft-info h-8"
                                                    >
                                                        Reinvio email
                                                    </Link>
                                                )}
                                                {item.can_approve && (
                                                    <Link
                                                        href={route(
                                                            'teacher.monthly-reports.approve',
                                                            item.report_id
                                                        )}
                                                        method="post"
                                                        as="button"
                                                        className="btn-soft-neutral h-8"
                                                    >
                                                        Approva
                                                    </Link>
                                                )}
                                                {!item.can_resend_email && !item.can_approve && (
                                                    <span className="text-slate-400">-</span>
                                                )}
                                            </div>
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

function StatCard({ label, value }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
            <p className="text-xs uppercase tracking-wide text-slate-400">{label}</p>
            <p className="mt-1 text-2xl font-semibold text-slate-900">{value}</p>
        </div>
    );
}
