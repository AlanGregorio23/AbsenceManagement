import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

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

const normalizeLessonSlots = (slots) => {
    if (!Array.isArray(slots)) {
        return [];
    }

    return slots
        .map((slot) => ({
            period: Number(slot?.period),
            timeRange: String(slot?.time_range ?? '').trim(),
        }))
        .filter((slot) => Number.isInteger(slot.period) && slot.period >= 1 && slot.period <= 11);
};

const toggleLessonSelection = (values, lesson) => {
    const numericLesson = Number(lesson);
    if (!Number.isInteger(numericLesson)) {
        return values;
    }

    const current = Array.isArray(values) ? values.map((value) => Number(value)) : [];
    const exists = current.includes(numericLesson);
    const next = exists
        ? current.filter((value) => value !== numericLesson)
        : [...current, numericLesson];

    return next
        .filter((value) => Number.isInteger(value) && value >= 1 && value <= 11)
        .sort((first, second) => first - second);
};

const resolvePresetPeriods = (presetKey, slots) => {
    const allPeriods = slots.map((slot) => Number(slot.period)).filter((period) => Number.isInteger(period));
    if (presetKey === 'all') {
        return allPeriods;
    }

    if (presetKey === 'morning') {
        return allPeriods.filter((period) => period <= 5);
    }

    if (presetKey === 'afternoon') {
        return allPeriods.filter((period) => period >= 6);
    }

    return [];
};

export default function LeaveCreate({
    reasons = [],
    lessonSlots = [],
    settings = {},
    contacts = {},
}) {
    const fileInputRef = useRef(null);
    const [fileName, setFileName] = useState('Nessun file selezionato');
    const [isDragOver, setIsDragOver] = useState(false);
    const [selectedReason, setSelectedReason] = useState('Altro');
    const [showLateNoticeModal, setShowLateNoticeModal] = useState(false);
    const normalizedLessonSlots = normalizeLessonSlots(lessonSlots);
    const noticeWorkingHoursRaw = Number(settings?.leave_request_notice_working_hours ?? 24);
    const noticeWorkingHours = Number.isFinite(noticeWorkingHoursRaw)
        ? noticeWorkingHoursRaw
        : 24;
    const laboratoryManagerEmails = Array.isArray(contacts?.laboratory_manager_emails)
        ? contacts.laboratory_manager_emails
              .map((email) => String(email ?? '').trim())
              .filter((email) => email !== '')
        : [];
    const { data, setData, post, processing, errors, reset } = useForm({
        start_date: '',
        end_date: '',
        lessons_start: [],
        lessons_end: [],
        destination: '',
        reason_choice: 'Altro',
        motivation_custom: '',
        motivation: 'Altro',
        management_consent_confirmed: false,
        document: null,
    });

    const selectedReasonRule = reasons.find(
        (reason) =>
            String(reason?.name ?? '').trim().toLowerCase()
            === String(selectedReason).trim().toLowerCase()
    );
    const requiresManagementConsent = Boolean(
        selectedReason !== 'Altro'
            && selectedReasonRule?.requires_management_consent
    );
    const requiresDocumentOnCreate = Boolean(
        requiresManagementConsent
            && selectedReasonRule?.requires_document_on_leave_creation
    );
    const managementConsentNote = String(
        selectedReasonRule?.management_consent_note ?? ''
    ).trim();
    const isMultiDay = Boolean(
        data.start_date
        && data.end_date
        && data.start_date !== data.end_date
    );
    const hasStartLessons = Array.isArray(data.lessons_start) && data.lessons_start.length > 0;
    const hasEndLessons = Array.isArray(data.lessons_end) && data.lessons_end.length > 0;
    const hasCustomReason = String(data.motivation_custom ?? '').trim() !== '';
    const lateNoticeError = String(errors?.start_date ?? '');
    const isLateNoticeError = lateNoticeError.toLowerCase().includes('ore lavorative');

    const lessonPresets = [
        { key: 'all', label: 'Tutto il giorno' },
        { key: 'morning', label: 'Solo mattina' },
        { key: 'afternoon', label: 'Solo pomeriggio' },
        { key: 'clear', label: 'Svuota' },
    ];

    const handleReasonChange = (event) => {
        const value = event.target.value || 'Altro';
        setSelectedReason(value);
        setData('reason_choice', value);

        if (value === 'Altro') {
            const custom = String(data.motivation_custom ?? '').trim();
            setData('motivation', custom !== '' ? `Altro - ${custom}` : 'Altro');
            setData('management_consent_confirmed', false);
            return;
        }

        setData('motivation_custom', '');
        setData('motivation', value);
        setData('management_consent_confirmed', false);
    };

    const handleMotivationChange = (event) => {
        const value = event.target.value;
        setData('motivation_custom', value);
        setData(
            'motivation',
            value.trim() !== '' ? `Altro - ${value.trim()}` : 'Altro'
        );
    };

    const setFile = (file) => {
        setData('document', file);
        setFileName(file ? file.name : 'Nessun file selezionato');
    };

    const handleFileChange = (event) => {
        const file = event.target.files?.[0] ?? null;
        setFile(file);
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

    const submitLeave = (event) => {
        event.preventDefault();
        post(route('student.leaves.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setSelectedReason('Altro');
                setFileName('Nessun file selezionato');
                setShowLateNoticeModal(false);
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            },
        });
    };

    useEffect(() => {
        setShowLateNoticeModal(isLateNoticeError);
    }, [isLateNoticeError]);

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

    const onLessonToggle = (field, lesson) => {
        setData(field, toggleLessonSelection(data[field], lesson));
    };

    const onApplyLessonPreset = (field, presetKey) => {
        if (presetKey === 'clear') {
            setData(field, []);
            return;
        }

        setData(field, resolvePresetPeriods(presetKey, normalizedLessonSlots));
    };

    const renderLessonMatrix = (field, label) => (
        <>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    {label}
                </p>
                <div className="flex flex-wrap gap-1">
                    {lessonPresets.map((preset) => (
                        <button
                            key={`${field}-preset-${preset.key}`}
                            type="button"
                            className="rounded-md border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-600 hover:bg-slate-50"
                            onClick={() => onApplyLessonPreset(field, preset.key)}
                        >
                            {preset.label}
                        </button>
                    ))}
                </div>
            </div>

            <div className="hidden overflow-x-auto md:block">
                <table className="w-full min-w-[760px] border-collapse text-xs">
                    <thead className="bg-slate-50 text-slate-600">
                        <tr>
                            <th className="border border-slate-200 px-2 py-2 text-left font-semibold">
                                Periodo
                            </th>
                            {normalizedLessonSlots.map((slot) => (
                                <th
                                    key={`${field}-head-${slot.period}`}
                                    className="border border-slate-200 px-2 py-2 text-center font-semibold"
                                >
                                    {slot.period}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td className="border border-slate-200 px-2 py-2 font-semibold text-slate-700">
                                Ore
                            </td>
                            {normalizedLessonSlots.map((slot) => {
                                const selected = data[field].includes(slot.period);

                                return (
                                    <td
                                        key={`${field}-period-${slot.period}`}
                                        className={`border border-slate-200 px-2 py-2 text-center align-top ${selected ? 'bg-slate-100' : 'bg-white'}`}
                                    >
                                        <label className="flex cursor-pointer flex-col items-center gap-1">
                                            <input
                                                type="checkbox"
                                                checked={selected}
                                                onChange={() =>
                                                    onLessonToggle(field, slot.period)
                                                }
                                                className="h-4 w-4 rounded border-slate-300"
                                            />
                                            <span className="text-[10px] leading-tight text-slate-500">
                                                {slot.timeRange}
                                            </span>
                                        </label>
                                    </td>
                                );
                            })}
                        </tr>
                    </tbody>
                </table>
            </div>
            <div className="grid grid-cols-2 gap-2 md:hidden">
                {normalizedLessonSlots.map((slot) => {
                    const selected = data[field].includes(slot.period);

                    return (
                        <label
                            key={`${field}-mobile-${slot.period}`}
                            className={`flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 ${selected ? 'border-slate-400 bg-slate-100' : 'border-slate-200 bg-white'}`}
                        >
                            <input
                                type="checkbox"
                                checked={selected}
                                onChange={() => onLessonToggle(field, slot.period)}
                                className="h-4 w-4 rounded border-slate-300"
                            />
                            <span className="min-w-0">
                                <span className="block text-xs font-semibold text-slate-700">
                                    {slot.timeRange}
                                </span>
                            </span>
                        </label>
                    );
                })}
            </div>
        </>
    );

    return (
        <AuthenticatedLayout header="Richiesta congedo">
            <Head title="Nuova richiesta congedo" />

            <div className="grid gap-6 lg:grid-cols-3">
                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                    <h2 className="text-lg font-semibold text-slate-900">
                        Nuova richiesta congedo
                    </h2>

                    <form onSubmit={submitLeave} className="mt-6 space-y-6">
                        <div className="grid gap-4 md:grid-cols-2">
                            <label className="text-sm text-slate-600">
                                Data inizio
                                <input
                                    type="date"
                                    className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                    value={data.start_date}
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
                                    onChange={handleEndDateChange}
                                />
                                {errors.end_date && (
                                    <p className="mt-1 text-xs text-rose-500">
                                        {errors.end_date}
                                    </p>
                                )}
                            </label>

                            <label className="text-sm text-slate-600 md:col-span-2">
                                Destinazione (luogo completo)
                                <textarea
                                    rows="2"
                                    className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                    placeholder="es. Ospedale Regionale, Via Tesserete 46, Lugano"
                                    value={data.destination}
                                    onChange={(event) =>
                                        setData('destination', event.target.value)
                                    }
                                />
                                {errors.destination && (
                                    <p className="mt-1 text-xs text-rose-500">
                                        {errors.destination}
                                    </p>
                                )}
                            </label>

                            <label className="text-sm text-slate-600 md:col-span-2">
                                Motivo
                                <select
                                    className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                    value={selectedReason}
                                    onChange={handleReasonChange}
                                >
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

                            <div className="space-y-2 md:col-span-2">
                                {renderLessonMatrix('lessons_start', 'Il / dal')}
                                {isMultiDay && renderLessonMatrix('lessons_end', 'Al')}
                            </div>
                            {(errors.lessons_start || errors.lessons_end || errors.hours) && (
                                <p className="text-xs text-rose-500 md:col-span-2">
                                    {errors.lessons_start || errors.lessons_end || errors.hours}
                                </p>
                            )}

                            {requiresManagementConsent && (
                                <div className="space-y-3 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 md:col-span-2">
                                    <p className="font-semibold text-amber-950">
                                        Caso particolare: serve consenso direzione
                                    </p>
                                    <p className="text-xs text-amber-900">
                                        Prima di proseguire devi avere il consenso
                                        della direzione.
                                    </p>
                                    {managementConsentNote !== '' && (
                                        <p className="rounded-lg border border-amber-200 bg-white px-3 py-2 text-xs text-amber-900">
                                            {managementConsentNote}
                                        </p>
                                    )}
                                    {requiresDocumentOnCreate && (
                                        <p className="rounded-lg border border-amber-200 bg-white px-3 py-2 text-xs font-semibold text-amber-900">
                                            Per questa motivazione il documento e obbligatorio
                                            prima dell invio.
                                        </p>
                                    )}
                                    <label className="flex items-start gap-2 rounded-lg border border-amber-200 bg-white px-3 py-2 text-xs text-amber-900">
                                        <input
                                            type="checkbox"
                                            className="mt-0.5"
                                            checked={Boolean(
                                                data.management_consent_confirmed
                                            )}
                                            onChange={(event) =>
                                                setData(
                                                    'management_consent_confirmed',
                                                    event.target.checked
                                                )
                                            }
                                        />
                                        Ho ricevuto il consenso dalla direzione.
                                    </label>
                                    {errors.management_consent_confirmed && (
                                        <p className="mt-2 text-xs text-rose-600">
                                            {errors.management_consent_confirmed}
                                        </p>
                                    )}
                                </div>
                            )}

                            {selectedReason === 'Altro' && (
                                <label className="text-sm text-slate-600 md:col-span-2">
                                    Motivazione custom
                                    <textarea
                                        rows="3"
                                        className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                        placeholder="Descrivi il motivo."
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
                                disabled={
                                    processing
                                    || (requiresManagementConsent
                                        && !data.management_consent_confirmed)
                                    || (requiresDocumentOnCreate && !data.document)
                                    || !hasStartLessons
                                    || (isMultiDay && !hasEndLessons)
                                    || (selectedReason === 'Altro' && !hasCustomReason)
                                }
                            >
                                Invia richiesta congedo
                            </button>
                            {requiresDocumentOnCreate && !data.document && (
                                <p className="text-xs text-amber-700">
                                    Per questa motivazione devi allegare un documento.
                                </p>
                            )}
                        </div>
                    </form>
                </section>

                <aside className="space-y-4 lg:h-full">
                    <div className="h-full rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="text-sm font-semibold text-slate-800">
                            Scansioni e documenti
                        </h3>
                        <p className="mt-2 text-sm text-slate-500">
                            {requiresDocumentOnCreate
                                ? 'Documento obbligatorio per questa motivazione.'
                                : 'Documento facoltativo: puoi allegarlo subito o dopo.'}
                        </p>
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
                            {requiresDocumentOnCreate
                                ? 'Trascina qui il documento obbligatorio oppure selezionalo dal dispositivo.'
                                : 'Trascina qui il file oppure selezionalo dal dispositivo.'}
                        </div>
                        <button
                            type="button"
                            className="mt-3 w-full rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600"
                            onClick={() => fileInputRef.current?.click()}
                        >
                            Seleziona file
                        </button>
                        <p className="mt-2 text-xs text-slate-500">{fileName}</p>
                        {errors.document && (
                            <p className="mt-1 text-xs text-rose-500">
                                {errors.document}
                            </p>
                        )}
                    </div>

                    <div className="rounded-2xl border border-amber-200 bg-amber-50 p-6 shadow-sm">
                        <h3 className="text-sm font-semibold text-amber-900">
                            Note importanti congedo
                        </h3>
                        <div className="mt-3 space-y-2 text-xs text-amber-900">
                            <p>
                                La richiesta deve essere inviata almeno{' '}
                                <span className="font-semibold">{noticeWorkingHours} ore lavorative</span>{' '}
                                prima della data/ora di inizio.
                            </p>
                            <p>
                                Durante i periodi di vacanza scolastica non inviare congedi nel sistema:
                                contatta direttamente il capo laboratorio.
                            </p>
                            <p>
                                Per casi urgenti, eccezionali o particolari scrivi subito via email al capo laboratorio.
                            </p>
                            {laboratoryManagerEmails.length > 0 && (
                                <div className="rounded-xl border border-amber-200 bg-white px-3 py-2 text-[11px]">
                                    <p className="font-semibold text-amber-900">
                                        Email capi laboratorio
                                    </p>
                                    <p className="mt-1 break-all text-amber-800">
                                        {laboratoryManagerEmails.join(' | ')}
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>
                </aside>
            </div>

            {showLateNoticeModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 px-4">
                    <div className="w-full max-w-lg rounded-2xl border border-rose-200 bg-white p-5 shadow-2xl">
                        <h3 className="text-base font-semibold text-rose-700">
                            Richiesta oltre anticipo minimo
                        </h3>
                        <p className="mt-2 text-sm text-slate-700">
                            Il congedo deve essere inviato almeno{' '}
                            <span className="font-semibold">{noticeWorkingHours} ore lavorative</span>{' '}
                            prima dell inizio previsto.
                        </p>
                        <p className="mt-2 text-sm text-slate-700">
                            Per casi particolari scrivi subito via email ai capi laboratorio.
                        </p>
                        {laboratoryManagerEmails.length > 0 && (
                            <div className="mt-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                <p className="font-semibold text-slate-700">
                                    Email capi laboratorio
                                </p>
                                <p className="mt-1 break-all">
                                    {laboratoryManagerEmails.join(' | ')}
                                </p>
                            </div>
                        )}
                        <div className="mt-4 flex justify-end">
                            <button
                                type="button"
                                className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700"
                                onClick={() => setShowLateNoticeModal(false)}
                            >
                                Ho capito
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
