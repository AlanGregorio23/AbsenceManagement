import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import RequestDetailsModal from '@/Pages/Student/Partials/RequestDetailsModal';
import { Head, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

export default function History({ items = [] }) {
    const page = usePage();
    const [query, setQuery] = useState('');
    const [typeFilter, setTypeFilter] = useState('Tutti');
    const [selectedItem, setSelectedItem] = useState(null);
    const safeItems = Array.isArray(items) ? items : Object.values(items ?? {});

    const filteredItems = useMemo(() => {
        const normalizedQuery = query.trim().toLowerCase();

        return safeItems.filter((row) => {
            const matchType =
                typeFilter === 'Tutti' || row.tipo === typeFilter;
            const matchQuery =
                normalizedQuery.length === 0 ||
                String(row.id ?? '').toLowerCase().includes(normalizedQuery) ||
                String(row.tipo ?? '').toLowerCase().includes(normalizedQuery) ||
                String(row.data ?? '').toLowerCase().includes(normalizedQuery) ||
                String(row.motivo ?? '').toLowerCase().includes(normalizedQuery) ||
                String(row.durata ?? '').toLowerCase().includes(normalizedQuery);

            return matchType && matchQuery;
        });
    }, [safeItems, query, typeFilter]);

    const truncateText = (value, maxLength = 110) => {
        const text = String(value ?? '').trim();
        if (text === '') {
            return '-';
        }
        if (text.length <= maxLength) {
            return text;
        }
        return `${text.slice(0, Math.max(maxLength - 3, 0)).trimEnd()}...`;
    };

    useEffect(() => {
        const search = String(page.url ?? '').split('?')[1] ?? '';
        const params = new URLSearchParams(search);
        const openId = String(params.get('open') ?? '').trim().toLowerCase();
        if (openId === '') {
            return;
        }

        const match = safeItems.find(
            (row) => String(row.id ?? '').trim().toLowerCase() === openId
        );
        if (match) {
            setSelectedItem(match);
        }
    }, [page.url, safeItems]);

    const closeModal = () => {
        setSelectedItem(null);
        if (typeof window !== 'undefined') {
            const nextUrl = new URL(window.location.href);
            nextUrl.searchParams.delete('open');
            window.history.replaceState({}, '', nextUrl.toString());
        }
    };

    return (
        <AuthenticatedLayout header="Storico">
            <Head title="Storico" />

            <div className="space-y-6">
                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-semibold text-slate-900">
                                Storico richieste
                            </h2>
                            <p className="text-sm text-slate-500">
                                Tutte le richieste inviate e il loro stato.
                            </p>
                        </div>
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                            <input
                                className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm sm:w-56"
                                placeholder="Cerca ID o tipo"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                            />
                            <select
                                className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm sm:w-44"
                                value={typeFilter}
                                onChange={(event) =>
                                    setTypeFilter(event.target.value)
                                }
                            >
                                <option>Tutti</option>
                                <option>Assenza</option>
                                <option>Ritardo</option>
                                <option>Congedo</option>
                            </select>
                        </div>
                    </div>

                    <div className="mt-4 space-y-3 md:hidden">
                        {filteredItems.length === 0 && (
                            <div className="rounded-xl border border-dashed border-slate-200 px-4 py-6 text-center text-sm text-slate-400">
                                Nessuna richiesta trovata.
                            </div>
                        )}
                        {filteredItems.map((row) => (
                            <article key={row.id} className="rounded-xl border border-slate-200 bg-white p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="text-sm text-slate-400">ID</p>
                                        <span className="font-semibold text-slate-900">
                                            {row.id}
                                        </span>
                                    </div>
                                    <span className={`rounded-full px-3 py-1 text-xs font-semibold ${row.badge}`}>
                                        {row.stato}
                                    </span>
                                </div>

                                <div className="mt-3 grid grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <p className="text-slate-400">Data</p>
                                        <p className="text-slate-700">{row.data}</p>
                                    </div>
                                    <div>
                                        <p className="text-slate-400">Tipo</p>
                                        <p className="text-slate-700">{row.tipo}</p>
                                    </div>
                                    <div>
                                        <p className="text-slate-400">Durata</p>
                                        <p className="text-slate-700">{row.durata}</p>
                                    </div>
                                    <div>
                                        <p className="text-slate-400">Scadenza</p>
                                        <p className="text-slate-700">{row.scadenza ?? '-'}</p>
                                    </div>
                                    <div>
                                        <p className="text-slate-400">Certificato</p>
                                        {row.tipo === 'Assenza' ? (
                                            <span className={`mt-1 inline-flex rounded-full px-2 py-1 text-xs font-semibold ${row.certificato_obbligo_badge ?? 'bg-slate-100 text-slate-700'}`}>
                                                {row.certificato_obbligo_short ?? 'Non richiesto'}
                                            </span>
                                        ) : (
                                            <p className="text-slate-400">-</p>
                                        )}
                                    </div>
                                </div>

                                <div className="mt-3">
                                    <p className="text-slate-400">Motivo</p>
                                    <p className="text-sm text-slate-700" title={row.motivo ?? '-'}>
                                        {truncateText(row.motivo)}
                                    </p>
                                </div>
                                <div className="mt-4">
                                    <button
                                        type="button"
                                        onClick={() => setSelectedItem(row)}
                                        className="btn-soft-primary h-8"
                                    >
                                        Apri pratica
                                    </button>
                                </div>
                            </article>
                        ))}
                    </div>

                    <div className="mt-4 hidden overflow-x-auto md:block">
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs uppercase tracking-wide text-slate-400">
                                <tr>
                                    <th className="py-3">ID</th>
                                    <th className="py-3">Data</th>
                                    <th className="py-3">Tipo</th>
                                    <th className="py-3">Durata</th>
                                    <th className="py-3">Scadenza</th>
                                    <th className="py-3">Certificato</th>
                                    <th className="py-3">Stato</th>
                                    <th className="py-3">Azioni</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {filteredItems.length === 0 && (
                                    <tr>
                                        <td
                                            className="py-6 text-center text-sm text-slate-400"
                                            colSpan={8}
                                        >
                                            Nessuna richiesta trovata.
                                        </td>
                                    </tr>
                                )}
                                {filteredItems.map((row) => (
                                    <tr key={row.id} className="text-slate-600">
                                        <td className="py-3 font-medium text-slate-800">
                                            {row.id}
                                        </td>
                                        <td className="py-3">{row.data}</td>
                                        <td className="py-3">{row.tipo}</td>
                                        <td className="py-3">{row.durata}</td>
                                        <td className="py-3">{row.scadenza ?? '-'}</td>
                                        <td className="py-3">
                                            {row.tipo === 'Assenza' ? (
                                                <span
                                                    className={`rounded-full px-3 py-1 text-xs font-semibold ${row.certificato_obbligo_badge ?? 'bg-slate-100 text-slate-700'}`}
                                                >
                                                    {row.certificato_obbligo_short ??
                                                        'Non richiesto'}
                                                </span>
                                            ) : (
                                                <span className="text-slate-400">-</span>
                                            )}
                                        </td>
                                        <td className="py-3">
                                            <span
                                                className={`rounded-full px-3 py-1 text-xs font-semibold ${row.badge}`}
                                            >
                                                {row.stato}
                                            </span>
                                        </td>
                                        <td className="py-3">
                                            <button
                                                type="button"
                                                onClick={() => setSelectedItem(row)}
                                                    className="btn-soft-primary h-8"
                                            >
                                                Apri pratica
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <RequestDetailsModal
                item={selectedItem}
                open={selectedItem !== null}
                onClose={closeModal}
            />
        </AuthenticatedLayout>
    );
}
