import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const classes = [
    {
        class_id: 1,
        id: '4A',
        corso: 'Informatica',
        studenti: 18,
    },
    {
        class_id: 2,
        id: '3B',
        corso: 'Elettronica',
        studenti: 20,
    },
    {
        class_id: 3,
        id: '2C',
        corso: 'Meccanica',
        studenti: 16,
    },
];

export default function TeacherClasses({ items = classes }) {
    return (
        <AuthenticatedLayout header="Le mie classi">
            <Head title="Classi Docenti" />

            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-semibold text-slate-900">
                        Classi assegnate
                    </h2>
                </div>

                <div className="mt-5 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th className="py-3">Classe</th>
                                <th className="py-3">Corso</th>
                                <th className="py-3">Studenti</th>
                                <th className="py-3">Azione</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-slate-700">
                            {items.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="py-6 text-center text-slate-400">
                                        Nessuna classe assegnata.
                                    </td>
                                </tr>
                            )}
                            {items.map((row) => (
                                <tr key={row.id}>
                                    <td className="py-3 font-semibold text-slate-900">
                                        {row.id}
                                    </td>
                                    <td className="py-3">{row.corso}</td>
                                    <td className="py-3">{row.studenti}</td>
                                    <td className="py-3">
                                        {row.class_id ? (
                                            <Link
                                                href={route('teacher.students', {
                                                    class_id: row.class_id,
                                                })}
                                                className="rounded-md border border-slate-300 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                            >
                                                Apri classe
                                            </Link>
                                        ) : (
                                            <span className="text-xs text-slate-400">
                                                Non disponibile
                                            </span>
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
