import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { buildAnnualHoursLimitLabels } from '@/annualHoursLimit';
import { Head, useForm } from '@inertiajs/react';
import { Fragment, useMemo, useState } from 'react';

const ACTION_OPTIONS = [
    { value: 'none', label: 'Nessuna azione' },
    { value: 'notify_student', label: 'Notifica allievo' },
    { value: 'notify_guardian', label: 'Notifica tutore' },
    { value: 'notify_teacher', label: 'Notifica docente di classe' },
    {
        value: 'extra_activity_notice',
        label: 'Segnalazione possibile attivita extrascolastica (>=60 min)',
    },
    {
        value: 'conduct_penalty',
        label: 'Segnalazione proposta penalita nota di condotta',
    },
];

const emptyReason = () => ({
    id: null,
    name: '',
    counts_40_hours: true,
    requires_management_consent: false,
    requires_document_on_leave_creation: false,
    management_consent_note: '',
});

const emptyRule = () => ({
    id: null,
    min_delays: 0,
    max_delays: null,
    actions: [{ type: 'none', detail: '' }],
    info_message: '',
});

const RECOMMENDED_INTERACTION_RETENTION_DAYS = 180;
const RECOMMENDED_ERROR_RETENTION_DAYS = 365;
const WEEKDAY_LABELS = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'];
const SEMESTER_BOUNDARY_REFERENCE_YEAR = 2000;
const MONTH_LABELS = [
    'Gennaio',
    'Febbraio',
    'Marzo',
    'Aprile',
    'Maggio',
    'Giugno',
    'Luglio',
    'Agosto',
    'Settembre',
    'Ottobre',
    'Novembre',
    'Dicembre',
];
const SCHOOL_YEAR_MONTH_ORDER = [8, 9, 10, 11, 12, 1, 2, 3, 4, 5, 6, 7];

const normalizeHolidayDate = (value) => {
    const input = String(value ?? '').trim();
    const datePart = input.slice(0, 10);

    return /^\d{4}-\d{2}-\d{2}$/.test(datePart) ? datePart : null;
};

const formatHolidayDate = (value) => {
    const normalized = normalizeHolidayDate(value);
    if (!normalized) {
        return '-';
    }

    const [year, month, day] = normalized.split('-');
    return `${day}.${month}.${year}`;
};

const parseSchoolYear = (schoolYear) => {
    const match = String(schoolYear ?? '').match(/^(\d{4})-(\d{4})$/);
    if (!match) {
        return null;
    }

    return {
        startYear: Number(match[1]),
        endYear: Number(match[2]),
    };
};

const buildSemesterBoundaryInputValue = (day, month) => {
    const normalizedMonth = Math.min(Math.max(Number(month) || 1, 1), 12);
    const maxDay = new Date(
        SEMESTER_BOUNDARY_REFERENCE_YEAR,
        normalizedMonth,
        0
    ).getDate();
    const normalizedDay = Math.min(Math.max(Number(day) || 1, 1), maxDay);

    return `${SEMESTER_BOUNDARY_REFERENCE_YEAR}-${String(normalizedMonth).padStart(2, '0')}-${String(normalizedDay).padStart(2, '0')}`;
};

const parseSemesterBoundaryInputValue = (value) => {
    const normalized = normalizeHolidayDate(value);
    if (!normalized) {
        return null;
    }

    const [, month, day] = normalized.split('-').map(Number);

    return { day, month };
};

const fieldClass =
    'h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-800 shadow-sm transition focus:border-sky-300 focus:outline-none focus:ring-2 focus:ring-sky-100';
const selectClass = `${fieldClass} pr-8`;

function SectionPanel({ id, title, description, meta = null, children }) {
    return (
        <section
            id={id}
            className="rounded-2xl border border-slate-200 bg-white shadow-sm"
        >
            <div className="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-5 py-4 sm:px-6">
                <div>
                    <h2 className="text-base font-semibold text-slate-950">
                        {title}
                    </h2>
                    {description && (
                        <p className="mt-1 max-w-3xl text-sm text-slate-500">
                            {description}
                        </p>
                    )}
                </div>
                {meta}
            </div>
            <div className="px-5 py-5 sm:px-6">{children}</div>
        </section>
    );
}

function Field({ label, hint = null, children, className = '' }) {
    return (
        <label className={`flex min-w-0 flex-col gap-1.5 ${className}`}>
            <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                {label}
            </span>
            {children}
            {hint && <span className="text-xs text-slate-500">{hint}</span>}
        </label>
    );
}

function SettingGroup({ title, children, action = null }) {
    return (
        <div className="min-w-0 rounded-xl border border-slate-200 bg-slate-50/70 p-4">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-sm font-semibold text-slate-800">{title}</h3>
                {action}
            </div>
            {children}
        </div>
    );
}

function SummaryPill({ label, value, tone = 'slate' }) {
    const tones = {
        slate: 'bg-slate-100 text-slate-700',
        sky: 'bg-sky-100 text-sky-700',
        emerald: 'bg-emerald-100 text-emerald-700',
        amber: 'bg-amber-100 text-amber-700',
    };

    return (
        <span
            className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${tones[tone] ?? tones.slate}`}
        >
            {label}: {value}
        </span>
    );
}

export default function Settings({ settings, retentionAdvice = null }) {
    const [isConfirmModalOpen, setIsConfirmModalOpen] = useState(false);
    const [editingHolidayId, setEditingHolidayId] = useState(null);
    const [selectedHolidayId, setSelectedHolidayId] = useState(null);
    const recommendedInteractionRetentionDays =
        retentionAdvice?.recommended_interaction_days ??
        RECOMMENDED_INTERACTION_RETENTION_DAYS;
    const recommendedErrorRetentionDays =
        retentionAdvice?.recommended_error_days ??
        RECOMMENDED_ERROR_RETENTION_DAYS;
    const { data, setData, post, processing } = useForm({
        absence: {
            max_annual_hours: settings.absence.max_annual_hours,
            warning_threshold_hours: settings.absence.warning_threshold_hours,
            vice_director_email: settings.absence.vice_director_email ?? '',
            guardian_signature_required:
                settings.absence.guardian_signature_required,
            medical_certificate_days:
                settings.absence.medical_certificate_days,
            medical_certificate_max_days:
                settings.absence.medical_certificate_max_days,
            absence_countdown_days:
                settings.absence.absence_countdown_days,
            pre_expiry_warning_percent:
                settings.absence.pre_expiry_warning_percent ?? 80,
            leave_request_notice_working_hours:
                settings.absence.leave_request_notice_working_hours ?? 24,
        },
        reasons:
            settings?.reasons?.length > 0
                ? settings.reasons.map((reason) => ({
                      ...reason,
                      requires_management_consent: Boolean(
                          reason?.requires_management_consent
                      ),
                      requires_document_on_leave_creation: Boolean(
                          reason?.requires_document_on_leave_creation
                      ),
                      management_consent_note: String(
                          reason?.management_consent_note ?? ''
                      ),
                  }))
                : [emptyReason()],
        delay: {
            minutes_threshold: settings?.delay?.minutes_threshold ?? 15,
            guardian_signature_required:
                settings?.delay?.guardian_signature_required ?? true,
            deadline_active: Boolean(settings?.delay?.deadline_active ?? false),
            deadline_business_days:
                settings?.delay?.deadline_business_days ?? 5,
            first_semester_end_day:
                settings?.delay?.first_semester_end_day ?? 26,
            first_semester_end_month:
                settings?.delay?.first_semester_end_month ?? 1,
            pre_expiry_warning_percent:
                settings?.delay?.pre_expiry_warning_percent ?? 80,
        },
        logs: {
            interaction_retention_days:
                settings?.logs?.interaction_retention_days ?? 425,
            error_retention_days: settings?.logs?.error_retention_days ?? 425,
        },
        login: {
            max_attempts: settings?.login?.max_attempts ?? 5,
            decay_seconds: settings?.login?.decay_seconds ?? 300,
            forgot_password_max_attempts:
                settings?.login?.forgot_password_max_attempts ?? 6,
            forgot_password_decay_seconds:
                settings?.login?.forgot_password_decay_seconds ?? 60,
            reset_password_max_attempts:
                settings?.login?.reset_password_max_attempts ?? 6,
            reset_password_decay_seconds:
                settings?.login?.reset_password_decay_seconds ?? 60,
        },
        delay_rules:
            settings?.delay_rules?.length > 0
                ? settings.delay_rules.map((rule) => ({
                      ...rule,
                      actions:
                          rule.actions && rule.actions.length > 0
                              ? rule.actions
                              : [{ type: 'none', detail: '' }],
                  }))
                : [emptyRule()],
    });
    const holidayImportForm = useForm({
        calendar_pdf: null,
    });
    const holidayCreateForm = useForm({
        holiday_date: '',
        label: '',
    });
    const holidayEditForm = useForm({
        holiday_date: '',
        label: '',
    });
    const holidayDeleteForm = useForm({});
    const holidays = Array.isArray(settings?.holidays) ? settings.holidays : [];
    const holidaysByYear = useMemo(() => {
        const grouped = {};
        holidays.forEach((holiday) => {
            const yearKey = String(holiday?.school_year ?? 'Senza anno');
            if (!grouped[yearKey]) {
                grouped[yearKey] = [];
            }
            grouped[yearKey].push(holiday);
        });

        return Object.entries(grouped)
            .sort(([first], [second]) => first.localeCompare(second))
            .map(([schoolYear, items]) => ({
                schoolYear,
                items: [...items].sort((first, second) => {
                    const firstDate = normalizeHolidayDate(first?.holiday_date) ?? '';
                    const secondDate = normalizeHolidayDate(second?.holiday_date) ?? '';
                    return firstDate.localeCompare(secondDate);
                }),
            }));
    }, [holidays]);
    const holidayCalendarByYear = useMemo(() => {
        return holidaysByYear.map((group) => {
            const schoolYearRange = parseSchoolYear(group.schoolYear);
            const holidaysByDate = new Map();

            group.items.forEach((holiday) => {
                const normalizedDate = normalizeHolidayDate(holiday?.holiday_date);
                if (normalizedDate) {
                    holidaysByDate.set(normalizedDate, holiday);
                }
            });

            const pdfCount = group.items.filter(
                (holiday) => holiday.source === 'pdf_import'
            ).length;
            const manualCount = group.items.length - pdfCount;

            const fallbackMonths = Array.from(holidaysByDate.keys())
                .map((dateKey) => {
                    const [year, month] = dateKey.split('-').map(Number);
                    return { year, month };
                })
                .filter(
                    (item, index, source) =>
                        index
                        === source.findIndex(
                            (candidate) =>
                                candidate.year === item.year && candidate.month === item.month
                        )
                )
                .sort((first, second) =>
                    `${first.year}-${String(first.month).padStart(2, '0')}`.localeCompare(
                        `${second.year}-${String(second.month).padStart(2, '0')}`
                    )
                );

            const monthPlan = schoolYearRange
                ? SCHOOL_YEAR_MONTH_ORDER.map((month) => ({
                      year: month >= 8 ? schoolYearRange.startYear : schoolYearRange.endYear,
                      month,
                  }))
                : fallbackMonths;

            const months = monthPlan.map(({ year, month }) => {
                const daysInMonth = new Date(year, month, 0).getDate();
                const firstDay = new Date(year, month - 1, 1);
                const mondayBasedOffset = (firstDay.getDay() + 6) % 7;
                const cells = [];

                for (let index = 0; index < mondayBasedOffset; index += 1) {
                    cells.push({
                        kind: 'placeholder',
                        id: `placeholder-${year}-${month}-${index}`,
                    });
                }

                let monthHolidayCount = 0;
                const holidayItems = [];
                for (let day = 1; day <= daysInMonth; day += 1) {
                    const dateKey = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                    const holiday = holidaysByDate.get(dateKey) ?? null;
                    if (holiday) {
                        monthHolidayCount += 1;
                        holidayItems.push({
                            day,
                            holiday,
                            dateKey,
                        });
                    }
                    cells.push({
                        kind: holiday ? 'holiday' : 'day',
                        id: dateKey,
                        day,
                        holiday,
                    });
                }

                while (cells.length % 7 !== 0) {
                    const index = cells.length;
                    cells.push({
                        kind: 'placeholder',
                        id: `placeholder-tail-${year}-${month}-${index}`,
                    });
                }

                return {
                    id: `${year}-${month}`,
                    year,
                    month,
                    label: `${MONTH_LABELS[month - 1]} ${year}`,
                    holidayCount: monthHolidayCount,
                    holidayItems,
                    cells,
                };
            });

            return {
                ...group,
                totalCount: group.items.length,
                pdfCount,
                manualCount,
                months,
            };
        });
    }, [holidaysByYear]);
    const selectedHoliday = useMemo(() => {
        if (!selectedHolidayId) {
            return null;
        }
        return holidays.find((holiday) => holiday.id === selectedHolidayId) ?? null;
    }, [holidays, selectedHolidayId]);
    const annualHoursLimit = buildAnnualHoursLimitLabels(
        data.absence.max_annual_hours
    );

    const submitSettings = (event) => {
        event.preventDefault();
        setIsConfirmModalOpen(true);
    };

    const closeConfirmModal = () => {
        if (processing) {
            return;
        }
        setIsConfirmModalOpen(false);
    };

    const confirmSaveSettings = () => {
        post(route('admin.settings.update'), {
            preserveScroll: true,
            onSuccess: () => setIsConfirmModalOpen(false),
        });
    };

    const updateAbsence = (field, value) => {
        setData('absence', { ...data.absence, [field]: value });
    };

    const updateReason = (index, field, value) => {
        const next = data.reasons.map((reason, reasonIndex) =>
            reasonIndex === index ? { ...reason, [field]: value } : reason
        );
        setData('reasons', next);
    };

    const addReason = () => {
        setData('reasons', [...data.reasons, emptyReason()]);
    };

    const removeReason = (index) => {
        const next = data.reasons.filter((_, reasonIndex) => reasonIndex !== index);
        setData('reasons', next.length ? next : []);
    };

    const updateDelay = (field, value) => {
        setData('delay', { ...data.delay, [field]: value });
    };

    const updateDelaySemesterBoundary = (value) => {
        const nextBoundary = parseSemesterBoundaryInputValue(value);
        if (!nextBoundary) {
            return;
        }

        setData('delay', {
            ...data.delay,
            first_semester_end_day: nextBoundary.day,
            first_semester_end_month: nextBoundary.month,
        });
    };

    const updateLogs = (field, value) => {
        setData('logs', { ...data.logs, [field]: value });
    };

    const updateLogin = (field, value) => {
        setData('login', { ...data.login, [field]: value });
    };

    const updateRule = (index, field, value) => {
        const next = data.delay_rules.map((rule, ruleIndex) =>
            ruleIndex === index ? { ...rule, [field]: value } : rule
        );
        setData('delay_rules', next);
    };

    const addRule = () => {
        setData('delay_rules', [...data.delay_rules, emptyRule()]);
    };

    const removeRule = (index) => {
        const next = data.delay_rules.filter((_, ruleIndex) => ruleIndex !== index);
        setData('delay_rules', next.length ? next : []);
    };

    const addAction = (ruleIndex) => {
        const next = data.delay_rules.map((rule, index) => {
            if (index !== ruleIndex) {
                return rule;
            }
            return {
                ...rule,
                actions: [...rule.actions, { type: 'none', detail: '' }],
            };
        });
        setData('delay_rules', next);
    };

    const updateAction = (ruleIndex, actionIndex, field, value) => {
        const next = data.delay_rules.map((rule, index) => {
            if (index !== ruleIndex) {
                return rule;
            }
            const actions = rule.actions.map((action, idx) =>
                idx === actionIndex ? { ...action, [field]: value } : action
            );
            return { ...rule, actions };
        });
        setData('delay_rules', next);
    };

    const removeAction = (ruleIndex, actionIndex) => {
        const next = data.delay_rules.map((rule, index) => {
            if (index !== ruleIndex) {
                return rule;
            }
            const actions = rule.actions.filter((_, idx) => idx !== actionIndex);
            return {
                ...rule,
                actions: actions.length
                    ? actions
                    : [{ type: 'none', detail: '' }],
            };
        });
        setData('delay_rules', next);
    };

    const submitHolidayImport = (event = null) => {
        if (event) {
            event.preventDefault();
        }
        holidayImportForm.post(route('admin.settings.holidays.import'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => holidayImportForm.reset('calendar_pdf'),
        });
    };

    const submitHolidayCreate = (event = null) => {
        if (event) {
            event.preventDefault();
        }
        holidayCreateForm.post(route('admin.settings.holidays.store'), {
            preserveScroll: true,
            onSuccess: () => holidayCreateForm.reset(),
        });
    };

    const openHolidayEdit = (holiday) => {
        setEditingHolidayId(holiday.id);
        setSelectedHolidayId(holiday.id);
        holidayEditForm.setData({
            holiday_date: holiday.holiday_date ?? '',
            label: holiday.label ?? '',
        });
        holidayEditForm.clearErrors();
    };

    const cancelHolidayEdit = () => {
        setEditingHolidayId(null);
        holidayEditForm.reset();
        holidayEditForm.clearErrors();
    };

    const submitHolidayEdit = (event = null) => {
        if (event) {
            event.preventDefault();
        }
        if (!editingHolidayId) {
            return;
        }
        holidayEditForm.patch(
            route('admin.settings.holidays.update', editingHolidayId),
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedHolidayId(editingHolidayId);
                    setEditingHolidayId(null);
                    holidayEditForm.reset();
                },
            }
        );
    };

    const deleteHoliday = (holidayId) => {
        if (!window.confirm('Eliminare questa data vacanza?')) {
            return;
        }
        holidayDeleteForm.delete(route('admin.settings.holidays.destroy', holidayId), {
            preserveScroll: true,
            onSuccess: () => {
                if (selectedHolidayId === holidayId) {
                    setSelectedHolidayId(null);
                }
                if (editingHolidayId === holidayId) {
                    cancelHolidayEdit();
                }
            },
        });
    };

    return (
        <AuthenticatedLayout header="Configurazione">
            <Head title="Configurazione" />

            <form onSubmit={submitSettings} className="space-y-5 pb-24">
                <div className="rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h1 className="text-xl font-semibold text-slate-950">
                                Configurazione
                            </h1>
                            <p className="mt-1 text-sm text-slate-500">
                                Regole operative, calendario, sicurezza e retention.
                            </p>
                        </div>
                    </div>
                </div>

                <SectionPanel
                    id="config-absenze"
                    title="Assenze e congedi"
                    description="Soglie ore, certificati, firme e motivazioni."
                    meta={
                        <div className="flex flex-wrap gap-2">
                            <SummaryPill
                                label="Limite"
                                value={annualHoursLimit.limit}
                                tone="sky"
                            />
                            <SummaryPill
                                label="Avviso"
                                value={`${data.absence.warning_threshold_hours} ore`}
                                tone="amber"
                            />
                        </div>
                    }
                >
                    <div className="grid gap-4 xl:grid-cols-[minmax(0,0.9fr)_minmax(520px,1.35fr)]">
                        <div className="space-y-4">
                            <SettingGroup title="Ore e notifiche">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field label="Ore annuali">
                                        <input
                                            className={fieldClass}
                                            type="number"
                                            min="1"
                                            value={data.absence.max_annual_hours}
                                            onChange={(event) =>
                                                updateAbsence(
                                                    'max_annual_hours',
                                                    Number(event.target.value)
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Soglia avviso">
                                        <input
                                            className={fieldClass}
                                            type="number"
                                            min="0"
                                            value={data.absence.warning_threshold_hours}
                                            onChange={(event) =>
                                                updateAbsence(
                                                    'warning_threshold_hours',
                                                    Number(event.target.value)
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Email vicedirettore"
                                        className="sm:col-span-2"
                                    >
                                        <input
                                            className={fieldClass}
                                            type="email"
                                            value={data.absence.vice_director_email}
                                            onChange={(event) =>
                                                updateAbsence(
                                                    'vice_director_email',
                                                    event.target.value
                                                )
                                            }
                                            placeholder="vicedirettore@samt.ch"
                                        />
                                    </Field>
                                </div>
                            </SettingGroup>

                            <SettingGroup title="Firme e certificati">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field label="Firma tutore">
                                        <select
                                            className={selectClass}
                                            value={
                                                data.absence.guardian_signature_required
                                                    ? 'yes'
                                                    : 'no'
                                            }
                                            onChange={(event) =>
                                                updateAbsence(
                                                    'guardian_signature_required',
                                                    event.target.value === 'yes'
                                                )
                                            }
                                        >
                                            <option value="yes">Si</option>
                                            <option value="no">No</option>
                                        </select>
                                    </Field>
                                    <Field label="Consegna certificato">
                                        <input
                                            className={fieldClass}
                                            type="number"
                                            min="0"
                                            value={data.absence.medical_certificate_days}
                                            onChange={(event) =>
                                                updateAbsence(
                                                    'medical_certificate_days',
                                                    Number(event.target.value)
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Durata max certificato">
                                        <input
                                            className={fieldClass}
                                            type="number"
                                            min="0"
                                            value={data.absence.medical_certificate_max_days}
                                            onChange={(event) =>
                                                updateAbsence(
                                                    'medical_certificate_max_days',
                                                    Number(event.target.value)
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Countdown assenza">
                                        <input
                                            className={fieldClass}
                                            type="number"
                                            min="0"
                                            value={data.absence.absence_countdown_days}
                                            onChange={(event) =>
                                                updateAbsence(
                                                    'absence_countdown_days',
                                                    Number(event.target.value)
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Avviso scadenza %">
                                        <input
                                            className={fieldClass}
                                            type="number"
                                            min="1"
                                            max="100"
                                            value={data.absence.pre_expiry_warning_percent}
                                            onChange={(event) =>
                                                updateAbsence(
                                                    'pre_expiry_warning_percent',
                                                    Number(event.target.value)
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Preavviso congedo">
                                        <input
                                            className={fieldClass}
                                            type="number"
                                            min="0"
                                            max="240"
                                            value={
                                                data.absence.leave_request_notice_working_hours
                                            }
                                            onChange={(event) =>
                                                updateAbsence(
                                                    'leave_request_notice_working_hours',
                                                    Number(event.target.value)
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                            </SettingGroup>
                        </div>

                        <SettingGroup
                            title="Motivazioni predefinite"
                            action={
                                <button
                                    type="button"
                                    className="btn-soft-neutral"
                                    onClick={addReason}
                                >
                                    Aggiungi
                                </button>
                            }
                        >
                            <div className="mb-3 flex flex-wrap gap-2">
                                <SummaryPill
                                    label="Conta"
                                    value={annualHoursLimit.limit}
                                    tone="emerald"
                                />
                                <SummaryPill
                                    label="Esclusa"
                                    value="fuori limite"
                                    tone="slate"
                                />
                                <SummaryPill
                                    label="Speciale"
                                    value="consenso direzione"
                                    tone="amber"
                                />
                            </div>
                            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                                <table className="w-full min-w-[820px] text-sm">
                                    <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                        <tr>
                                            <th className="px-3 py-2 text-left">
                                                Motivazione
                                            </th>
                                            <th className="w-32 px-3 py-2 text-left">
                                                Limite ore
                                            </th>
                                            <th className="w-32 px-3 py-2 text-left">
                                                Speciale
                                            </th>
                                            <th className="w-40 px-3 py-2 text-left">
                                                Documento
                                            </th>
                                            <th className="w-24 px-3 py-2 text-right">
                                                Azioni
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {data.reasons.map((reason, index) => (
                                            <Fragment key={`reason-${index}`}>
                                            <tr>
                                                <td className="px-3 py-2 align-top">
                                                    <input
                                                        className={`${fieldClass} w-full`}
                                                        value={reason.name}
                                                        onChange={(event) =>
                                                            updateReason(
                                                                index,
                                                                'name',
                                                                event.target.value
                                                            )
                                                        }
                                                        placeholder="Motivazione"
                                                    />
                                                </td>
                                                <td className="px-3 py-2 align-top">
                                                    <select
                                                        className={`${selectClass} w-full`}
                                                        value={
                                                            reason.counts_40_hours
                                                                ? 'in'
                                                                : 'out'
                                                        }
                                                        onChange={(event) =>
                                                            updateReason(
                                                                index,
                                                                'counts_40_hours',
                                                                event.target.value === 'in'
                                                            )
                                                        }
                                                    >
                                                        <option value="in">Conta</option>
                                                        <option value="out">
                                                            Esclusa
                                                        </option>
                                                    </select>
                                                </td>
                                                <td className="px-3 py-2 text-center align-top">
                                                    <label className="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 bg-white">
                                                        <input
                                                            type="checkbox"
                                                            aria-label="Motivazione speciale"
                                                            checked={Boolean(
                                                                reason.requires_management_consent
                                                            )}
                                                            onChange={(event) =>
                                                                updateReason(
                                                                    index,
                                                                    'requires_management_consent',
                                                                    event.target.checked
                                                                )
                                                            }
                                                        />
                                                    </label>
                                                </td>
                                                <td className="px-3 py-2 text-center align-top">
                                                    {Boolean(reason.requires_management_consent) && (
                                                        <label className="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 bg-white">
                                                            <input
                                                                type="checkbox"
                                                                aria-label="Richiedi documento subito"
                                                                checked={Boolean(
                                                                    reason.requires_document_on_leave_creation
                                                                )}
                                                                onChange={(event) =>
                                                                    updateReason(
                                                                        index,
                                                                        'requires_document_on_leave_creation',
                                                                        event.target.checked
                                                                    )
                                                                }
                                                            />
                                                        </label>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 text-right align-top">
                                                    <button
                                                        type="button"
                                                        className="btn-soft-danger"
                                                        onClick={() => removeReason(index)}
                                                    >
                                                        Rimuovi
                                                    </button>
                                                </td>
                                            </tr>
                                            {Boolean(reason.requires_management_consent) && (
                                                <tr className="bg-amber-50/60">
                                                    <td colSpan={5} className="px-3 pb-3">
                                                        <textarea
                                                            rows="3"
                                                            className="w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm text-slate-700 focus:border-amber-300 focus:outline-none focus:ring-2 focus:ring-amber-100"
                                                            value={
                                                                reason.management_consent_note
                                                                ?? ''
                                                            }
                                                            onChange={(event) =>
                                                                updateReason(
                                                                    index,
                                                                    'management_consent_note',
                                                                    event.target.value
                                                                )
                                                            }
                                                            maxLength={2000}
                                                            placeholder="Nota per allievo"
                                                        />
                                                    </td>
                                                </tr>
                                            )}
                                            </Fragment>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </SettingGroup>
                    </div>
                </SectionPanel>

                <SectionPanel
                    id="config-vacanze"
                    title="Calendario scolastico"
                    description="Vacanze importate da PDF e date manuali."
                    meta={
                        <div className="flex flex-wrap gap-2">
                            <SummaryPill
                                label="Date"
                                value={holidays.length}
                                tone="emerald"
                            />
                            <SummaryPill
                                label="Anni"
                                value={holidayCalendarByYear.length}
                                tone="sky"
                            />
                        </div>
                    }
                >
                    <div className="grid gap-4 xl:grid-cols-[0.9fr_1.2fr_0.8fr]">
                        <SettingGroup title="Importa calendario PDF">
                            <input
                                type="file"
                                accept=".pdf,application/pdf"
                                className={`${fieldClass} w-full pt-2`}
                                onChange={(event) =>
                                    holidayImportForm.setData(
                                        'calendar_pdf',
                                        event.target.files?.[0] ?? null
                                    )
                                }
                            />
                            {holidayImportForm.errors.calendar_pdf && (
                                <p className="text-xs text-rose-600">
                                    {holidayImportForm.errors.calendar_pdf}
                                </p>
                            )}
                            <button
                                type="button"
                                className="btn-soft-neutral mt-3"
                                onClick={submitHolidayImport}
                                disabled={holidayImportForm.processing}
                            >
                                {holidayImportForm.processing
                                    ? 'Import in corso...'
                                    : 'Importa e aggiorna vacanze'}
                            </button>
                        </SettingGroup>

                        <SettingGroup title="Aggiungi data manuale">
                            <div className="grid gap-3 md:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)_auto] xl:grid-cols-1 2xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)_auto]">
                                <Field label="Data">
                                <input
                                    type="date"
                                    className={fieldClass}
                                    value={holidayCreateForm.data.holiday_date}
                                    onChange={(event) =>
                                        holidayCreateForm.setData(
                                            'holiday_date',
                                            event.target.value
                                        )
                                    }
                                />
                                </Field>
                                <Field label="Descrizione">
                                <input
                                    type="text"
                                    maxLength={255}
                                    className={fieldClass}
                                    value={holidayCreateForm.data.label}
                                    onChange={(event) =>
                                        holidayCreateForm.setData('label', event.target.value)
                                    }
                                    placeholder="Es. Ponte, festivita cantonale..."
                                />
                                </Field>
                                <div className="flex items-end">
                                    <button
                                        type="button"
                                        className="btn-soft-neutral h-10"
                                        onClick={submitHolidayCreate}
                                        disabled={holidayCreateForm.processing}
                                    >
                                        {holidayCreateForm.processing ? 'Salvo...' : 'Aggiungi'}
                                    </button>
                                </div>
                            </div>
                            {(holidayCreateForm.errors.holiday_date || holidayCreateForm.errors.label) && (
                                <p className="mt-2 text-xs text-rose-600">
                                    {holidayCreateForm.errors.holiday_date
                                        || holidayCreateForm.errors.label}
                                </p>
                            )}
                        </SettingGroup>

                        <SettingGroup title="Semestri">
                            <Field
                                label="Fine primo semestre"
                                hint="Aggiornato automaticamente importando il calendario PDF."
                            >
                                <input
                                    className={fieldClass}
                                    type="date"
                                    value={buildSemesterBoundaryInputValue(
                                        data.delay.first_semester_end_day,
                                        data.delay.first_semester_end_month
                                    )}
                                    onChange={(event) =>
                                        updateDelaySemesterBoundary(
                                            event.target.value
                                        )
                                    }
                                />
                            </Field>
                            <p className="mt-3 rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-700">
                                Il secondo semestre parte dal giorno successivo.
                            </p>
                        </SettingGroup>
                    </div>

                    <div className="mt-4 space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-sm font-semibold text-slate-800">
                                Date registrate
                            </p>
                            <SummaryPill label="Totale" value={holidays.length} />
                        </div>
                        {holidays.length === 0 && (
                            <p className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4 text-sm text-slate-500">
                                Nessuna vacanza registrata.
                            </p>
                        )}
                        {holidayCalendarByYear.map((group) => (
                            <div
                                key={`calendar-${group.schoolYear}`}
                                className="space-y-3 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 p-3"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-1 pb-2 text-xs">
                                    <p className="font-semibold uppercase tracking-wide text-slate-500">
                                        Calendario {group.schoolYear}
                                    </p>
                                    <div className="flex flex-wrap items-center gap-2 text-[11px]">
                                        <span className="rounded-full bg-slate-200 px-2 py-1 font-semibold text-slate-600">
                                            Totale {group.totalCount}
                                        </span>
                                        <span className="rounded-full bg-emerald-100 px-2 py-1 font-semibold text-emerald-700">
                                            PDF {group.pdfCount}
                                        </span>
                                        <span className="rounded-full bg-amber-100 px-2 py-1 font-semibold text-amber-700">
                                            Manuale {group.manualCount}
                                        </span>
                                    </div>
                                </div>
                                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                                    {group.months.map((month) => (
                                        <article
                                            key={month.id}
                                            className="min-h-[96px] rounded-xl border border-slate-200 bg-white p-3"
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <p className="truncate text-xs font-semibold uppercase tracking-wide text-slate-600">
                                                    {month.label}
                                                </p>
                                                <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                                                    {month.holidayCount}
                                                </span>
                                            </div>
                                            <div className="mt-3 flex flex-wrap gap-1.5">
                                                {month.holidayItems.length === 0 ? (
                                                    <span className="text-xs text-slate-400">
                                                        Nessuna data
                                                    </span>
                                                ) : (
                                                    month.holidayItems.map((item) => {
                                                        const isSelected =
                                                            selectedHolidayId
                                                            === item.holiday.id;

                                                        return (
                                                            <button
                                                                key={item.dateKey}
                                                                type="button"
                                                                className={`h-7 min-w-7 rounded-md px-2 text-xs font-semibold transition ${isSelected
                                                                    ? 'bg-emerald-700 text-white ring-2 ring-emerald-300'
                                                                    : 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200'}`}
                                                                onClick={() =>
                                                                    setSelectedHolidayId(
                                                                        item.holiday.id
                                                                    )
                                                                }
                                                                title={`Vacanza ${formatHolidayDate(
                                                                    item.holiday.holiday_date
                                                                )}`}
                                                            >
                                                                {item.day}
                                                            </button>
                                                        );
                                                    })
                                                )}
                                            </div>
                                        </article>
                                    ))}
                                </div>
                            </div>
                        ))}
                        {selectedHoliday && (
                            <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                    <p className="text-sm font-semibold text-slate-700">
                                        Giorno selezionato: {formatHolidayDate(selectedHoliday.holiday_date)}
                                    </p>
                                    <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">
                                        {selectedHoliday.source === 'pdf_import' ? 'PDF' : 'Manuale'}
                                    </span>
                                </div>
                                {editingHolidayId === selectedHoliday.id ? (
                                    <div className="space-y-3">
                                        <label className="flex flex-col gap-2 text-xs text-slate-600">
                                            Data
                                            <input
                                                type="date"
                                                className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                                                value={holidayEditForm.data.holiday_date}
                                                onChange={(event) =>
                                                    holidayEditForm.setData(
                                                        'holiday_date',
                                                        event.target.value
                                                    )
                                                }
                                            />
                                        </label>
                                        <label className="flex flex-col gap-2 text-xs text-slate-600">
                                            Descrizione
                                            <input
                                                type="text"
                                                maxLength={255}
                                                className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                                                value={holidayEditForm.data.label}
                                                onChange={(event) =>
                                                    holidayEditForm.setData(
                                                        'label',
                                                        event.target.value
                                                    )
                                                }
                                                placeholder="Es. Ponte, festivita cantonale..."
                                            />
                                        </label>
                                        {(holidayEditForm.errors.holiday_date
                                            || holidayEditForm.errors.label) && (
                                                <p className="text-xs text-rose-600">
                                                    {holidayEditForm.errors.holiday_date
                                                        || holidayEditForm.errors.label}
                                                </p>
                                            )}
                                        <div className="flex items-center gap-2">
                                            <button
                                                type="button"
                                                className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700"
                                                onClick={submitHolidayEdit}
                                                disabled={holidayEditForm.processing}
                                            >
                                                Salva
                                            </button>
                                            <button
                                                type="button"
                                                className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700"
                                                onClick={cancelHolidayEdit}
                                                disabled={holidayEditForm.processing}
                                            >
                                                Annulla
                                            </button>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="space-y-3">
                                        <p className="text-sm text-slate-600">
                                            {selectedHoliday.label || 'Nessuna descrizione'}
                                        </p>
                                        <div className="flex items-center gap-2">
                                            <button
                                                type="button"
                                                className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700"
                                                onClick={() => openHolidayEdit(selectedHoliday)}
                                            >
                                                Modifica
                                            </button>
                                            <button
                                                type="button"
                                                className="rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-700"
                                                onClick={() => deleteHoliday(selectedHoliday.id)}
                                                disabled={holidayDeleteForm.processing}
                                            >
                                                Elimina
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}
                        <details className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                            <summary className="cursor-pointer px-4 py-3 text-sm font-semibold text-slate-700">
                                Vista elenco dettagliato
                            </summary>
                            <div className="mt-3 space-y-3 px-3 pb-3">
                                {holidaysByYear.map((group) => (
                            <div
                                key={group.schoolYear}
                                className="overflow-hidden rounded-2xl border border-slate-200"
                            >
                                <div className="border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Anno scolastico {group.schoolYear}
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[720px] text-sm">
                                        <thead className="bg-white text-xs uppercase tracking-wide text-slate-400">
                                            <tr>
                                                <th className="px-3 py-2 text-left">Data</th>
                                                <th className="px-3 py-2 text-left">Descrizione</th>
                                                <th className="px-3 py-2 text-left">Origine</th>
                                                <th className="px-3 py-2 text-left">Azioni</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100 bg-white">
                                            {group.items.map((holiday) => (
                                                <tr key={holiday.id}>
                                                    <td className="px-3 py-2 align-top text-slate-700">
                                                        {editingHolidayId === holiday.id ? (
                                                            <input
                                                                type="date"
                                                                className="rounded-lg border border-slate-200 px-2 py-1 text-sm"
                                                                value={holidayEditForm.data.holiday_date}
                                                                onChange={(event) =>
                                                                    holidayEditForm.setData(
                                                                        'holiday_date',
                                                                        event.target.value
                                                                    )
                                                                }
                                                            />
                                                        ) : (
                                                            formatHolidayDate(holiday.holiday_date)
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2 align-top text-slate-700">
                                                        {editingHolidayId === holiday.id ? (
                                                            <input
                                                                type="text"
                                                                maxLength={255}
                                                                className="w-full rounded-lg border border-slate-200 px-2 py-1 text-sm"
                                                                value={holidayEditForm.data.label}
                                                                onChange={(event) =>
                                                                    holidayEditForm.setData(
                                                                        'label',
                                                                        event.target.value
                                                                    )
                                                                }
                                                            />
                                                        ) : (
                                                            holiday.label || '-'
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2 align-top text-slate-700">
                                                        <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">
                                                            {holiday.source === 'pdf_import' ? 'PDF' : 'Manuale'}
                                                        </span>
                                                    </td>
                                                    <td className="px-3 py-2 align-top text-slate-700">
                                                        {editingHolidayId === holiday.id ? (
                                                            <div className="flex items-center gap-2">
                                                                <button
                                                                    type="button"
                                                                    className="rounded-lg border border-slate-200 px-2 py-1 text-xs font-semibold text-slate-700"
                                                                    onClick={submitHolidayEdit}
                                                                    disabled={holidayEditForm.processing}
                                                                >
                                                                    Salva
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    className="rounded-lg border border-slate-200 px-2 py-1 text-xs font-semibold text-slate-700"
                                                                    onClick={cancelHolidayEdit}
                                                                    disabled={holidayEditForm.processing}
                                                                >
                                                                    Annulla
                                                                </button>
                                                            </div>
                                                        ) : (
                                                            <div className="flex items-center gap-2">
                                                                <button
                                                                    type="button"
                                                                    className="rounded-lg border border-slate-200 px-2 py-1 text-xs font-semibold text-slate-700"
                                                                    onClick={() => openHolidayEdit(holiday)}
                                                                >
                                                                    Modifica
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    className="rounded-lg border border-rose-200 px-2 py-1 text-xs font-semibold text-rose-700"
                                                                    onClick={() => deleteHoliday(holiday.id)}
                                                                    disabled={holidayDeleteForm.processing}
                                                                >
                                                                    Elimina
                                                                </button>
                                                            </div>
                                                        )}
                                                        {editingHolidayId === holiday.id
                                                            && (holidayEditForm.errors.holiday_date
                                                                || holidayEditForm.errors.label) && (
                                                                <p className="mt-1 text-xs text-rose-600">
                                                                    {holidayEditForm.errors.holiday_date
                                                                        || holidayEditForm.errors.label}
                                                                </p>
                                                            )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                                ))}
                            </div>
                        </details>
                    </div>
                </SectionPanel>

                <SectionPanel
                    id="config-ritardi"
                    title="Ritardi"
                    description="Conversione in assenza, firme e azioni automatiche."
                    meta={
                        <div className="flex flex-wrap gap-2">
                            <SummaryPill
                                label="Soglia"
                                value={`${data.delay.minutes_threshold} min`}
                                tone="amber"
                            />
                            <SummaryPill
                                label="Regole"
                                value={data.delay_rules.length}
                                tone="sky"
                            />
                        </div>
                    }
                >
                    <div className="grid gap-4 xl:grid-cols-[360px_minmax(0,1fr)]">
                        <div className="space-y-4">
                            <SettingGroup title="Regole base">
                                <div className="grid gap-3">
                                    <Field label="Minuti per 1 ora assenza">
                                        <input
                                            className={fieldClass}
                                            type="number"
                                            min="0"
                                            value={data.delay.minutes_threshold}
                                            onChange={(event) =>
                                                updateDelay(
                                                    'minutes_threshold',
                                                    Number(event.target.value)
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Firma tutore">
                                        <select
                                            className={selectClass}
                                            value={
                                                data.delay.guardian_signature_required
                                                    ? 'yes'
                                                    : 'no'
                                            }
                                            onChange={(event) =>
                                                updateDelay(
                                                    'guardian_signature_required',
                                                    event.target.value === 'yes'
                                                )
                                            }
                                        >
                                            <option value="yes">Si</option>
                                            <option value="no">No</option>
                                        </select>
                                    </Field>
                                </div>
                            </SettingGroup>

                            <SettingGroup title="Scadenze">
                                <div className="grid gap-3">
                                    <Field label="Scadenza ritardi">
                                        <select
                                            className={selectClass}
                                            value={data.delay.deadline_active ? 'yes' : 'no'}
                                            onChange={(event) =>
                                                updateDelay(
                                                    'deadline_active',
                                                    event.target.value === 'yes'
                                                )
                                            }
                                        >
                                            <option value="yes">Si</option>
                                            <option value="no">No</option>
                                        </select>
                                    </Field>
                                    <Field label="Giorni limite">
                                        <input
                                            className={fieldClass}
                                            type="number"
                                            min="0"
                                            max="30"
                                            value={data.delay.deadline_business_days}
                                            onChange={(event) =>
                                                updateDelay(
                                                    'deadline_business_days',
                                                    Number(event.target.value)
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Avviso scadenza %">
                                        <input
                                            className={fieldClass}
                                            type="number"
                                            min="1"
                                            max="100"
                                            value={data.delay.pre_expiry_warning_percent}
                                            onChange={(event) =>
                                                updateDelay(
                                                    'pre_expiry_warning_percent',
                                                    Number(event.target.value)
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                            </SettingGroup>
                        </div>

                        <SettingGroup
                            title="Soglie con azioni informative"
                            action={
                                <button
                                    type="button"
                                    className="btn-soft-neutral"
                                    onClick={addRule}
                                >
                                    Aggiungi regola
                                </button>
                            }
                        >
                            <div className="space-y-2">
                                {data.delay_rules.map((rule, ruleIndex) => (
                                    <details
                                        key={`rule-${ruleIndex}`}
                                        className="rounded-xl border border-slate-200 bg-white"
                                        open={ruleIndex === 0}
                                    >
                                        <summary className="flex cursor-pointer items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-800">
                                            <span>
                                                Regola {ruleIndex + 1}: {rule.min_delays} -{' '}
                                                {rule.max_delays === null
                                                    ? 'oltre'
                                                    : rule.max_delays}{' '}
                                                ritardi
                                            </span>
                                        </summary>
                                        <div className="space-y-3 border-t border-slate-100 p-4">
                                            <div className="grid gap-3 md:grid-cols-[120px_140px_auto]">
                                                <Field label="Da">
                                                    <input
                                                        className={fieldClass}
                                                        type="number"
                                                        min="0"
                                                        value={rule.min_delays}
                                                        onChange={(event) =>
                                                            updateRule(
                                                                ruleIndex,
                                                                'min_delays',
                                                                Number(event.target.value)
                                                            )
                                                        }
                                                    />
                                                </Field>
                                                <Field label="Fino a">
                                                    <input
                                                        className={fieldClass}
                                                        type="number"
                                                        min="0"
                                                        value={
                                                            rule.max_delays === null
                                                                ? ''
                                                                : rule.max_delays
                                                        }
                                                        onChange={(event) =>
                                                            updateRule(
                                                                ruleIndex,
                                                                'max_delays',
                                                                event.target.value === ''
                                                                    ? null
                                                                    : Number(event.target.value)
                                                            )
                                                        }
                                                        placeholder="oltre"
                                                    />
                                                </Field>
                                                <div className="flex items-end justify-end">
                                                    <button
                                                        type="button"
                                                        className="btn-soft-danger h-10"
                                                        onClick={() => removeRule(ruleIndex)}
                                                    >
                                                        Rimuovi regola
                                                    </button>
                                                </div>
                                            </div>

                                            <div className="space-y-2">
                                                {rule.actions.map((action, actionIndex) => (
                                                    <div
                                                        key={`rule-${ruleIndex}-action-${actionIndex}`}
                                                        className="grid gap-2 md:grid-cols-[minmax(240px,0.9fr)_minmax(0,1fr)_auto]"
                                                    >
                                                        <select
                                                            className={selectClass}
                                                            value={action.type}
                                                            onChange={(event) =>
                                                                updateAction(
                                                                    ruleIndex,
                                                                    actionIndex,
                                                                    'type',
                                                                    event.target.value
                                                                )
                                                            }
                                                        >
                                                            {ACTION_OPTIONS.map((option) => (
                                                                <option
                                                                    key={option.value}
                                                                    value={option.value}
                                                                >
                                                                    {option.label}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        {action.type === 'conduct_penalty' ? (
                                                            <input
                                                                className={fieldClass}
                                                                value={action.detail ?? ''}
                                                                onChange={(event) =>
                                                                    updateAction(
                                                                        ruleIndex,
                                                                        actionIndex,
                                                                        'detail',
                                                                        event.target.value
                                                                    )
                                                                }
                                                                placeholder="Testo penalita"
                                                            />
                                                        ) : (
                                                            <span className="hidden h-10 rounded-lg border border-dashed border-slate-200 bg-slate-50 md:block" />
                                                        )}
                                                        <button
                                                            type="button"
                                                            className="btn-soft-neutral h-10"
                                                            onClick={() =>
                                                                removeAction(
                                                                    ruleIndex,
                                                                    actionIndex
                                                                )
                                                            }
                                                        >
                                                            Rimuovi
                                                        </button>
                                                    </div>
                                                ))}
                                                <button
                                                    type="button"
                                                    className="btn-soft-neutral"
                                                    onClick={() => addAction(ruleIndex)}
                                                >
                                                    Aggiungi azione
                                                </button>
                                            </div>
                                            <Field label="Messaggio informativo">
                                                <input
                                                    className={`${fieldClass} w-full`}
                                                    value={rule.info_message ?? ''}
                                                    onChange={(event) =>
                                                        updateRule(
                                                            ruleIndex,
                                                            'info_message',
                                                            event.target.value
                                                        )
                                                    }
                                                    placeholder="Messaggio opzionale"
                                                />
                                            </Field>
                                        </div>
                                    </details>
                                ))}
                            </div>
                        </SettingGroup>
                    </div>
                </SectionPanel>

                <SectionPanel
                    id="config-sicurezza"
                    title="Sistema"
                    description="Retention log, limiti login e recupero password."
                    meta={
                        <SummaryPill
                            label="Log"
                            value={`${data.logs.interaction_retention_days}/${data.logs.error_retention_days} giorni`}
                            tone="slate"
                        />
                    }
                >
                    <div className="grid gap-4 xl:grid-cols-3">
                        <SettingGroup title="Retention log">
                            <div className="grid gap-3">
                                <Field
                                    label="Interazioni"
                                    hint={`Consigliato ${recommendedInteractionRetentionDays}`}
                                >
                                    <input
                                        className={fieldClass}
                                        type="number"
                                        min="1"
                                        max="3650"
                                        value={data.logs.interaction_retention_days}
                                        onChange={(event) =>
                                            updateLogs(
                                                'interaction_retention_days',
                                                Number(event.target.value)
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Errori e avvisi"
                                    hint={`Consigliato ${recommendedErrorRetentionDays}`}
                                >
                                    <input
                                        className={fieldClass}
                                        type="number"
                                        min="1"
                                        max="3650"
                                        value={data.logs.error_retention_days}
                                        onChange={(event) =>
                                            updateLogs(
                                                'error_retention_days',
                                                Number(event.target.value)
                                            )
                                        }
                                    />
                                </Field>
                            </div>
                        </SettingGroup>

                        <SettingGroup title="Login">
                            <div className="grid gap-3">
                                <Field label="Tentativi massimi" hint="Range 3-10">
                                    <input
                                        className={fieldClass}
                                        type="number"
                                        min="3"
                                        max="10"
                                        value={data.login.max_attempts}
                                        onChange={(event) =>
                                            updateLogin(
                                                'max_attempts',
                                                Number(event.target.value)
                                            )
                                        }
                                    />
                                </Field>
                                <Field label="Timeout blocco" hint="Secondi, range 60-1800">
                                    <input
                                        className={fieldClass}
                                        type="number"
                                        min="60"
                                        max="1800"
                                        value={data.login.decay_seconds}
                                        onChange={(event) =>
                                            updateLogin(
                                                'decay_seconds',
                                                Number(event.target.value)
                                            )
                                        }
                                    />
                                </Field>
                            </div>
                        </SettingGroup>

                        <SettingGroup title="Recupero password">
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                                <Field label="Forgot: tentativi" hint="Range 3-20">
                                    <input
                                        className={fieldClass}
                                        type="number"
                                        min="3"
                                        max="20"
                                        value={data.login.forgot_password_max_attempts}
                                        onChange={(event) =>
                                            updateLogin(
                                                'forgot_password_max_attempts',
                                                Number(event.target.value)
                                            )
                                        }
                                    />
                                </Field>
                                <Field label="Forgot: timeout" hint="Secondi, 60-1800">
                                    <input
                                        className={fieldClass}
                                        type="number"
                                        min="60"
                                        max="1800"
                                        value={data.login.forgot_password_decay_seconds}
                                        onChange={(event) =>
                                            updateLogin(
                                                'forgot_password_decay_seconds',
                                                Number(event.target.value)
                                            )
                                        }
                                    />
                                </Field>
                                <Field label="Reset: tentativi" hint="Range 3-20">
                                    <input
                                        className={fieldClass}
                                        type="number"
                                        min="3"
                                        max="20"
                                        value={data.login.reset_password_max_attempts}
                                        onChange={(event) =>
                                            updateLogin(
                                                'reset_password_max_attempts',
                                                Number(event.target.value)
                                            )
                                        }
                                    />
                                </Field>
                                <Field label="Reset: timeout" hint="Secondi, 60-1800">
                                    <input
                                        className={fieldClass}
                                        type="number"
                                        min="60"
                                        max="1800"
                                        value={data.login.reset_password_decay_seconds}
                                        onChange={(event) =>
                                            updateLogin(
                                                'reset_password_decay_seconds',
                                                Number(event.target.value)
                                            )
                                        }
                                    />
                                </Field>
                            </div>
                        </SettingGroup>
                    </div>
                </SectionPanel>

                <div className="sticky bottom-4 z-20 rounded-2xl border border-slate-200 bg-white/95 p-4 shadow-lg backdrop-blur">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-slate-600">
                            Salva tutte le modifiche
                        </p>
                        <button
                            type="submit"
                            className="btn-soft-neutral h-10 rounded-xl px-5 text-sm"
                            disabled={processing}
                        >
                            {processing ? 'Salvataggio...' : 'Salva tutto'}
                        </button>
                    </div>
                </div>
            </form>

            {isConfirmModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 px-4">
                    <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
                        <h3 className="text-base font-semibold text-slate-900">
                            Conferma salvataggio configurazione
                        </h3>
                        <p className="mt-2 text-sm text-slate-600">
                            Stai per applicare tutte le modifiche alle regole.
                            Vuoi continuare?
                        </p>
                        <div className="mt-5 flex justify-end gap-2">
                            <button
                                type="button"
                                className="btn-soft-neutral px-4"
                                onClick={closeConfirmModal}
                                disabled={processing}
                            >
                                Annulla
                            </button>
                            <button
                                type="button"
                                className="btn-soft-info px-4"
                                onClick={confirmSaveSettings}
                                disabled={processing}
                            >
                                {processing ? 'Salvataggio...' : 'Conferma e salva'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
