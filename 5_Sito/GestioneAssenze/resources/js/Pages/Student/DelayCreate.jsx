import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

const pad = (value) => String(value).padStart(2, '0');
const nowDate = () => {
    const date = new Date();
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
};

export default function DelayCreate() {
    const { data, setData, post, processing, errors, reset } = useForm({
        delay_date: nowDate(),
        delay_minutes: 10,
        motivation: '',
    });

    const submitDelay = (event) => {
        event.preventDefault();

        post(route('student.delays.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset('delay_minutes', 'motivation');
                setData('delay_date', nowDate());
                setData('delay_minutes', 10);
            },
        });
    };

    return (
        <AuthenticatedLayout header="Segnala ritardi">
            <Head title="Segnala ritardi" />

            <div className="grid gap-6">
                <section className="mx-auto w-full max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-900">
                        Nuovo inserimento ritardi
                    </h2>
                    <p className="text-sm text-slate-500">
                        Inserisci data e minuti del ritardo.
                    </p>

                    <form onSubmit={submitDelay} className="mt-6 space-y-6">
                        <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_150px]">
                            <label className="text-sm text-slate-600">
                                Data
                                <input
                                    type="date"
                                    className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                    value={data.delay_date}
                                    max={nowDate()}
                                    onChange={(event) =>
                                        setData('delay_date', event.target.value)
                                    }
                                />
                                {errors.delay_date && (
                                    <p className="mt-1 text-xs text-rose-500">
                                        {errors.delay_date}
                                    </p>
                                )}
                            </label>
                            <label className="text-sm text-slate-600 md:max-w-[150px]">
                                Minuti ritardo
                                <input
                                    type="number"
                                    min="1"
                                    max="480"
                                    step="1"
                                    required
                                    className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                    value={data.delay_minutes}
                                    onChange={(event) =>
                                        setData(
                                            'delay_minutes',
                                            event.target.value === ''
                                                ? ''
                                                : Number(event.target.value)
                                        )
                                    }
                                />
                                {errors.delay_minutes && (
                                    <p className="mt-1 text-xs text-rose-500">
                                        {errors.delay_minutes}
                                    </p>
                                )}
                            </label>
                            <label className="text-sm text-slate-600 md:col-span-2">
                                Commento
                                <textarea
                                    rows="3"
                                    required
                                    className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                    placeholder="Commento obbligatorio"
                                    value={data.motivation}
                                    onChange={(event) =>
                                        setData('motivation', event.target.value)
                                    }
                                />
                                {errors.motivation && (
                                    <p className="mt-1 text-xs text-rose-500">
                                        {errors.motivation}
                                    </p>
                                )}
                            </label>
                        </div>

                        <div className="flex flex-wrap gap-3">
                            <button
                                type="submit"
                                className="rounded-xl bg-slate-900 px-5 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-400"
                                disabled={processing}
                            >
                                Registra segnalazione
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
