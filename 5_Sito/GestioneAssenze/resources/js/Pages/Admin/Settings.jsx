import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

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
                for (let day = 1; day <= daysInMonth; day += 1) {
                    const dateKey = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                    const holiday = holidaysByDate.get(dateKey) ?? null;
                    if (holiday) {
                        monthHolidayCount += 1;
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

            <form onSubmit={submitSettings} className="space-y-6">
                <section
                    id="config-absenze"
                    className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7"
                >
                    <h2 className="text-lg font-semibold text-slate-900">
                        Regole assenze
                    </h2>
                    <p className="text-sm text-slate-500">
                        Parametri principali per il calcolo delle ore.
                    </p>
                    <p className="text-xs text-slate-500">
                        Le soglie sono usate solo per segnalazioni e verifiche.
                    </p>
                    <div className="mt-4 space-y-4">
                        <div className="space-y-3 text-sm text-slate-600">
                            <label className="flex flex-col gap-2">
                                Numero massimo di ore annuali disponibili
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
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
                            </label>
                            <label className="flex flex-col gap-2">
                                Soglia di ore per segnalazione superamento imminente
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
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
                            </label>
                            <label className="flex flex-col gap-2">
                                Email vicedirettore (avviso congedi oltre limite ore)
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
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
                            </label>
                            <label className="flex flex-col gap-2">
                                Firma tutore obbligatoria
                                <select
                                    className="rounded-lg border border-slate-200 px-3 py-2 pr-8 text-sm"
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
                            </label>
                            <label className="flex flex-col gap-2">
                                Numero di giorni per la consegna del certificato medico
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
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
                            </label>
                            <label className="flex flex-col gap-2">
                                Numero massimo di giorni per certificato medico
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
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
                            </label>
                            <label className="flex flex-col gap-2">
                                Numero di giorni lavorativi di countdown per assenza
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
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
                            </label>
                            <label className="flex flex-col gap-2">
                                Ore lavorative minime anticipo richiesta congedo
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                                    type="number"
                                    min="0"
                                    max="240"
                                    value={data.absence.leave_request_notice_working_hours}
                                    onChange={(event) =>
                                        updateAbsence(
                                            'leave_request_notice_working_hours',
                                            Number(event.target.value)
                                        )
                                    }
                                />
                            </label>
                            <div className="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <p className="text-sm font-semibold text-slate-700">
                                    Motivazioni predefinite
                                </p>
                                <p className="text-xs text-slate-500">
                                    Legenda: Conta = entra nelle 40 ore, Esclusa =
                                    non entra nelle 40 ore.
                                </p>
                                <p className="text-xs text-slate-500">
                                    Caso particolare = sui congedi richiede consenso
                                    direzione prima dell invio.
                                </p>
                                {data.reasons.map((reason, index) => (
                                    <article
                                        key={`reason-${index}`}
                                        className="space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-2.5"
                                    >
                                        <div className="grid gap-2 md:grid-cols-[minmax(0,1.5fr)_140px_auto_auto]">
                                            <input
                                                className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"
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
                                            <select
                                                className="rounded-lg border border-slate-200 px-3 py-2 pr-8 text-sm"
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
                                                <option value="out">Esclusa</option>
                                            </select>
                                            <label className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
                                                <input
                                                    type="checkbox"
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
                                                Speciale
                                            </label>
                                            <button
                                                type="button"
                                                className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600"
                                                onClick={() => removeReason(index)}
                                            >
                                                Rimuovi
                                            </button>
                                        </div>
                                        {Boolean(reason.requires_management_consent) && (
                                            <div className="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-2.5">
                                                <label className="flex items-center gap-2 text-xs text-slate-700">
                                                    <input
                                                        type="checkbox"
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
                                                    Richiedi documento subito nel congedo
                                                </label>
                                                <label className="flex flex-col gap-1 text-xs text-slate-700">
                                                    Commento/nota per allievo (max 2000)
                                                    <textarea
                                                        rows="5"
                                                        className="rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm text-slate-700"
                                                        value={reason.management_consent_note ?? ''}
                                                        onChange={(event) =>
                                                            updateReason(
                                                                index,
                                                                'management_consent_note',
                                                                event.target.value
                                                            )
                                                        }
                                                        maxLength={2000}
                                                        placeholder="Es: Porta il modulo firmato dalla direzione."
                                                    />
                                                </label>
                                            </div>
                                        )}
                                    </article>
                                ))}
                                <button
                                    type="button"
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-600"
                                    onClick={addReason}
                                >
                                    Aggiungi motivazione
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <section
                    id="config-vacanze"
                    className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7"
                >
                    <h2 className="text-lg font-semibold text-slate-900">
                        Vacanze scolastiche
                    </h2>
                    <p className="text-sm text-slate-500">
                        Import da PDF calendario ufficiale e gestione manuale delle date.
                    </p>
                    <p className="text-xs text-slate-500">
                        Queste date vengono escluse dai calcoli di giorni/ore lavorative per assenze e congedi.
                    </p>

                    <div className="mt-4 grid gap-4 lg:grid-cols-2">
                        <div className="space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <p className="text-sm font-semibold text-slate-700">
                                Importa calendario PDF
                            </p>
                            <input
                                type="file"
                                accept=".pdf,application/pdf"
                                className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"
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
                                className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700"
                                onClick={submitHolidayImport}
                                disabled={holidayImportForm.processing}
                            >
                                {holidayImportForm.processing
                                    ? 'Import in corso...'
                                    : 'Importa e aggiorna vacanze'}
                            </button>
                        </div>

                        <div className="space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <p className="text-sm font-semibold text-slate-700">
                                Aggiungi data manuale
                            </p>
                            <label className="flex flex-col gap-2 text-xs text-slate-600">
                                Data vacanza
                                <input
                                    type="date"
                                    className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"
                                    value={holidayCreateForm.data.holiday_date}
                                    onChange={(event) =>
                                        holidayCreateForm.setData(
                                            'holiday_date',
                                            event.target.value
                                        )
                                    }
                                />
                            </label>
                            <label className="flex flex-col gap-2 text-xs text-slate-600">
                                Descrizione (facoltativa)
                                <input
                                    type="text"
                                    maxLength={255}
                                    className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"
                                    value={holidayCreateForm.data.label}
                                    onChange={(event) =>
                                        holidayCreateForm.setData('label', event.target.value)
                                    }
                                    placeholder="Es. Ponte, festivita cantonale..."
                                />
                            </label>
                            {(holidayCreateForm.errors.holiday_date || holidayCreateForm.errors.label) && (
                                <p className="text-xs text-rose-600">
                                    {holidayCreateForm.errors.holiday_date
                                        || holidayCreateForm.errors.label}
                                </p>
                            )}
                            <button
                                type="button"
                                className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700"
                                onClick={submitHolidayCreate}
                                disabled={holidayCreateForm.processing}
                            >
                                {holidayCreateForm.processing ? 'Salvataggio...' : 'Aggiungi data'}
                            </button>
                        </div>
                    </div>

                    <div className="mt-4 space-y-3">
                        <p className="text-sm font-semibold text-slate-700">
                            Date registrate ({holidays.length})
                        </p>
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
                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    {group.months.map((month) => (
                                        <article
                                            key={month.id}
                                            className="rounded-xl border border-slate-200 bg-white p-2.5"
                                        >
                                            <div className="mb-2 flex items-center justify-between gap-2">
                                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                                    {month.label}
                                                </p>
                                                <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                                                    {month.holidayCount}
                                                </span>
                                            </div>
                                            <div className="grid grid-cols-7 gap-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                                                {WEEKDAY_LABELS.map((label) => (
                                                    <span
                                                        key={`${month.id}-${label}`}
                                                        className="text-center"
                                                    >
                                                        {label}
                                                    </span>
                                                ))}
                                            </div>
                                            <div className="mt-1 grid grid-cols-7 gap-1">
                                                {month.cells.map((cell) => {
                                                    if (cell.kind === 'placeholder') {
                                                        return (
                                                            <span
                                                                key={cell.id}
                                                                className="h-7 rounded-md"
                                                            />
                                                        );
                                                    }
                                                    if (cell.kind === 'holiday' && cell.holiday) {
                                                        const isSelected =
                                                            selectedHolidayId === cell.holiday.id;
                                                        return (
                                                            <button
                                                                key={cell.id}
                                                                type="button"
                                                                className={`h-7 rounded-md text-xs font-semibold transition ${isSelected
                                                                    ? 'bg-emerald-700 text-white ring-2 ring-emerald-300'
                                                                    : 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200'}`}
                                                                onClick={() =>
                                                                    setSelectedHolidayId(cell.holiday.id)
                                                                }
                                                                title={`Vacanza ${formatHolidayDate(
                                                                    cell.holiday.holiday_date
                                                                )}`}
                                                            >
                                                                {cell.day}
                                                            </button>
                                                        );
                                                    }

                                                    return (
                                                        <span
                                                            key={cell.id}
                                                            className="h-7 rounded-md bg-slate-100/70 text-center text-xs leading-7 text-slate-500"
                                                        >
                                                            {cell.day}
                                                        </span>
                                                    );
                                                })}
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
                </section>

                <section
                    id="config-ritardi"
                    className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7"
                >
                    <h2 className="text-lg font-semibold text-slate-900">
                        Regole ritardi
                    </h2>
                    <p className="text-sm text-slate-500">
                        Definisci la soglia di conversione in assenza e le azioni automatiche dei ritardi.
                    </p>
                    <p className="text-xs text-slate-500">
                        Se il ritardo supera la soglia, viene aggiunta automaticamente 1 ora di assenza.
                    </p>
                    <div className="mt-4 space-y-4">
                        <div className="space-y-3 text-sm text-slate-600">
                            <label className="flex flex-col gap-2">
                                Soglia minuti ritardo per aggiungere 1 ora di assenza
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
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
                            </label>
                            <label className="flex flex-col gap-2">
                                Firma tutore obbligatoria sui ritardi
                                <select
                                    className="rounded-lg border border-slate-200 px-3 py-2 pr-8 text-sm"
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
                            </label>
                            <label className="flex flex-col gap-2">
                                Attiva scadenza ritardi registrati
                                <select
                                    className="rounded-lg border border-slate-200 px-3 py-2 pr-8 text-sm"
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
                            </label>
                            <label className="flex flex-col gap-2">
                                Giorni lavorativi limite ritardo registrato
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
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
                            </label>
                            <div className="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <p className="text-sm font-semibold text-slate-700">
                                    Soglie di ritardi con azioni informative
                                </p>
                                {data.delay_rules.map((rule, ruleIndex) => (
                                    <details
                                        key={`rule-${ruleIndex}`}
                                        className="rounded-xl border border-slate-200 p-3"
                                        open={ruleIndex === 0}
                                    >
                                        <summary className="cursor-pointer text-sm font-semibold text-slate-700">
                                            Regola {ruleIndex + 1}: {rule.min_delays} -{' '}
                                            {rule.max_delays === null ? 'oltre' : rule.max_delays}{' '}
                                            ritardi
                                        </summary>
                                        <div className="mt-3 space-y-3">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <label className="flex flex-col gap-1 text-xs text-slate-500">
                                                    Ritardi da
                                                    <input
                                                        className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700"
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
                                                </label>
                                                <label className="flex flex-col gap-1 text-xs text-slate-500">
                                                    Ritardi fino a
                                                    <input
                                                        className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700"
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
                                                        placeholder="(vuoto = oltre)"
                                                    />
                                                </label>
                                                <button
                                                    type="button"
                                                    className="rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-600"
                                                    onClick={() => removeRule(ruleIndex)}
                                                >
                                                    Rimuovi regola
                                                </button>
                                            </div>
                                            <div className="space-y-2">
                                                {rule.actions.map((action, actionIndex) => (
                                                    <div
                                                        key={`rule-${ruleIndex}-action-${actionIndex}`}
                                                        className="flex flex-wrap items-center gap-2"
                                                    >
                                                        <select
                                                            className="rounded-lg border border-slate-200 px-3 py-2 pr-8 text-sm"
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
                                                        {action.type === 'conduct_penalty' && (
                                                            <input
                                                                className="flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm"
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
                                                        )}
                                                        <button
                                                            type="button"
                                                            className="rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-600"
                                                            onClick={() =>
                                                                removeAction(
                                                                    ruleIndex,
                                                                    actionIndex
                                                                )
                                                            }
                                                        >
                                                            Rimuovi azione
                                                        </button>
                                                    </div>
                                                ))}
                                                <button
                                                    type="button"
                                                    className="rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-600"
                                                    onClick={() => addAction(ruleIndex)}
                                                >
                                                    Aggiungi azione
                                                </button>
                                            </div>
                                            <label className="flex flex-col gap-2 text-xs text-slate-500">
                                                Messaggio informativo
                                                <input
                                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700"
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
                                            </label>
                                        </div>
                                    </details>
                                ))}
                                <button
                                    type="button"
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-600"
                                    onClick={addRule}
                                >
                                    Aggiungi regola
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <section
                    id="config-sicurezza"
                    className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7"
                >
                    <h2 className="text-lg font-semibold text-slate-900">
                        Retention log
                    </h2>
                    <p className="text-sm text-slate-500">
                        Giorni di conservazione per interazioni ed errori.
                    </p>
                    <p className="text-xs text-slate-500">
                        Pulizia automatica notturna.
                    </p>
                    <div className="mt-4 space-y-4">
                        <div className="space-y-3 text-sm text-slate-600">
                            <label className="flex flex-col gap-2">
                                Giorni di conservazione log interazioni (INFO, consigliato {recommendedInteractionRetentionDays})
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
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
                            </label>
                            <label className="flex flex-col gap-2">
                                Giorni di conservazione log errori/avvisi (ERROR/WARNING, consigliato {recommendedErrorRetentionDays})
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
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
                            </label>
                            <hr className="border-slate-200" />
                            <p className="text-sm font-semibold text-slate-700">
                                Sicurezza login
                            </p>
                            <p className="text-xs text-slate-500">
                                Override admin dei limiti login. Base da `env/config`, consentito solo in range sicuro.
                            </p>
                            <label className="flex flex-col gap-2">
                                Tentativi massimi login (3-10)
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
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
                            </label>
                            <label className="flex flex-col gap-2">
                                Timeout blocco login in secondi (60-1800)
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
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
                            </label>
                            <hr className="border-slate-200" />
                            <p className="text-sm font-semibold text-slate-700">
                                Sicurezza recupero password
                            </p>
                            <p className="text-xs text-slate-500">
                                Throttle endpoint password dimenticata e reset password.
                            </p>
                            <label className="flex flex-col gap-2">
                                Forgot password: tentativi massimi (3-20)
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
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
                            </label>
                            <label className="flex flex-col gap-2">
                                Forgot password: timeout blocco in secondi (60-1800)
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
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
                            </label>
                            <label className="flex flex-col gap-2">
                                Reset password: tentativi massimi (3-20)
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
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
                            </label>
                            <label className="flex flex-col gap-2">
                                Reset password: timeout blocco in secondi (60-1800)
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
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
                            </label>
                        </div>
                    </div>
                </section>
                <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-slate-600">
                            Salva tutte le modifiche in un unico passaggio.
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
