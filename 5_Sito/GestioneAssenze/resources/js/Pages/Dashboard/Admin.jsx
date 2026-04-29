import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardStatCard from '@/Components/DashboardStatCard';
import { Head, Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const fallbackStats = [
    { label: 'Utenti totali', value: '0' },
    { label: 'Classi totali', value: '0' },
    { label: 'Docenti totali', value: '0' },
    { label: 'Errori critici', value: '0' },
];
const statDecorations = [
    { icon: 'users', tone: 'sky' },
    { icon: 'classes', tone: 'violet' },
    { icon: 'teacher', tone: 'emerald' },
    { icon: 'errors', tone: 'rose' },
];

const resolveUserKey = (user) =>
    String(user?.user_id ?? user?.email ?? `${user?.nome ?? ''}-${user?.cognome ?? ''}`);

const resolveClassKey = (row) =>
    String(row?.id ?? `${row?.nome ?? ''}-${row?.docente ?? ''}-${row?.anno ?? ''}`);

const formatPayload = (payload) => {
    if (!payload) {
        return 'Nessun dettaglio disponibile.';
    }

    if (typeof payload === 'string') {
        return payload;
    }

    if (Array.isArray(payload) && payload.length === 0) {
        return 'Nessun dettaglio disponibile.';
    }

    if (
        typeof payload === 'object' &&
        !Array.isArray(payload) &&
        Object.keys(payload).length === 0
    ) {
        return 'Nessun dettaglio disponibile.';
    }

    try {
        return JSON.stringify(payload, null, 2);
    } catch {
        return String(payload);
    }
};

function GlassDetailsModal({
    title,
    onClose,
    children,
    maxWidth = 'max-w-3xl',
}) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/55 p-4 backdrop-blur-[2px]">
            <div className={`w-full ${maxWidth} rounded-2xl border border-white/60 bg-white/90 p-6 shadow-2xl backdrop-blur-xl`}>
                <div className="flex items-center justify-between gap-3">
                    <h3 className="text-lg font-semibold text-slate-900">
                        {title}
                    </h3>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg border border-slate-200 bg-white/70 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-white"
                    >
                        Chiudi
                    </button>
                </div>
                <div className="mt-4">
                    {children}
                </div>
            </div>
        </div>
    );
}

export default function AdminDashboard({
    stats = [],
    utenti = [],
    classi = [],
    logs = [],
    settings = null,
}) {
    const resolvedStats = stats.length ? stats : fallbackStats;
    const usersPerPage = 4;
    const classesPerPage = 4;
    const [userPage, setUserPage] = useState(1);
    const [classPage, setClassPage] = useState(1);
    const [userDetailKey, setUserDetailKey] = useState(null);
    const [classDetailKey, setClassDetailKey] = useState(null);
    const [logDetailId, setLogDetailId] = useState(null);
    const delayRules = settings?.delay_rules ?? [];
    const absence = settings?.absence ?? null;
    const delay = settings?.delay ?? null;

    const totalUserPages = Math.max(1, Math.ceil(utenti.length / usersPerPage));
    const visibleUsers = useMemo(() => {
        const safePage = Math.min(userPage, totalUserPages);
        const start = (safePage - 1) * usersPerPage;
        return utenti.slice(start, start + usersPerPage);
    }, [utenti, userPage, usersPerPage, totalUserPages]);

    const totalClassPages = Math.max(1, Math.ceil(classi.length / classesPerPage));
    const visibleClasses = useMemo(() => {
        const safePage = Math.min(classPage, totalClassPages);
        const start = (safePage - 1) * classesPerPage;
        return classi.slice(start, start + classesPerPage);
    }, [classi, classPage, classesPerPage, totalClassPages]);

    const selectedUser = useMemo(
        () => visibleUsers.find((user) => resolveUserKey(user) === userDetailKey) ?? null,
        [visibleUsers, userDetailKey],
    );

    const selectedClass = useMemo(
        () => visibleClasses.find((row) => resolveClassKey(row) === classDetailKey) ?? null,
        [visibleClasses, classDetailKey],
    );

    const selectedLog = useMemo(
        () => logs.find((log) => log.id === logDetailId) ?? null,
        [logs, logDetailId],
    );

    useEffect(() => {
        if (userPage > totalUserPages) {
            setUserPage(totalUserPages);
        }
    }, [userPage, totalUserPages]);

    useEffect(() => {
        if (classPage > totalClassPages) {
            setClassPage(totalClassPages);
        }
    }, [classPage, totalClassPages]);

    useEffect(() => {
        if (!selectedUser && userDetailKey !== null) {
            setUserDetailKey(null);
        }
    }, [selectedUser, userDetailKey]);

    useEffect(() => {
        if (!selectedClass && classDetailKey !== null) {
            setClassDetailKey(null);
        }
    }, [selectedClass, classDetailKey]);

    useEffect(() => {
        if (!selectedLog && logDetailId !== null) {
            setLogDetailId(null);
        }
    }, [selectedLog, logDetailId]);

    const actionLabels = {
        none: 'Nessuna azione',
        notify_student: 'Notifica allievo',
        notify_guardian: 'Notifica tutore',
        notify_teacher: 'Notifica docente di classe',
        extra_activity_notice: 'Segnalazione attivita extrascolastica',
        conduct_penalty: 'Penalita nota di condotta',
    };

    const formatRuleRange = (rule) =>
        `${rule.min_delays} - ${rule.max_delays === null ? 'oltre' : rule.max_delays}`;

    const formatActions = (actions = []) =>
        actions.map((action) => {
            const base = actionLabels[action.type] ?? action.type;
            if (action.type === 'conduct_penalty' && action.detail) {
                return `${base} (${action.detail})`;
            }
            return base;
        });

    const summarizeDelayRules = () => {
        if (!delayRules.length) {
            return 'Nessuna soglia configurata';
        }
        const previews = delayRules.slice(0, 2).map(formatRuleRange).join(', ');
        const extra = delayRules.length > 2 ? `, +${delayRules.length - 2}` : '';
        return `${delayRules.length} regole (${previews}${extra})`;
    };

    return (
        <AuthenticatedLayout header="Dashboard">
            <Head title="Dashboard Admin" />

            <div className="space-y-6">
                <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    {resolvedStats.map((stat, index) => (
                        <DashboardStatCard
                            key={stat.label}
                            label={stat.label}
                            value={stat.value}
                            icon={statDecorations[index % statDecorations.length].icon}
                            tone={statDecorations[index % statDecorations.length].tone}
                        />
                    ))}
                </section>

                <section className="grid gap-6 lg:grid-cols-3">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
                        <div className="flex items-center justify-between">
                            <h3 className="text-lg font-semibold text-slate-900">
                                Gestione utenti
                            </h3>
                            <Link className="inline-flex h-9 items-center rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white transition hover:bg-slate-800" href={route('admin.user.create')}>
                                + Aggiungi utente
                            </Link>
                        </div>
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full min-w-[640px] table-fixed text-sm">
                                <thead className="text-xs uppercase tracking-wide text-slate-400">
                                    <tr>
                                        <th className="px-3 py-3 text-center align-middle">Nome</th>
                                        <th className="px-3 py-3 text-center align-middle">Cognome</th>
                                        <th className="px-3 py-3 text-center align-middle">Email</th>
                                        <th className="px-3 py-3 text-center align-middle">Ruolo</th>
                                        <th className="px-3 py-3 text-center align-middle">Azioni</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {visibleUsers.length === 0 && (
                                        <tr>
                                            <td
                                                className="px-3 py-6 text-center text-sm text-slate-400"
                                                colSpan={5}
                                            >
                                                Nessun utente trovato.
                                            </td>
                                        </tr>
                                    )}
                                    {visibleUsers.map((user) => {
                                        const userKey = resolveUserKey(user);

                                        return (
                                        <tr
                                            key={userKey}
                                            className="transition hover:bg-slate-50"
                                        >
                                            <td className="px-3 py-3 text-center align-middle font-medium text-slate-800">
                                                {user.nome}
                                            </td>
                                            <td className="px-3 py-3 text-center align-middle">
                                                {user.cognome}
                                            </td>
                                            <td className="px-3 py-3 text-center align-middle text-slate-500 break-all">
                                                {user.email}
                                            </td>
                                            <td className="px-3 py-3 text-center align-middle">
                                                {user.ruolo}
                                            </td>
                                            <td className="px-3 py-3 text-center align-middle">
                                                <button
                                                    type="button"
                                                    className="btn-soft-primary h-8"
                                                    onClick={() => setUserDetailKey(userKey)}
                                                >
                                                    Dettagli
                                                </button>
                                            </td>
                                        </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        <div className="mt-4 flex items-center justify-between border-t border-slate-100 pt-4">
                            <p className="text-xs text-slate-500">
                                Pagina {userPage} di {totalUserPages}
                            </p>
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50 disabled:cursor-not-allowed disabled:border-slate-100 disabled:text-slate-300"
                                    onClick={() => setUserPage((page) => Math.max(1, page - 1))}
                                    disabled={userPage <= 1}
                                >
                                    Precedente
                                </button>
                                <button
                                    type="button"
                                    className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50 disabled:cursor-not-allowed disabled:border-slate-100 disabled:text-slate-300"
                                    onClick={() => setUserPage((page) => Math.min(totalUserPages, page + 1))}
                                    disabled={userPage >= totalUserPages}
                                >
                                    Successiva
                                </button>
                            </div>
                        </div>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="flex items-center justify-between">
                            <h3 className="text-lg font-semibold text-slate-900">
                                Opzioni sistema
                            </h3>
                            <Link
                                href={route('admin.settings')}
                                className="inline-flex h-9 items-center rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                            >
                                Modifica regole
                            </Link>
                        </div>
                        <div className="mt-4 space-y-3 text-sm text-slate-600">
                            <div className="rounded-xl bg-slate-50 p-3">
                                <p className="font-semibold text-slate-800">
                                    Regole ore assenze
                                </p>
                                {absence ? (
                                    <div className="mt-2 space-y-1 text-sm text-slate-600">
                                        <p>
                                            Max annuale:{' '}
                                            <span className="font-semibold text-slate-800">
                                                {absence.max_annual_hours} ore
                                            </span>
                                            {' - '}
                                            Soglia:{' '}
                                            <span className="font-semibold text-slate-800">
                                                {absence.warning_threshold_hours} ore
                                            </span>
                                        </p>
                                        <p className="text-xs text-slate-500">
                                            Firma tutore:{' '}
                                            {absence.guardian_signature_required
                                                ? 'obbligatoria'
                                                : 'non richiesta'}
                                        </p>
                                    </div>
                                ) : (
                                    <p className="mt-2 text-xs text-slate-500">
                                        Nessuna regola disponibile.
                                    </p>
                                )}
                            </div>
                            <div className="rounded-xl bg-slate-50 p-3">
                                <p className="font-semibold text-slate-800">
                                    Regole ore ritardi
                                </p>
                                {delay ? (
                                    <div className="mt-2 text-sm text-slate-600">
                                        <p>
                                            Soglia ritardo assenza:{' '}
                                            <span className="font-semibold text-slate-800">
                                                {delay.minutes_threshold} min
                                            </span>
                                        </p>
                                        <p className="text-xs text-slate-500">
                                            {summarizeDelayRules()}
                                        </p>
                                        <p className="text-xs text-slate-500">
                                            Firma tutore:{' '}
                                            {delay.guardian_signature_required
                                                ? 'obbligatoria'
                                                : 'non richiesta'}
                                        </p>
                                    </div>
                                ) : (
                                    <p className="mt-2 text-xs text-slate-500">
                                        Nessuna regola disponibile.
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>
                </section>

                <section className="grid gap-6 lg:grid-cols-3">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
                        <div className="flex items-center justify-between">
                            <h3 className="text-lg font-semibold text-slate-900">
                                Gestione classi
                            </h3>
                            <Link
                                href={route('admin.classes')}
                                className="inline-flex h-9 items-center rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white transition hover:bg-slate-800"
                            >
                                + Aggiungi classe
                            </Link>
                        </div>
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full min-w-[560px] table-fixed text-sm">
                                <thead className="text-xs uppercase tracking-wide text-slate-400">
                                    <tr>
                                        <th className="px-3 py-3 text-center align-middle">Classe</th>
                                        <th className="px-3 py-3 text-center align-middle">Docente di classe</th>
                                        <th className="px-3 py-3 text-center align-middle">Anno</th>
                                        <th className="px-3 py-3 text-center align-middle">Azioni</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {visibleClasses.length === 0 && (
                                        <tr>
                                            <td
                                                className="px-3 py-6 text-center text-sm text-slate-400"
                                                colSpan={4}
                                            >
                                                Nessuna classe trovata.
                                            </td>
                                        </tr>
                                    )}
                                    {visibleClasses.map((row) => {
                                        const classKey = resolveClassKey(row);

                                        return (
                                        <tr
                                            key={classKey}
                                            className="transition hover:bg-slate-50"
                                        >
                                            <td className="px-3 py-3 text-center align-middle font-medium text-slate-800">
                                                {row.nome}
                                            </td>
                                            <td className="px-3 py-3 text-center align-middle">
                                                {row.docente}
                                            </td>
                                            <td className="px-3 py-3 text-center align-middle">
                                                {row.anno}
                                            </td>
                                            <td className="px-3 py-3 text-center align-middle">
                                                <button
                                                    type="button"
                                                    className="btn-soft-primary h-8"
                                                    onClick={() => setClassDetailKey(classKey)}
                                                >
                                                    Dettagli
                                                </button>
                                            </td>
                                        </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        <div className="mt-4 flex items-center justify-between border-t border-slate-100 pt-4">
                            <p className="text-xs text-slate-500">
                                Pagina {classPage} di {totalClassPages}
                            </p>
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50 disabled:cursor-not-allowed disabled:border-slate-100 disabled:text-slate-300"
                                    onClick={() => setClassPage((page) => Math.max(1, page - 1))}
                                    disabled={classPage <= 1}
                                >
                                    Precedente
                                </button>
                                <button
                                    type="button"
                                    className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50 disabled:cursor-not-allowed disabled:border-slate-100 disabled:text-slate-300"
                                    onClick={() => setClassPage((page) => Math.min(totalClassPages, page + 1))}
                                    disabled={classPage >= totalClassPages}
                                >
                                    Successiva
                                </button>
                            </div>
                        </div>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="flex items-center justify-between">
                            <h3 className="text-lg font-semibold text-slate-900">
                                Ultimi errori e avvisi
                            </h3>
                            <Link
                                href={route('admin.error-logs')}
                                className="inline-flex h-9 items-center rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                            >
                                Visualizza log
                            </Link>
                        </div>

                        <div className="mt-4 space-y-3">
                            {logs.length === 0 && (
                                <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
                                    Nessun errore o avviso recente.
                                </div>
                            )}
                            {logs.map((log) => (
                                    <button
                                        type="button"
                                        key={log.id}
                                        onClick={() => setLogDetailId(log.id)}
                                        className={`w-full rounded-xl border p-3 text-left transition ${
                                            log.livello === 'ERROR'
                                                ? 'border-rose-100 bg-rose-50/60'
                                                : 'border-amber-100 bg-amber-50/60'
                                        } hover:border-slate-300`}
                                    >
                                        <div className="min-w-0">
                                            <p
                                                className={`text-sm font-semibold ${
                                                    log.livello === 'ERROR'
                                                        ? 'text-rose-900'
                                                        : 'text-amber-900'
                                                }`}
                                            >
                                                {log.azione}{' '}
                                                <span
                                                    className={`font-normal ${
                                                        log.livello === 'ERROR'
                                                            ? 'text-rose-800'
                                                            : 'text-amber-800'
                                                    }`}
                                                >
                                                    {log.entita && log.entita !== '-'
                                                        ? `- ${log.entita}`
                                                        : ''}
                                                </span>
                                            </p>
                                            <div
                                                className={`mt-1 flex flex-wrap items-center gap-2 text-xs ${
                                                    log.livello === 'ERROR'
                                                        ? 'text-rose-700'
                                                        : 'text-amber-700'
                                                }`}
                                            >
                                                <span className="rounded-full bg-white/80 px-2 py-0.5">
                                                    {log.livello_label ?? log.livello ?? 'Errore'}
                                                </span>
                                                <span>{log.created_at}</span>
                                                <span>Attore: {log.attore ?? 'Sistema'}</span>
                                            </div>
                                            <div className="mt-2">
                                                <span className="btn-soft-primary h-8">
                                                    Dettagli
                                                </span>
                                            </div>
                                        </div>
                                    </button>
                            ))}
                        </div>
                    </div>
                </section>
            </div>

            {selectedUser && (
                <GlassDetailsModal
                    title="Dettagli utente"
                    onClose={() => setUserDetailKey(null)}
                    maxWidth="max-w-2xl"
                >
                    <div className="grid gap-3 text-sm text-slate-700 sm:grid-cols-2">
                        <p>
                            <span className="font-semibold">Nome:</span>{' '}
                            {selectedUser.nome ?? '-'}
                        </p>
                        <p>
                            <span className="font-semibold">Cognome:</span>{' '}
                            {selectedUser.cognome ?? '-'}
                        </p>
                        <p className="sm:col-span-2">
                            <span className="font-semibold">Email:</span>{' '}
                            {selectedUser.email ?? '-'}
                        </p>
                        <p>
                            <span className="font-semibold">Ruolo:</span>{' '}
                            {selectedUser.ruolo ?? '-'}
                        </p>
                    </div>
                </GlassDetailsModal>
            )}

            {selectedClass && (
                <GlassDetailsModal
                    title="Dettagli classe"
                    onClose={() => setClassDetailKey(null)}
                    maxWidth="max-w-2xl"
                >
                    <div className="grid gap-3 text-sm text-slate-700 sm:grid-cols-2">
                        <p>
                            <span className="font-semibold">Classe:</span>{' '}
                            {selectedClass.nome ?? '-'}
                        </p>
                        <p>
                            <span className="font-semibold">Anno:</span>{' '}
                            {selectedClass.anno ?? '-'}
                        </p>
                        <p>
                            <span className="font-semibold">Docente:</span>{' '}
                            {selectedClass.docente ?? '-'}
                        </p>
                        <p>
                            <span className="font-semibold">Sezione:</span>{' '}
                            {selectedClass.sezione ?? '-'}
                        </p>
                        <p>
                            <span className="font-semibold">Studenti:</span>{' '}
                            {selectedClass.studenti ?? '-'}
                        </p>
                        <p>
                            <span className="font-semibold">Creata il:</span>{' '}
                            {selectedClass.creato_il ?? '-'}
                        </p>
                    </div>
                </GlassDetailsModal>
            )}

            {selectedLog && (
                <GlassDetailsModal
                    title="Dettagli operazione"
                    onClose={() => setLogDetailId(null)}
                    maxWidth="max-w-4xl"
                >
                    <div className="grid gap-2 text-sm text-slate-700 md:grid-cols-2">
                        <p>
                            <span className="font-semibold">Livello:</span>{' '}
                            {selectedLog.livello_label ?? selectedLog.livello ?? '-'}
                        </p>
                        <p>
                            <span className="font-semibold">Data:</span>{' '}
                            {selectedLog.created_at ?? '-'}
                        </p>
                        <p>
                            <span className="font-semibold">Azione:</span>{' '}
                            {selectedLog.azione ?? '-'}
                        </p>
                        <p>
                            <span className="font-semibold">Entita:</span>{' '}
                            {selectedLog.entita ?? '-'}
                        </p>
                        <p>
                            <span className="font-semibold">Attore:</span>{' '}
                            {selectedLog.attore ?? 'Sistema'}
                        </p>
                        <p>
                            <span className="font-semibold">IP:</span>{' '}
                            {selectedLog.ip ?? '-'}
                        </p>
                    </div>
                    <pre className="mt-4 max-h-80 overflow-auto rounded-xl border border-slate-200 bg-white/80 p-3 text-xs text-slate-700">
{formatPayload(selectedLog.dettagli_json)}
                    </pre>
                </GlassDetailsModal>
            )}
        </AuthenticatedLayout>
    );
}
