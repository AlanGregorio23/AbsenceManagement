import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';

const inputClass =
    'mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-800';

export default function AbsenceDraftFromLeave({ draft }) {
    const form = useForm({
        hours: Number(draft?.hours ?? 1),
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('student.absences.derived-draft.submit', draft.absence_id), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout header={`Bozza assenza ${draft?.id ?? ''}`}>
            <Head title="Bozza assenza da congedo" />

            <div className="grid gap-6 lg:grid-cols-3">
                <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-slate-400">
                            Bozza da congedo
                        </p>
                        <h1 className="text-xl font-semibold text-slate-900">
                            {draft?.id ?? 'Bozza assenza'}
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Giorni e motivazione sono bloccati. Puoi modificare solo le ore e inviare l assenza ufficiale.
                        </p>
                    </div>

                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="text-sm text-slate-700">
                                Data inizio
                                <input
                                    type="date"
                                    className={inputClass}
                                    value={draft?.start_date ?? ''}
                                    disabled
                                />
                            </label>

                            <label className="text-sm text-slate-700">
                                Data fine
                                <input
                                    type="date"
                                    className={inputClass}
                                    value={draft?.end_date ?? draft?.start_date ?? ''}
                                    disabled
                                />
                            </label>
                        </div>

                        <label className="block text-sm text-slate-700">
                            Ore
                            <input
                                type="number"
                                min="1"
                                max="200"
                                className={inputClass}
                                value={form.data.hours}
                                onChange={(event) =>
                                    form.setData('hours', Number(event.target.value || 0))
                                }
                            />
                            {form.errors.hours && (
                                <p className="mt-1 text-xs text-rose-600">{form.errors.hours}</p>
                            )}
                        </label>

                        <label className="block text-sm text-slate-700">
                            Motivazione
                            <textarea
                                rows={4}
                                className={inputClass}
                                value={draft?.motivation ?? ''}
                                disabled
                            />
                        </label>

                        {form.errors.absence && (
                            <p className="text-xs text-rose-600">{form.errors.absence}</p>
                        )}

                        <div className="flex flex-wrap justify-end gap-2">
                            <Link
                                href={route('dashboard')}
                                className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Annulla
                            </Link>
                            <button
                                type="submit"
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-indigo-300"
                                disabled={form.processing}
                            >
                                Invia assenza ufficiale
                            </button>
                        </div>
                    </form>
                </section>

                <aside className="space-y-4">
                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-sm font-semibold text-slate-800">
                            Dati congedo origine
                        </h2>
                        <dl className="mt-3 space-y-2 text-sm text-slate-600">
                            <div className="flex justify-between gap-3">
                                <dt>Congedo</dt>
                                <dd>{draft?.derived_leave_code ?? '-'}</dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt>Periodo</dt>
                                <dd>{draft?.leave_period ?? '-'}</dd>
                            </div>
                            {draft?.leave_requested_lessons_label && (
                                <div className="flex justify-between gap-3">
                                    <dt>Periodi scolastici</dt>
                                    <dd>{draft.leave_requested_lessons_label}</dd>
                                </div>
                            )}
                            <div className="flex justify-between gap-3">
                                <dt>Destinazione</dt>
                                <dd>{draft?.leave_destination ?? '-'}</dd>
                            </div>
                        </dl>
                        <p className="mt-3 text-sm text-slate-700">
                            <span className="font-semibold">Motivo congedo:</span>{' '}
                            {draft?.leave_reason ?? '-'}
                        </p>
                    </section>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
