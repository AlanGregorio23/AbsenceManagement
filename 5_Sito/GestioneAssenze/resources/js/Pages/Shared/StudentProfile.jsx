import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const formatBirthDate = (value) => {
    const raw = String(value ?? '').trim();
    if (raw === '') {
        return '-';
    }

    const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (match) {
        return `${match[3]}.${match[2]}.${match[1]}`;
    }

    const parsedDate = new Date(raw);
    if (Number.isNaN(parsedDate.getTime())) {
        return raw;
    }

    const day = String(parsedDate.getDate()).padStart(2, '0');
    const month = String(parsedDate.getMonth() + 1).padStart(2, '0');
    const year = String(parsedDate.getFullYear());

    return `${day}.${month}.${year}`;
};

function StatCard({ label, value, helper }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p className="text-xs uppercase tracking-wide text-slate-400">{label}</p>
            <p className="mt-2 text-2xl font-semibold text-slate-900">{value}</p>
            <p className="mt-1 text-xs text-slate-500">{helper}</p>
        </div>
    );
}

const exportSectionOptions = [
    { key: 'student', label: 'Dati studente' },
    { key: 'guardians', label: 'Tutori' },
    { key: 'summary', label: 'Riepilogo' },
    { key: 'absences', label: 'Assenze' },
    { key: 'delays', label: 'Ritardi' },
    { key: 'leaves', label: 'Congedi' },
];

const defaultExportSections = exportSectionOptions.reduce((selected, option) => {
    selected[option.key] = true;
    return selected;
}, {});

export default function StudentProfile({ profile }) {
    const [query, setQuery] = useState('');
    const [typeFilter, setTypeFilter] = useState('Tutti');
    const [isExportOpen, setIsExportOpen] = useState(false);
    const [exportSections, setExportSections] = useState(defaultExportSections);
    const [exportDateFrom, setExportDateFrom] = useState('');
    const [exportDateTo, setExportDateTo] = useState('');

    const records = Array.isArray(profile?.records) ? profile.records : [];
    const eventTypes = useMemo(() => {
        const uniqueTypes = Array.from(new Set(records.map((row) => row.type)));
        return ['Tutti', ...uniqueTypes];
    }, [records]);

    const filteredRecords = useMemo(() => {
        const normalizedQuery = query.trim().toLowerCase();

        return records.filter((row) => {
            const matchType = typeFilter === 'Tutti' || row.type === typeFilter;
            const searchableText = [
                row.record_id,
                row.type,
                row.detail,
                row.status,
                row.period,
            ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();
            const matchQuery =
                normalizedQuery.length === 0 ||
                searchableText.includes(normalizedQuery);

            return matchType && matchQuery;
        });
    }, [records, query, typeFilter]);

    const classes = Array.isArray(profile?.classes) ? profile.classes : [];
    const guardians = Array.isArray(profile?.guardians) ? profile.guardians : [];
    const guardianContact =
        profile?.guardian_contact ?? profile?.primary_guardian ?? guardians[0] ?? null;
    const stats = profile?.stats ?? {};
    const delayRuleInsights = profile?.delay_rule_insights ?? {};
    const delayActionLines = Array.isArray(delayRuleInsights?.action_lines)
        ? delayRuleInsights.action_lines
        : [];
    const recoveryEstimate =
        typeof delayRuleInsights?.recovery_estimate === 'object' &&
        delayRuleInsights?.recovery_estimate !== null
            ? delayRuleInsights.recovery_estimate
            : {};
    const recoveryEstimateLabel =
        typeof recoveryEstimate?.label === 'string' &&
        recoveryEstimate.label.trim() !== ''
            ? recoveryEstimate.label
            : 'Non previsto';
    const delayRuleRangeLabel =
        delayRuleInsights?.rule_id
            ? `${delayRuleInsights.rule_min_delays} - ${
                  delayRuleInsights.rule_max_delays === null
                      ? 'oltre'
                      : delayRuleInsights.rule_max_delays
              }`
            : 'Nessuna';
    const formattedBirthDate = formatBirthDate(profile?.birth_date);

    const pageTitle = profile?.full_name
        ? `Profilo ${profile.full_name}`
        : 'Profilo studente';
    const selectedExportSections = exportSectionOptions
        .filter((option) => Boolean(exportSections[option.key]))
        .map((option) => option.key);
    const allExportSectionsSelected =
        selectedExportSections.length === exportSectionOptions.length;
    const exportUrl = useMemo(() => {
        if (!profile?.student_id) {
            return '#';
        }

        const params = new URLSearchParams();
        const sections = allExportSectionsSelected || selectedExportSections.length === 0
            ? ['all']
            : selectedExportSections;

        sections.forEach((section) => {
            params.append('sections[]', section);
        });

        if (exportDateFrom) {
            params.set('date_from', exportDateFrom);
        }

        if (exportDateTo) {
            params.set('date_to', exportDateTo);
        }

        const queryString = params.toString();

        return `${route('students.profile.export', profile.student_id)}${
            queryString ? `?${queryString}` : ''
        }`;
    }, [
        allExportSectionsSelected,
        exportDateFrom,
        exportDateTo,
        profile?.student_id,
        selectedExportSections,
    ]);
    const toggleExportSection = (sectionKey) => {
        setExportSections((current) => ({
            ...current,
            [sectionKey]: !current[sectionKey],
        }));
    };
    const toggleAllExportSections = () => {
        const nextValue = !allExportSectionsSelected;
        setExportSections(
            exportSectionOptions.reduce((selected, option) => {
                selected[option.key] = nextValue;
                return selected;
            }, {})
        );
    };

    return (
        <AuthenticatedLayout header={pageTitle}>
            <Head title={pageTitle} />

            <div className="space-y-6">
                <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-xs uppercase tracking-[0.25em] text-slate-500">
                                {profile?.code ?? ''}
                            </p>
                            <h2 className="mt-2 text-3xl font-semibold text-slate-900">
                                {profile?.full_name ?? 'Studente'}
                            </h2>
                            <p className="mt-2 text-sm text-slate-600">
                                {profile?.email ?? '-'}
                            </p>
                            <p className="mt-1 text-sm text-slate-600">
                                Data di nascita: {formattedBirthDate}
                            </p>
                            <div className="mt-3 flex flex-wrap gap-2">
                                {classes.length === 0 && (
                                    <span className="rounded-full border border-slate-200 px-3 py-1 text-xs text-slate-500">
                                        Nessuna classe
                                    </span>
                                )}
                                {classes.map((classLabel) => (
                                    <span
                                        key={classLabel}
                                        className="rounded-full border border-slate-200 bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700"
                                    >
                                        {classLabel}
                                    </span>
                                ))}
                            </div>
                        </div>

                        <div className="flex w-full flex-col gap-3 sm:w-auto sm:min-w-[260px]">
                            <button
                                type="button"
                                className="btn-soft-info w-full justify-center"
                                onClick={() => setIsExportOpen(true)}
                            >
                                Export dati studente
                            </button>

                            <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <p className="text-xs uppercase tracking-wide text-slate-500">
                                    Contatto tutore
                                </p>
                                <p className="mt-1 text-sm font-semibold text-slate-900">
                                    {guardianContact?.name ?? '-'}
                                </p>
                                <p className="text-xs text-slate-500">
                                    {guardianContact?.relationship ?? ''}
                                </p>
                                <p className="mt-1 text-xs text-slate-600">
                                    {guardianContact?.email ?? ''}
                                </p>
                                <p className="mt-3 text-xs uppercase tracking-wide text-slate-500">
                                    Tutori collegati: {guardians.length}
                                </p>
                                <div className="mt-2 max-h-24 space-y-1 overflow-y-auto pr-1">
                                    {guardians.map((guardian) => (
                                        <p
                                            key={guardian.id}
                                            className="text-xs text-slate-600"
                                        >
                                            {guardian.name}
                                            {guardian.relationship
                                                ? ` (${guardian.relationship})`
                                                : ''}
                                        </p>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Ore assenze"
                        value={stats.absence_hours_total ?? 0}
                        helper={`${stats.absences_total ?? 0} eventi assenza`}
                    />
                    <StatCard
                        label="Ore su 40"
                        value={stats.hours_on_40 ?? 0}
                        helper="Conteggiate nel monte ore"
                    />
                    <StatCard
                        label="Ore arbitrarie"
                        value={stats.arbitrary_hours ?? 0}
                        helper={`${stats.absences_arbitrary ?? 0} assenze arbitrarie`}
                    />
                    <StatCard
                        label="Ritardi"
                        value={stats.delays_total ?? 0}
                        helper={`${stats.delay_minutes_total ?? 0} minuti totali ritardo`}
                    />
                </section>

                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <h3 className="text-lg font-semibold text-slate-900">
                            Segnalazioni ritardi
                        </h3>
                        <div className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700">
                            Regola attiva: {delayRuleRangeLabel}
                        </div>
                    </div>

                    <div className="mt-4 grid gap-3 md:grid-cols-4">
                        <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <p className="text-xs uppercase tracking-wide text-slate-500">
                                Registrati semestre
                            </p>
                            <p className="mt-1 text-lg font-semibold text-slate-900">
                                {stats.delays_registered_semester ?? 0}
                            </p>
                        </div>
                        <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <p className="text-xs uppercase tracking-wide text-slate-500">
                                Non registrati semestre
                            </p>
                            <p className="mt-1 text-lg font-semibold text-slate-900">
                                {stats.delays_unregistered_semester ?? 0}
                            </p>
                        </div>
                        <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <p className="text-xs uppercase tracking-wide text-slate-500">
                                Minuti registrati
                            </p>
                            <p className="mt-1 text-lg font-semibold text-slate-900">
                                {delayRuleInsights?.registered_minutes ?? 0}
                            </p>
                        </div>
                        <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <p className="text-xs uppercase tracking-wide text-slate-500">
                                Recupero previsto
                            </p>
                            <p className="mt-1 text-sm font-semibold text-slate-900">
                                {recoveryEstimateLabel}
                            </p>
                        </div>
                    </div>

                    {delayActionLines.length > 0 && (
                        <div className="mt-4 overflow-x-auto rounded-xl border border-slate-200">
                            <table className="w-full text-sm">
                                <thead className="text-xs uppercase tracking-wide text-slate-400">
                                    <tr>
                                        <th className="px-3 py-2 text-center align-middle">#</th>
                                        <th className="px-3 py-2 text-center align-middle">Azione prevista</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 text-slate-700">
                                    {delayActionLines.map((line, index) => (
                                        <tr key={line}>
                                            <td className="px-3 py-2 text-center align-middle text-slate-500">
                                                {index + 1}
                                            </td>
                                            <td className="px-3 py-2 text-center align-middle">{line}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h3 className="text-lg font-semibold text-slate-900">
                            Storico completo
                        </h3>
                        <div className="flex flex-wrap gap-2">
                            <input
                                className="rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                placeholder="Cerca evento"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                            />
                            <select
                                className="rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                value={typeFilter}
                                onChange={(event) =>
                                    setTypeFilter(event.target.value)
                                }
                            >
                                {eventTypes.map((type) => (
                                    <option key={type}>{type}</option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full min-w-[900px] table-fixed text-sm">
                            <thead className="text-xs uppercase tracking-wide text-slate-400">
                                <tr>
                                    <th className="px-3 py-3 text-center align-middle">ID</th>
                                    <th className="px-3 py-3 text-center align-middle">Tipo</th>
                                    <th className="px-3 py-3 text-center align-middle">Data</th>
                                    <th className="px-3 py-3 text-center align-middle">Periodo</th>
                                    <th className="px-3 py-3 text-center align-middle">Dettaglio</th>
                                    <th className="px-3 py-3 text-center align-middle">Stato</th>
                                    <th className="px-3 py-3 text-center align-middle">Ore/Min</th>
                                    <th className="px-3 py-3 text-center align-middle">Su 40</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 text-slate-700">
                                {filteredRecords.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={8}
                                            className="px-3 py-6 text-center text-sm text-slate-400"
                                        >
                                            Nessun evento trovato.
                                        </td>
                                    </tr>
                                )}
                                {filteredRecords.map((row) => (
                                    <tr key={`${row.type}-${row.record_id}`}>
                                        <td className="px-3 py-3 text-center align-middle font-semibold text-slate-800">
                                            {row.record_id}
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle">
                                            <span
                                                className={`rounded-full px-3 py-1 text-xs font-semibold ${row.type_badge ?? 'bg-slate-100 text-slate-700'}`}
                                            >
                                                {row.type}
                                            </span>
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle">{row.date}</td>
                                        <td className="px-3 py-3 text-center align-middle">{row.period}</td>
                                        <td className="px-3 py-3 text-center align-middle">{row.detail}</td>
                                        <td className="px-3 py-3 text-center align-middle">
                                            <span
                                                className={`rounded-full px-3 py-1 text-xs font-semibold ${row.status_badge ?? 'bg-slate-100 text-slate-700'}`}
                                            >
                                                {row.status}
                                            </span>
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle">
                                            {row.hours_label !== '-'
                                                ? row.hours_label
                                                : row.delay_minutes_label}
                                        </td>
                                        <td className="px-3 py-3 text-center align-middle">{row.count_40_label}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            {isExportOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 px-4">
                    <div className="w-full max-w-xl rounded-2xl bg-white p-6 shadow-2xl">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h3 className="text-lg font-semibold text-slate-900">
                                    Export dati studente
                                </h3>
                                <p className="mt-1 text-sm text-slate-500">
                                    Scegli sezioni e periodo. Lascia vuote le date per scaricare tutto.
                                </p>
                            </div>
                            <button
                                type="button"
                                className="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600"
                                onClick={() => setIsExportOpen(false)}
                            >
                                Chiudi
                            </button>
                        </div>

                        <div className="mt-5 space-y-4">
                            <div>
                                <div className="flex items-center justify-between gap-3">
                                    <p className="text-sm font-semibold text-slate-800">
                                        Dati da includere
                                    </p>
                                    <button
                                        type="button"
                                        className="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-700"
                                        onClick={toggleAllExportSections}
                                    >
                                        {allExportSectionsSelected
                                            ? 'Deseleziona tutto'
                                            : 'Seleziona tutto'}
                                    </button>
                                </div>
                                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                    {exportSectionOptions.map((option) => (
                                        <label
                                            key={option.key}
                                            className="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700"
                                        >
                                            <input
                                                type="checkbox"
                                                className="h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500"
                                                checked={Boolean(exportSections[option.key])}
                                                onChange={() => toggleExportSection(option.key)}
                                            />
                                            {option.label}
                                        </label>
                                    ))}
                                </div>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2">
                                <label className="flex flex-col gap-1 text-xs font-semibold text-slate-600">
                                    Dal
                                    <input
                                        type="date"
                                        className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                                        value={exportDateFrom}
                                        onChange={(event) =>
                                            setExportDateFrom(event.target.value)
                                        }
                                    />
                                </label>
                                <label className="flex flex-col gap-1 text-xs font-semibold text-slate-600">
                                    Al
                                    <input
                                        type="date"
                                        className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                                        value={exportDateTo}
                                        min={exportDateFrom || undefined}
                                        onChange={(event) =>
                                            setExportDateTo(event.target.value)
                                        }
                                    />
                                </label>
                            </div>

                            <div className="flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4">
                                <button
                                    type="button"
                                    className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700"
                                    onClick={() => setIsExportOpen(false)}
                                >
                                    Annulla
                                </button>
                                <a
                                    href={exportUrl}
                                    className="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white"
                                    onClick={() => setIsExportOpen(false)}
                                >
                                    Scarica CSV
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
