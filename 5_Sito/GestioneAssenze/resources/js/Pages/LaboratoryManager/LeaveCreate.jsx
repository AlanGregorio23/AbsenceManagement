import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    hoursOnLimitLabel,
    resolveAnnualHoursLimitLabels,
} from '@/annualHoursLimit';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';

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

const normalizeStudents = (students) => {
    if (!Array.isArray(students)) {
        return [];
    }

    return students
        .map((student) => ({
            id: Number(student?.id),
            name: String(student?.name ?? '').trim(),
            email: String(student?.email ?? '').trim(),
            className: String(student?.class ?? '-').trim() || '-',
            hoursUsedOn40: Number(student?.hours_used_on_40 ?? 0),
            availableHours: Number(student?.available_hours ?? 0),
        }))
        .filter((student) => Number.isInteger(student.id) && student.id > 0);
};

const formatStudentSearchLabel = (student) =>
    `${student.name} - ${student.className}`;

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

export default function LaboratoryManagerLeaveCreate({
    reasons = [],
    lessonSlots = [],
    students = [],
    settings = {},
}) {
    const { props } = usePage();
    const annualHoursLimit = resolveAnnualHoursLimitLabels(props);
    const fileInputRef = useRef(null);
    const [fileName, setFileName] = useState('Nessun file selezionato');
    const [selectedReason, setSelectedReason] = useState('Altro');
    const [studentSearchQuery, setStudentSearchQuery] = useState('');
    const normalizedLessonSlots = normalizeLessonSlots(lessonSlots);
    const normalizedStudents = normalizeStudents(students);
    const maxAnnualHours = Number(
        settings?.max_annual_hours ?? annualHoursLimit.value
    );
    const noticeWorkingHoursRaw = Number(settings?.leave_request_notice_working_hours ?? 24);
    const noticeWorkingHours = Number.isFinite(noticeWorkingHoursRaw)
        ? noticeWorkingHoursRaw
        : 24;
    const { data, setData, post, processing, errors, reset } = useForm({
        student_id: '',
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

    const selectedStudent = useMemo(() => {
        const studentId = Number(data.student_id);
        if (!Number.isInteger(studentId) || studentId <= 0) {
            return null;
        }

        return normalizedStudents.find((student) => student.id === studentId) ?? null;
    }, [data.student_id, normalizedStudents]);

    const selectedStudentSearchLabel = selectedStudent
        ? formatStudentSearchLabel(selectedStudent)
        : '';
    const normalizedStudentSearchQuery = studentSearchQuery.trim().toLowerCase();
    const shouldShowStudentResults =
        normalizedStudentSearchQuery !== ''
        && normalizedStudentSearchQuery !== selectedStudentSearchLabel.toLowerCase();
    const filteredStudents = useMemo(() => {
        if (normalizedStudentSearchQuery === '') {
            return [];
        }

        return normalizedStudents
            .filter((student) =>
                `${student.name} ${student.className} ${student.email}`
                    .toLowerCase()
                    .includes(normalizedStudentSearchQuery)
            )
            .slice(0, 8);
    }, [normalizedStudentSearchQuery, normalizedStudents]);

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

    const handleStudentSearchChange = (event) => {
        setStudentSearchQuery(event.target.value);
        if (data.student_id !== '') {
            setData('student_id', '');
        }
    };

    const handleStudentSelect = (student) => {
        setData('student_id', String(student.id));
        setStudentSearchQuery(formatStudentSearchLabel(student));
    };

    const clearSelectedStudent = () => {
        setData('student_id', '');
        setStudentSearchQuery('');
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
        post(route('lab.leaves.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setSelectedReason('Altro');
                setStudentSearchQuery('');
                setFileName('Nessun file selezionato');
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            },
        });
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
        <AuthenticatedLayout header="Nuovo congedo per studente">
            <Head title="Nuovo congedo (Capo laboratorio)" />

            <div className="grid gap-6 lg:grid-cols-3">
                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                    <h2 className="text-lg font-semibold text-slate-900">
                        Crea richiesta congedo per studente
                    </h2>

                    <form onSubmit={submitLeave} className="mt-6 space-y-6">
                        <div className="grid gap-4 md:grid-cols-2">
                            <label className="text-sm text-slate-600 md:col-span-2">
                                Studente
                                <input
                                    type="search"
                                    className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                    placeholder="Cerca per nome, classe o email"
                                    value={studentSearchQuery}
                                    onChange={handleStudentSearchChange}
                                    autoComplete="off"
                                />
                                <p className="mt-2 text-xs text-slate-500">
                                    Scrivi per cercare, poi clicca il risultato corretto.
                                </p>
                                {shouldShowStudentResults && (
                                    <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white">
                                        {filteredStudents.length === 0 && (
                                            <div className="px-4 py-3 text-xs text-slate-500">
                                                Nessuno studente trovato.
                                            </div>
                                        )}
                                        {filteredStudents.map((student) => (
                                            <button
                                                key={student.id}
                                                type="button"
                                                className="flex w-full items-start justify-between gap-3 border-b border-slate-100 px-4 py-3 text-left transition hover:bg-slate-50 last:border-b-0"
                                                onClick={() => handleStudentSelect(student)}
                                            >
                                                <span className="min-w-0">
                                                    <span className="block truncate text-sm font-semibold text-slate-800">
                                                        {student.name}
                                                    </span>
                                                    <span className="block truncate text-xs text-slate-500">
                                                        {student.className} · {student.email || 'Nessuna email'}
                                                    </span>
                                                </span>
                                                <span className="rounded-full bg-slate-100 px-2 py-1 text-[11px] font-semibold text-slate-600">
                                                    Seleziona
                                                </span>
                                            </button>
                                        ))}
                                    </div>
                                )}
                                {selectedStudent && (
                                    <div className="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                                        <div className="min-w-0">
                                            <p className="font-semibold">Studente selezionato</p>
                                            <p className="truncate text-xs text-emerald-800">
                                                {selectedStudent.name} · {selectedStudent.className}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            className="rounded-lg border border-emerald-300 px-3 py-1 text-xs font-semibold text-emerald-900 hover:bg-emerald-100"
                                            onClick={clearSelectedStudent}
                                        >
                                            Cambia
                                        </button>
                                    </div>
                                )}
                                {errors.student_id && (
                                    <p className="mt-1 text-xs text-rose-500">
                                        {errors.student_id}
                                    </p>
                                )}
                            </label>

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
                                        Confermo che il consenso direzione e stato acquisito.
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
                                    || !selectedStudent
                                    || (requiresManagementConsent
                                        && !data.management_consent_confirmed)
                                    || (requiresDocumentOnCreate && !data.document)
                                    || !hasStartLessons
                                    || (isMultiDay && !hasEndLessons)
                                    || (selectedReason === 'Altro' && !hasCustomReason)
                                }
                            >
                                Crea richiesta congedo
                            </button>
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
                                : 'Documento facoltativo.'}
                        </p>
                        <input
                            ref={fileInputRef}
                            type="file"
                            className="hidden"
                            accept=".pdf,.jpg,.jpeg,.png"
                            onChange={(event) =>
                                setFile(event.target.files?.[0] ?? null)
                            }
                        />
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

                    <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="text-sm font-semibold text-slate-800">
                            Studente selezionato
                        </h3>
                        {!selectedStudent && (
                            <p className="mt-2 text-xs text-slate-500">
                                Seleziona uno studente per vedere riepilogo classe e ore.
                            </p>
                        )}
                        {selectedStudent && (
                            <div className="mt-3 space-y-2 text-xs text-slate-600">
                                <p>
                                    <span className="font-semibold text-slate-800">Nome:</span>{' '}
                                    {selectedStudent.name}
                                </p>
                                <p>
                                    <span className="font-semibold text-slate-800">Classe:</span>{' '}
                                    {selectedStudent.className}
                                </p>
                                <p>
                                    <span className="font-semibold text-slate-800">Email:</span>{' '}
                                    {selectedStudent.email || '-'}
                                </p>
                                <p>
                                    <span className="font-semibold text-slate-800">
                                        {hoursOnLimitLabel(maxAnnualHours)} usate:
                                    </span>{' '}
                                    {selectedStudent.hoursUsedOn40} / {maxAnnualHours}
                                </p>
                                <p>
                                    <span className="font-semibold text-slate-800">Disponibili:</span>{' '}
                                    {selectedStudent.availableHours}
                                </p>
                            </div>
                        )}
                        <div className="mt-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-[11px] text-slate-600">
                            Anticipo standard richieste congedo: {noticeWorkingHours} ore lavorative.
                            Per creazioni da capo laboratorio il workflow resta gestito internamente.
                        </div>
                    </div>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
