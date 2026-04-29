import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function Interactions({
    logs = [],
    pagination = null,
    filters = {},
    entities = [],
    actions = [],
}) {
    const [query, setQuery] = useState(filters.query ?? '');
    const [entityFilter, setEntityFilter] = useState(filters.entity ?? '');
    const [actionFilter, setActionFilter] = useState(filters.action ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');
    const [selectedLog, setSelectedLog] = useState(null);

    useEffect(() => {
        setQuery(filters.query ?? '');
        setEntityFilter(filters.entity ?? '');
        setActionFilter(filters.action ?? '');
        setDateFrom(filters.date_from ?? '');
        setDateTo(filters.date_to ?? '');
    }, [filters.query, filters.entity, filters.action, filters.date_from, filters.date_to]);

    const applyFilters = (event) => {
        event.preventDefault();

        router.get(
            route('admin.interactions'),
            {
                query: query.trim() || undefined,
                entity: entityFilter || undefined,
                action: actionFilter || undefined,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                only: ['logs', 'pagination', 'filters', 'entities', 'actions'],
            },
        );
    };

    const resetFilters = () => {
        setQuery('');
        setEntityFilter('');
        setActionFilter('');
        setDateFrom('');
        setDateTo('');

        router.get(
            route('admin.interactions'),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                only: ['logs', 'pagination', 'filters', 'entities', 'actions'],
            },
        );
    };

    const exportParams = {
        query: query.trim() || undefined,
        entity: entityFilter || undefined,
        action: actionFilter || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
    };

    const formatPayload = (payload) => {
        if (!payload) {
            return 'Nessun dettaglio disponibile.';
        }

        if (typeof payload === 'string') {
            return payload;
        }

        if (
            Array.isArray(payload) && payload.length === 0
        ) {
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

    return (
        <AuthenticatedLayout header="Storico interazioni">
            <Head title="Storico interazioni" />

            <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-900">
                            Storico interazioni
                        </h2>
                        <p className="text-sm text-slate-500">
                            Traccia delle operazioni effettuate nel sistema.
                        </p>
                        <p className="text-xs text-slate-500">
                            Export massimo 200.000 righe (usa filtri/data per restringere).
                        </p>
                    </div>
                    <a
                        href={route('admin.interactions.export.options', exportParams)}
                        className="rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50"
                    >
                        Esporta
                    </a>
                </div>

                <form
                    className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600"
                    onSubmit={applyFilters}
                >
                    <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-5">
                        <label className="flex flex-col gap-2">
                            Cerca
                            <input
                                className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"
                                placeholder="Attore, azione, entita"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                            />
                        </label>
                        <label className="flex flex-col gap-2">
                            Entita
                            <select
                                className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"
                                value={entityFilter}
                                onChange={(event) =>
                                    setEntityFilter(event.target.value)
                                }
                            >
                                <option value="">Tutte</option>
                                {entities.map((entity) => (
                                    <option key={entity.code} value={entity.code}>
                                        {entity.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="flex flex-col gap-2">
                            Azione
                            <select
                                className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"
                                value={actionFilter}
                                onChange={(event) =>
                                    setActionFilter(event.target.value)
                                }
                            >
                                <option value="">Tutte</option>
                                {actions.map((action) => (
                                    <option key={action.code} value={action.code}>
                                        {action.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="flex flex-col gap-2">
                            Data da
                            <input
                                className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"
                                type="date"
                                value={dateFrom}
                                onChange={(event) =>
                                    setDateFrom(event.target.value)
                                }
                            />
                        </label>
                        <label className="flex flex-col gap-2">
                            Data a
                            <input
                                className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"
                                type="date"
                                value={dateTo}
                                onChange={(event) =>
                                    setDateTo(event.target.value)
                                }
                            />
                        </label>
                    </div>
                    <div className="mt-3 flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={resetFilters}
                            className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100"
                        >
                            Annulla
                        </button>
                        <button
                            type="submit"
                            className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                        >
                            <svg
                                className="h-4 w-4"
                                viewBox="0 0 20 20"
                                fill="none"
                                xmlns="http://www.w3.org/2000/svg"
                                aria-hidden="true"
                            >
                                <circle cx="9" cy="9" r="6" stroke="currentColor" strokeWidth="1.5" />
                                <path d="M13.5 13.5L17 17" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
                            </svg>
                            Cerca
                        </button>
                    </div>
                </form>

                <div className="mt-4 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th className="py-3">Attore</th>
                                <th className="py-3">Azione</th>
                                <th className="py-3">Entita</th>
                                <th className="py-3">Data</th>
                                <th className="py-3">Azioni</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {logs.length === 0 && (
                                <tr>
                                    <td
                                        className="py-6 text-center text-sm text-slate-400"
                                        colSpan={5}
                                    >
                                        Nessuna interazione trovata.
                                    </td>
                                </tr>
                            )}
                            {logs.map((item) => (
                                <tr key={item.id}>
                                    <td className="py-3">
                                        {item.attore ?? '-'}
                                    </td>
                                    <td className="py-3">{item.azione}</td>
                                    <td className="py-3">{item.entita}</td>
                                    <td className="py-3">{item.created_at}</td>
                                    <td className="py-3">
                                        <button
                                            type="button"
                                            onClick={() => setSelectedLog(item)}
                                            className="btn-soft-primary h-8"
                                        >
                                            Dettagli
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mt-4 flex items-center justify-between gap-3 border-t border-slate-100 pt-4">
                    <p className="text-xs text-slate-500">
                        {pagination?.from && pagination?.to
                            ? `Record visualizzati: ${pagination.from}-${pagination.to}`
                            : `Record visualizzati: ${logs.length}`}
                    </p>
                    <div className="flex items-center gap-2">
                        {pagination?.prev ? (
                            <Link
                                href={pagination.prev}
                                preserveScroll
                                className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Precedente
                            </Link>
                        ) : (
                            <span className="rounded-lg border border-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-300">
                                Precedente
                            </span>
                        )}
                        <span className="text-xs text-slate-500">
                            Pagina {pagination?.current_page ?? 1}
                        </span>
                        {pagination?.next ? (
                            <Link
                                href={pagination.next}
                                preserveScroll
                                className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Successiva
                            </Link>
                        ) : (
                            <span className="rounded-lg border border-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-300">
                                Successiva
                            </span>
                        )}
                    </div>
                </div>
            </section>

            {selectedLog && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <div className="w-full max-w-3xl rounded-2xl bg-white p-6 shadow-2xl">
                        <div className="flex items-center justify-between">
                            <h3 className="text-lg font-semibold text-slate-900">
                                Dettagli interazione
                            </h3>
                            <button
                                type="button"
                                onClick={() => setSelectedLog(null)}
                                className="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Chiudi
                            </button>
                        </div>

                        <div className="mt-4 grid gap-3 text-sm text-slate-700 md:grid-cols-2">
                            <p>
                                <span className="font-semibold">Attore:</span>{' '}
                                {selectedLog.attore ?? '-'}
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
                                <span className="font-semibold">Livello:</span>{' '}
                                {selectedLog.livello_label ?? selectedLog.livello ?? '-'}
                            </p>
                            <p>
                                <span className="font-semibold">IP:</span>{' '}
                                {selectedLog.ip ?? '-'}
                            </p>
                            <p>
                                <span className="font-semibold">Data:</span>{' '}
                                {selectedLog.created_at ?? '-'}
                            </p>
                        </div>

                        <div className="mt-4">
                            <p className="text-sm font-semibold text-slate-800">
                                Dettagli operazione
                            </p>
                            <pre className="mt-2 max-h-80 overflow-auto rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
{formatPayload(selectedLog.dettagli_json)}
                            </pre>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
