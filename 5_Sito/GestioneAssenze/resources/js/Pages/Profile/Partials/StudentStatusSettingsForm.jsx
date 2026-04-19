import PrimaryButton from '@/Components/PrimaryButton';
import { Transition } from '@headlessui/react';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

export default function StudentStatusSettingsForm({
    settings = null,
    status,
    className = '',
}) {
    const isEnabled = Boolean(settings);
    const { data, setData, patch, processing, recentlySuccessful, errors } = useForm({
        absence_warning_percent: settings?.absence_warning_percent ?? 80,
        absence_critical_percent: settings?.absence_critical_percent ?? 100,
        delay_warning_percent: settings?.delay_warning_percent ?? 80,
        delay_critical_percent: settings?.delay_critical_percent ?? 100,
    });

    useEffect(() => {
        if (!settings) {
            return;
        }

        setData('absence_warning_percent', settings.absence_warning_percent ?? 80);
        setData('absence_critical_percent', settings.absence_critical_percent ?? 100);
        setData('delay_warning_percent', settings.delay_warning_percent ?? 80);
        setData('delay_critical_percent', settings.delay_critical_percent ?? 100);
    }, [settings, setData]);

    if (!isEnabled) {
        return null;
    }

    const submit = (event) => {
        event.preventDefault();
        patch(route('profile.student-status.update'));
    };

    const updateField = (field, value) => {
        const parsed = Number(value);
        setData(field, Number.isFinite(parsed) ? parsed : 0);
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-gray-900">
                    Stato allievi
                </h2>
                <p className="mt-1 text-sm text-slate-600">
                    Soglie personali per pallini giallo/rosso in vista studenti.
                </p>
            </header>

            <form onSubmit={submit} className="mt-6 space-y-4">
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="flex flex-col gap-2 text-sm text-slate-700">
                        Assenze: soglia giallo (%)
                        <input
                            type="number"
                            min="1"
                            max="100"
                            value={data.absence_warning_percent}
                            onChange={(event) =>
                                updateField('absence_warning_percent', event.target.value)
                            }
                            className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                        />
                    </label>
                    <label className="flex flex-col gap-2 text-sm text-slate-700">
                        Assenze: soglia rosso (%)
                        <input
                            type="number"
                            min="1"
                            max="100"
                            value={data.absence_critical_percent}
                            onChange={(event) =>
                                updateField('absence_critical_percent', event.target.value)
                            }
                            className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                        />
                    </label>
                    <label className="flex flex-col gap-2 text-sm text-slate-700">
                        Ritardi: soglia giallo (%)
                        <input
                            type="number"
                            min="1"
                            max="100"
                            value={data.delay_warning_percent}
                            onChange={(event) =>
                                updateField('delay_warning_percent', event.target.value)
                            }
                            className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                        />
                    </label>
                    <label className="flex flex-col gap-2 text-sm text-slate-700">
                        Ritardi: soglia rosso (%)
                        <input
                            type="number"
                            min="1"
                            max="100"
                            value={data.delay_critical_percent}
                            onChange={(event) =>
                                updateField('delay_critical_percent', event.target.value)
                            }
                            className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                        />
                    </label>
                </div>

                {(errors.absence_warning_percent
                    || errors.absence_critical_percent
                    || errors.delay_warning_percent
                    || errors.delay_critical_percent) && (
                    <p className="text-sm text-rose-600">
                        {errors.absence_warning_percent
                            || errors.absence_critical_percent
                            || errors.delay_warning_percent
                            || errors.delay_critical_percent}
                    </p>
                )}

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing}>
                        Salva soglie stato
                    </PrimaryButton>

                    <Transition
                        show={recentlySuccessful || status === 'student-status-settings-updated'}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm text-gray-600">Soglie salvate.</p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
