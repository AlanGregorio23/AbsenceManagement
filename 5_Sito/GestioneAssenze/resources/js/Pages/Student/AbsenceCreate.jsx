import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

const parseDateValue = (value) => {
    if (!value) {
        return null;
    }

    const parts = String(value).split('-').map((part) => Number(part));
    if (parts.length !== 3 || parts.some((part) => Number.isNaN(part))) {
        return null;
    }

    const [year, month, day] = parts;
    return new Date(Date.UTC(year, month - 1, day));
};

export default function AbsenceCreate({ reasons = [], settings }) {
    const today = new Date().toISOString().slice(0, 10);
    const fileInputRef = useRef(null);
    const [fileName, setFileName] = useState('Nessun file selezionato');
    const [isDragOver, setIsDragOver] = useState(false);
    const [selectedReason, setSelectedReason] = useState('');
    const { data, setData, post, processing, errors, reset } = useForm({
        start_date: '',
        end_date: '',
        hours: '',
        reason_choice: '',
        motivation_custom: '',
        motivation: '',
        document: null,
    });

    const handleReasonChange = (event) => {
        const value = String(event.target.value ?? '').trim();
        setSelectedReason(value);
        setData('reason_choice', value);

        if (value === '') {
            setData('motivation_custom', '');
            setData('motivation', '');
            return;
        }

        if (value === 'Altro') {
            const custom = String(data.motivation_custom ?? '').trim();
            setData('motivation', custom !== '' ? `Altro - ${custom}` : 'Altro');
            return;
        }

        setData('motivation_custom', '');
        setData('motivation', value);
    };

    const handleMotivationChange = (event) => {
        const value = event.target.value;
        setData('motivation_custom', value);
        setData(
            'motivation',
            value.trim() !== '' ? `Altro - ${value.trim()}` : 'Altro'
        );
    };

    const handleFileChange = (event) => {
        const file = event.target.files?.[0] ?? null;
        setFile(file);
    };

    const setFile = (file) => {
        setData('document', file);
        setFileName(file ? file.name : 'Nessun file selezionato');
    };

    const handleStartDateChange = (event) => {
        const nextStartDate = event.target.value;
        setData('start_date', nextStartDate);

        if (!nextStartDate) {
            return;
        }

        const parsedStartDate = parseDateValue(nextStartDate);
        const parsedEndDate = parseDateValue(data.end_date);

        if (!parsedEndDate || (parsedStartDate && parsedEndDate < parsedStartDate)) {
            setData('end_date', nextStartDate);
        }
    };

    const handleEndDateChange = (event) => {
        const selectedEndDate = event.target.value;
        if (selectedEndDate === '') {
            setData('end_date', data.start_date || '');
            return;
        }

        const parsedStartDate = parseDateValue(data.start_date);
        const parsedEndDate = parseDateValue(selectedEndDate);

        if (parsedStartDate && parsedEndDate && parsedEndDate < parsedStartDate) {
            setData('end_date', data.start_date);
            return;
        }

        setData('end_date', selectedEndDate);
    };

    const handleFileDrop = (event) => {
        event.preventDefault();
        setIsDragOver(false);

        const file = event.dataTransfer.files?.[0] ?? null;
        setFile(file);
    };

    const handleFileDragOver = (event) => {
        event.preventDefault();
        setIsDragOver(true);
    };

    const handleFileDragLeave = (event) => {
        event.preventDefault();
        setIsDragOver(false);
    };

    const submitAbsence = (event) => {
        event.preventDefault();
        post(route('student.absences.store'), {
            forceFormData: true,
            onSuccess: () => {
                reset();
                setSelectedReason('');
                setFileName('Nessun file selezionato');
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            },
        });
    };

    return (
        <AuthenticatedLayout header="Segnala assenza">
            <Head title="Segnala assenza" />

            <div className="grid gap-6 lg:grid-cols-3">
                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                    <h2 className="text-lg font-semibold text-slate-900">
                        Nuova richiesta
                    </h2>
                    <p className="text-sm text-slate-500">
                        Compila i campi per inviare una nuova assenza.
                    </p>

                    <form onSubmit={submitAbsence} className="mt-6 space-y-6">
                        <div className="grid gap-4 md:grid-cols-2">
                            <label className="text-sm text-slate-600">
                                Data inizio
                                <input
                                    type="date"
                                    className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                    value={data.start_date}
                                    max={today}
                                    onChange={handleStartDateChange}
                                />
                                {errors.start_date && (
                                    <p className="mt-1 text-xs text-rose-500">
                                        {errors.start_date}
                                    </p>
                                )}
                            </label>
                            <label className="text-sm text-slate-600">
                                Data fine
                                <input
                                    type="date"
                                    className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                    value={data.end_date ?? ''}
                                    min={data.start_date || undefined}
                                    max={today}
                                    onChange={handleEndDateChange}
                                />
                                {errors.end_date && (
                                    <p className="mt-1 text-xs text-rose-500">
                                        {errors.end_date}
                                    </p>
                                )}
                            </label>
                            <label className="text-sm text-slate-600 md:col-span-2">
                                Ore richieste
                                <input
                                    type="number"
                                    min="1"
                                    className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                    placeholder="es. 3"
                                    value={data.hours}
                                    onChange={(event) =>
                                        setData(
                                            'hours',
                                            event.target.value === ''
                                                ? ''
                                                : Number(event.target.value)
                                        )
                                    }
                                />
                                {errors.hours && (
                                    <p className="mt-1 text-xs text-rose-500">
                                        {errors.hours}
                                    </p>
                                )}
                            </label>
                            <label className="text-sm text-slate-600 md:col-span-2">
                                Motivazione
                                <select
                                    className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                    value={selectedReason}
                                    onChange={handleReasonChange}
                                >
                                    <option value="">Seleziona motivazione</option>
                                    <option value="Altro">Altro</option>
                                    {reasons
                                        .filter(
                                            (reason) =>
                                                String(reason?.name ?? '').trim().toLowerCase()
                                                !== 'altro'
                                        )
                                        .map((reason) => (
                                            <option key={reason.id} value={reason.name}>
                                                {reason.name}
                                            </option>
                                        ))}
                                </select>
                                {(errors.reason_choice || errors.motivation) && (
                                    <p className="mt-1 text-xs text-rose-500">
                                        {errors.reason_choice || errors.motivation}
                                    </p>
                                )}
                            </label>
                            {selectedReason === 'Altro' && (
                                <label className="text-sm text-slate-600 md:col-span-2">
                                    Motivazione custom (facoltativa)
                                    <textarea
                                        rows="3"
                                        className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                        placeholder="Descrivi brevemente il motivo quando selezioni Altro."
                                        value={data.motivation_custom}
                                        onChange={handleMotivationChange}
                                    />
                                    {(errors.motivation_custom || errors.motivation) && (
                                        <p className="mt-1 text-xs text-rose-500">
                                            {errors.motivation_custom || errors.motivation}
                                        </p>
                                    )}
                                </label>
                            )}
                        </div>

                        <div className="flex flex-wrap gap-3">
                            <button
                                type="submit"
                                className="rounded-xl bg-slate-900 px-5 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-400"
                                disabled={processing}
                            >
                                Invia richiesta
                            </button>
                        </div>
                    </form>
                </section>

                <aside className="space-y-4 lg:h-full">
                    <div className="h-full rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="text-sm font-semibold text-slate-800">
                            Documenti allegati
                        </h3>
                        <input
                            ref={fileInputRef}
                            type="file"
                            className="hidden"
                            accept=".pdf,.jpg,.jpeg,.png"
                            onChange={handleFileChange}
                        />
                        <div
                            className={`mt-3 rounded-xl border border-dashed p-4 text-center text-xs transition ${
                                isDragOver
                                    ? 'border-slate-500 bg-slate-50 text-slate-700'
                                    : 'border-slate-300 text-slate-500'
                            }`}
                            onDrop={handleFileDrop}
                            onDragOver={handleFileDragOver}
                            onDragLeave={handleFileDragLeave}
                        >
                            Trascina qui il certificato oppure selezionalo dal dispositivo.
                        </div>
                        <button
                            type="button"
                            className="mt-3 w-full rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600"
                            onClick={() => fileInputRef.current?.click()}
                        >
                            Carica file
                        </button>
                        <p className="mt-2 text-xs text-slate-500">{fileName}</p>
                        {errors.document && (
                            <p className="mt-1 text-xs text-rose-500">
                                {errors.document}
                            </p>
                        )}
                    </div>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
