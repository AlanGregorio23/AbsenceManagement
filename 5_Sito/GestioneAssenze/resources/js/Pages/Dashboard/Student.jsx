import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardStatCard from '@/Components/DashboardStatCard';
import RequestDetailsModal from '@/Pages/Student/Partials/RequestDetailsModal';
import { resolveAnnualHoursLimitLabels } from '@/annualHoursLimit';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

const buildDefaultStats = (maxAnnualHours) => [
    { label: 'Ore di assenza', value: '0', helper: '+0 questo mese' },
    {
        label: 'Ore disponibili',
        value: `0 / ${maxAnnualHours > 0 ? maxAnnualHours : '-'}`,
        helper: 'Monte ore annuale',
    },
    { label: 'Azioni richieste', value: '0', helper: 'Operazioni da completare' },
    { label: 'Ritardi', value: '0', helper: 'Totale registrati' },
];
const statDecorations = [
    { icon: 'clock', tone: 'indigo' },
    { icon: 'docs', tone: 'amber' },
    { icon: 'chart', tone: 'emerald' },
    { icon: 'calendar', tone: 'rose' },
];

const certificateReminderCodes = new Set(['required_pending']);

const shouldShowCertificateReminder = (item) => {
    const typeCode = String(item?.tipo ?? '')
        .trim()
        .toLowerCase();
    const certificateCode = String(item?.certificato_obbligo_code ?? '')
        .trim()
        .toLowerCase();

    return typeCode === 'assenza' && certificateReminderCodes.has(certificateCode);
};

const documentsTargetHref = (item) => {
    const absenceId = Number(item?.absence_id ?? 0);
    if (!Number.isFinite(absenceId) || absenceId <= 0) {
        return route('student.documents');
    }

    return `${route('student.documents')}?target=${encodeURIComponent(`absence:${absenceId}`)}`;
};

const truncateText = (value, maxLength = 62) => {
    const text = String(value ?? '').trim();
    if (text === '') {
        return '-';
    }
    if (text.length <= maxLength) {
        return text;
    }
    return `${text.slice(0, Math.max(maxLength - 3, 0)).trimEnd()}...`;
};

export default function StudentDashboard({
    assenze = [],
    stats = [],
}) {
    const { props } = usePage();
    const annualHoursLimit = resolveAnnualHoursLimitLabels(props);
    const [selectedItem, setSelectedItem] = useState(null);
    const resolvedStats = stats.length ? stats : buildDefaultStats(annualHoursLimit.value);
    const safeAssenze = Array.isArray(assenze)
        ? assenze
        : Object.values(assenze ?? {});

    return (
        <AuthenticatedLayout header="Dashboard">
            <Head title="Dashboard Studente" />

            <div className="space-y-6">
                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {resolvedStats.map((stat, index) => (
                        <DashboardStatCard
                            key={stat.label}
                            label={stat.label}
                            value={stat.value}
                            helper={stat.helper}
                            icon={statDecorations[index % statDecorations.length].icon}
                            tone={statDecorations[index % statDecorations.length].tone}
                        />
                    ))}
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <div>
                        <h3 className="text-lg font-semibold text-slate-900">
                            Ultime richieste
                        </h3>
                        <p className="text-sm text-slate-500">
                            Riepilogo assenze, ritardi e congedi.
                        </p>
                    </div>

                    <div className="mt-4 space-y-3 md:hidden">
                        {safeAssenze.length === 0 && (
                            <div className="rounded-xl border border-dashed border-slate-200 px-4 py-6 text-center text-sm text-slate-400">
                                Nessuna richiesta trovata.
                            </div>
                        )}

                        {safeAssenze.map((assenza) => (
                            <article
                                key={assenza.id}
                                className="rounded-xl border border-slate-200 bg-white p-4"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="text-sm text-slate-400">ID</p>
                                        <span className="font-semibold text-slate-900">
                                            {assenza.id}
                                        </span>
                                    </div>
                                    <span
                                        className={`rounded-full px-3 py-1 text-xs font-semibold ${assenza.badge}`}
                                    >
                                        {assenza.stato}
                                    </span>
                                </div>

                                <div className="mt-3 grid grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <p className="text-slate-400">Tempo</p>
                                        <p className="text-slate-700">{assenza.durata}</p>
                                    </div>
                                    <div>
                                        <p className="text-slate-400">Data</p>
                                        <p className="text-slate-700">{assenza.data}</p>
                                    </div>
                                    <div>
                                        <p className="text-slate-400">Tipo</p>
                                        <p className="text-slate-700">{assenza.tipo}</p>
                                    </div>
                                    <div>
                                        <p className="text-slate-400">Scadenza</p>
                                        <p className="text-slate-700">{assenza.scadenza ?? '-'}</p>
                                    </div>
                                    <div>
                                        <p className="text-slate-400">Certificato</p>
                                        {assenza.tipo === 'Assenza' ? (
                                            <span
                                                className={`mt-1 inline-flex rounded-full px-2 py-1 text-xs font-semibold ${assenza.certificato_obbligo_badge ?? 'bg-slate-100 text-slate-700'}`}
                                            >
                                                {assenza.certificato_obbligo_short ??
                                                    'Non richiesto'}
                                            </span>
                                        ) : (
                                            <p className="text-slate-400">-</p>
                                        )}
                                    </div>
                                </div>

                                <div className="mt-3">
                                    <p className="text-slate-400">Motivo</p>
                                    <p className="text-sm text-slate-700" title={assenza.motivo ?? '-'}>
                                        {truncateText(assenza.motivo, 110)}
                                    </p>
                                </div>

                                <div className="mt-4 flex flex-wrap items-center gap-1.5">
                                    <button
                                        type="button"
                                        onClick={() => setSelectedItem(assenza)}
                                        className="btn-soft-primary h-8"
                                    >
                                        Apri pratica
                                    </button>
                                    {assenza.can_submit_draft && assenza.draft_edit_url && (
                                        <Link
                                            href={assenza.draft_edit_url}
                                            className="btn-soft-info h-8"
                                        >
                                            Completa bozza
                                        </Link>
                                    )}
                                    {shouldShowCertificateReminder(assenza) && (
                                        <Link
                                            href={documentsTargetHref(assenza)}
                                            className="btn-soft-warning h-8"
                                        >
                                            Carica certificato
                                        </Link>
                                    )}
                                </div>
                            </article>
                        ))}
                    </div>

                    <div className="mt-4 hidden overflow-x-auto md:block">
                        <table className="w-full text-sm">
                            <thead className="text-xs uppercase tracking-wide text-slate-400">
                                <tr>
                                    <th className="py-3 text-center align-middle">ID</th>
                                    <th className="py-3 text-center align-middle">Tempo</th>
                                    <th className="py-3 text-center align-middle">Motivo</th>
                                    <th className="py-3 text-center align-middle">Data</th>
                                    <th className="py-3 text-center align-middle">Tipo</th>
                                    <th className="py-3 text-center align-middle">Scadenza</th>
                                    <th className="py-3 text-center align-middle">Certificato</th>
                                    <th className="py-3 text-center align-middle">Stato</th>
                                    <th className="py-3 text-center align-middle">Azioni</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {safeAssenze.length === 0 && (
                                    <tr>
                                        <td
                                            className="py-6 text-center text-sm text-slate-400"
                                            colSpan={9}
                                        >
                                            Nessuna richiesta trovata.
                                        </td>
                                    </tr>
                                )}
                                {safeAssenze.map((assenza) => (
                                    <tr key={assenza.id} className="text-slate-600">
                                        <td className="py-3 text-center align-middle font-medium text-slate-800">
                                            {assenza.id}
                                        </td>
                                        <td className="py-3 text-center align-middle">{assenza.durata}</td>
                                        <td className="py-3 text-center align-middle">
                                            <span
                                                className="mx-auto block max-w-[14rem] truncate"
                                                title={assenza.motivo ?? '-'}
                                            >
                                                {truncateText(assenza.motivo, 78)}
                                            </span>
                                        </td>
                                        <td className="py-3 text-center align-middle">{assenza.data}</td>
                                        <td className="py-3 text-center align-middle">{assenza.tipo}</td>
                                        <td className="py-3 text-center align-middle">{assenza.scadenza ?? '-'}</td>
                                        <td className="py-3 text-center align-middle">
                                            {assenza.tipo === 'Assenza' ? (
                                                <span
                                                    className={`rounded-full px-3 py-1 text-xs font-semibold ${assenza.certificato_obbligo_badge ?? 'bg-slate-100 text-slate-700'}`}
                                                >
                                                    {assenza.certificato_obbligo_short ??
                                                        'Non richiesto'}
                                                </span>
                                            ) : (
                                                <span className="text-slate-400">-</span>
                                            )}
                                        </td>
                                        <td className="py-3 text-center align-middle">
                                            <span
                                                className={`rounded-full px-3 py-1 text-xs font-semibold ${assenza.badge}`}
                                            >
                                                {assenza.stato}
                                            </span>
                                        </td>
                                        <td className="py-3 text-center align-middle">
                                            <div className="inline-flex flex-wrap items-center justify-center gap-1.5">
                                                <button
                                                    type="button"
                                                    onClick={() => setSelectedItem(assenza)}
                                                    className="btn-soft-primary h-8"
                                                >
                                                    Apri pratica
                                                </button>
                                                {assenza.can_submit_draft &&
                                                    assenza.draft_edit_url && (
                                                        <Link
                                                            href={assenza.draft_edit_url}
                                                            className="btn-soft-info h-8"
                                                        >
                                                            Completa bozza
                                                        </Link>
                                                    )}
                                                {shouldShowCertificateReminder(assenza) && (
                                                    <Link
                                                        href={documentsTargetHref(assenza)}
                                                        className="btn-soft-warning h-8"
                                                    >
                                                        Carica certificato
                                                    </Link>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <RequestDetailsModal
                    item={selectedItem}
                    open={selectedItem !== null}
                    onClose={() => setSelectedItem(null)}
                />
            </div>
        </AuthenticatedLayout>
    );
}
