import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

export default function LogsExport({
    title = 'Esporta log',
    description = 'Scegli i filtri da includere nel file CSV.',
    sourceRoute = '',
    exportRoute = '',
    filters = {},
    entities = [],
    actions = [],
    levelOptions = [],
    maxRows = 200000,
}) {
    const [query, setQuery] = useState(filters.query ?? '');
    const [levelFilter, setLevelFilter] = useState(filters.level ?? '');
    const [entityFilter, setEntityFilter] = useState(filters.entity ?? '');
    const [actionFilter, setActionFilter] = useState(filters.action ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');
    const [dateError, setDateError] = useState('');

    useEffect(() => {
        setQuery(filters.query ?? '');
        setLevelFilter(filters.level ?? '');
        setEntityFilter(filters.entity ?? '');
        setActionFilter(filters.action ?? '');
        setDateFrom(filters.date_from ?? '');
        setDateTo(filters.date_to ?? '');
    }, [
        filters.query,
        filters.level,
        filters.entity,
        filters.action,
        filters.date_from,
        filters.date_to,
    ]);

    useEffect(() => {
        if (dateFrom !== '' && dateTo !== '' && dateFrom > dateTo) {
            setDateError('Intervallo non valido: la data "da" non puo superare la data "a".');
            return;
        }

        setDateError('');
    }, [dateFrom, dateTo]);

    const exportParams = useMemo(
        () => ({
            query: query.trim() || undefined,
            level: levelFilter || undefined,
            entity: entityFilter || undefined,
            action: actionFilter || undefined,
            date_from: dateFrom || undefined,
            date_to: dateTo || undefined,
        }),
        [query, levelFilter, entityFilter, actionFilter, dateFrom, dateTo],
    );

    const goToExport = (event) => {
        event.preventDefault();

        if (dateError !== '' || exportRoute === '') {
            return;
        }

        window.location.assign(route(exportRoute, exportParams));
    };

    const rowsLimitLabel = Number(maxRows).toLocaleString('it-CH');

    return (
        <AuthenticatedLayout header={title}>
            <Head title={title} />

            <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div className="space-y-1">
                    <h2 className="text-lg font-semibold text-slate-900">{title}</h2>
                    <p className="text-sm text-slate-500">{description}</p>
                    <p className="text-xs text-slate-500">
                        Massimo {rowsLimitLabel} righe per export.
                    </p>
                </div>

                <form
                    className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600"
                    onSubmit={goToExport}
                >
                    <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                        <label className="flex flex-col gap-2">
                            Cerca
                            <input
                                className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"
                                placeholder="Attore, azione, entita"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                            />
                        </label>

                        {levelOptions.length > 0 && (
                            <label className="flex flex-col gap-2">
                                Livello
                                <select
                                    className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"
                                    value={levelFilter}
                                    onChange={(event) => setLevelFilter(event.target.value)}
                                >
                                    {levelOptions.map((level) => (
                                        <option key={level.code || 'all'} value={level.code}>
                                            {level.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}

                        <label className="flex flex-col gap-2">
                            Entita
                            <select
                                className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"
                                value={entityFilter}
                                onChange={(event) => setEntityFilter(event.target.value)}
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
                                onChange={(event) => setActionFilter(event.target.value)}
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
                                onChange={(event) => setDateFrom(event.target.value)}
                            />
                        </label>

                        <label className="flex flex-col gap-2">
                            Data a
                            <input
                                className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"
                                type="date"
                                value={dateTo}
                                onChange={(event) => setDateTo(event.target.value)}
                            />
                        </label>
                    </div>

                    {dateError !== '' && (
                        <p className="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                            {dateError}
                        </p>
                    )}

                    <div className="mt-4 flex justify-end gap-2">
                        <button
                            type="submit"
                            disabled={dateError !== '' || exportRoute === ''}
                            className="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-400"
                        >
                            Esporta CSV
                        </button>
                    </div>
                </form>
            </section>
        </AuthenticatedLayout>
    );
}
