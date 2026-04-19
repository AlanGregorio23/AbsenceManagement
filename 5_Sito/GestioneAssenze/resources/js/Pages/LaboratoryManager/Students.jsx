import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const fallbackRows = [
    {
        id: 'S-004',
        student_id: 4,
        nome: 'Giulia Verdi',
        classe: 'INF4A',
        email: 'giulia.verdi@example.com',
        congedi: 2,
        congedi_ore: 12,
        assenze_ore: 12,
        ritardi_registrati_semestre: 1,
        status_absence_code: 'green',
        status_delay_code: 'green',
    },
];

const dotClassByCode = {
    green: 'bg-emerald-500',
    yellow: 'bg-amber-500',
    red: 'bg-rose-500',
};

const resolveDotClass = (code) =>
    dotClassByCode[String(code ?? '').trim().toLowerCase()] ?? dotClassByCode.green;

export default function LaboratoryManagerStudents({
    items = fallbackRows,
}) {
    const [query, setQuery] = useState('');
    const [classFilter, setClassFilter] = useState('Tutte');

    const classes = useMemo(() => {
        const unique = Array.from(new Set(items.map((row) => row.classe)));
        return ['Tutte', ...unique];
    }, [items]);

    const filteredItems = useMemo(() => {
        const normalizedQuery = query.trim().toLowerCase();

        return items.filter((row) => {
            const matchClass = classFilter === 'Tutte' || row.classe === classFilter;
            const matchQuery =
                normalizedQuery.length === 0 ||
                String(row.nome ?? '').toLowerCase().includes(normalizedQuery) ||
                String(row.id ?? '').toLowerCase().includes(normalizedQuery) ||
                String(row.email ?? '').toLowerCase().includes(normalizedQuery);

            return matchClass && matchQuery;
        });
    }, [items, query, classFilter]);

    const resolveStudentId = (row) => {
        const fallbackId = parseInt(String(row.id ?? '').replace(/\D/g, ''), 10);
        const studentId = Number.isInteger(row.student_id) ? row.student_id : fallbackId;

        if (!Number.isFinite(studentId) || studentId <= 0) {
            return null;
        }

        return studentId;
    };

    return (
        <AuthenticatedLayout header="Allievi">
            <Head title="Allievi Capo Laboratorio" />

            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-900">Elenco allievi</h2>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href={`${route('profile.edit')}#stato-allievi`}
                            className="btn-soft-neutral rounded-xl"
                        >
                            Impostazioni stato
                        </Link>
                        <input
                            className="h-9 rounded-xl border border-slate-200 px-3 text-sm"
                            placeholder="Cerca nome o email"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                        />
                        <select
                            className="h-9 rounded-xl border border-slate-200 px-3 text-sm"
                            value={classFilter}
                            onChange={(event) => setClassFilter(event.target.value)}
                        >
                            {classes.map((option) => (
                                <option key={option}>{option}</option>
                            ))}
                        </select>
                    </div>
                </div>

                <div className="mt-3 overflow-x-auto">
                    <table className="w-full min-w-[980px] table-fixed text-sm">
                        <thead className="text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th className="px-3 py-3 text-center align-middle">ID</th>
                                <th className="px-3 py-3 text-center align-middle">Allievo</th>
                                <th className="px-3 py-3 text-center align-middle">Classe</th>
                                <th className="px-3 py-3 text-center align-middle">Email</th>
                                <th className="px-3 py-3 text-center align-middle">Assenze (ore)</th>
                                <th className="px-3 py-3 text-center align-middle">Ritardi registrati</th>
                                <th className="px-3 py-3 text-center align-middle">Stato assenze</th>
                                <th className="px-3 py-3 text-center align-middle">Stato ritardi</th>
                                <th className="px-3 py-3 text-center align-middle">Azioni</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {filteredItems.length === 0 && (
                                <tr>
                                    <td className="px-3 py-6 text-center text-sm text-slate-400" colSpan={9}>
                                        Nessun allievo trovato.
                                    </td>
                                </tr>
                            )}
                            {filteredItems.map((row) => {
                                const studentId = resolveStudentId(row);
                                const absenceHours = Number(row.assenze_ore ?? 0);
                                const registeredDelays = Number(row.ritardi_registrati_semestre ?? row.ritardi ?? 0);

                                return (
                                    <tr key={row.id} className="text-slate-600">
                                        <td className="px-3 py-3 text-center align-middle font-medium text-slate-800 whitespace-nowrap">{row.id}</td>
                                        <td className="px-3 py-3 text-center align-middle">
                                            <span className="mx-auto block max-w-[12rem] truncate" title={row.nome ?? '-'}>
                                                {row.nome ?? '-'}
                                            </span>
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle">
                                            <span className="mx-auto block max-w-[10rem] truncate" title={row.classe ?? '-'}>
                                                {row.classe ?? '-'}
                                            </span>
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle">
                                            <span className="mx-auto block max-w-[12rem] truncate" title={row.email ?? '-'}>
                                                {row.email ?? '-'}
                                            </span>
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle">{Number.isFinite(absenceHours) ? absenceHours : 0}</td>
                                        <td className="px-3 py-3 text-center align-middle">
                                            {Number.isFinite(registeredDelays) ? registeredDelays : 0}
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle">
                                            <span
                                                className={`inline-flex h-3 w-3 rounded-full ${resolveDotClass(row.status_absence_code)}`}
                                                aria-label="Stato assenze"
                                                title="Stato assenze"
                                            />
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle">
                                            <span
                                                className={`inline-flex h-3 w-3 rounded-full ${resolveDotClass(row.status_delay_code)}`}
                                                aria-label="Stato ritardi"
                                                title="Stato ritardi"
                                            />
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle">
                                            {studentId ? (
                                                <Link
                                                    href={route('students.profile.show', studentId)}
                                                    className="btn-soft-neutral"
                                                >
                                                    Apri profilo
                                                </Link>
                                            ) : (
                                                <span className="text-xs text-slate-400">Non disponibile</span>
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
