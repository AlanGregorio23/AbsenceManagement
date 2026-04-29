import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

const bucketOptions = [
    { value: 'all', label: 'Tutti' },
    { value: 'missing', label: 'Mancanti' },
    { value: 'pending', label: 'Da approvare' },
    { value: 'completed', label: 'Completati' },
];

export default function StudentMonthlyReports({ items = [] }) {
    const [query, setQuery] = useState('');
    const [bucket, setBucket] = useState('all');
    const [fileName, setFileName] = useState('Nessun file selezionato');
    const [isDragOver, setIsDragOver] = useState(false);
    const fileInputRef = useRef(null);

    const uploadCandidates = useMemo(
        () => items.filter((item) => item.can_upload_signed),
        [items]
    );

    const { data, setData, post, processing, errors, reset } = useForm({
        report_id: uploadCandidates[0]?.report_id ?? '',
        document: null,
    });

    useEffect(() => {
        if (uploadCandidates.length === 0) {
            if (data.report_id !== '') {
                setData('report_id', '');
            }
            return;
        }

        const exists = uploadCandidates.some(
            (item) => String(item.report_id) === String(data.report_id)
        );

        if (!exists) {
            setData('report_id', uploadCandidates[0].report_id);
        }
    }, [uploadCandidates, data.report_id, setData]);

    const filtered = useMemo(() => {
        const normalizedQuery = query.trim().toLowerCase();

        return items.filter((item) => {
            const matchesBucket = bucket === 'all' || item.bucket === bucket;
            const matchesQuery =
                normalizedQuery === '' ||
                String(item.code ?? '')
                    .toLowerCase()
                    .includes(normalizedQuery) ||
                String(item.month ?? '')
                    .toLowerCase()
                    .includes(normalizedQuery);

            return matchesBucket && matchesQuery;
        });
    }, [items, query, bucket]);

    const selectedReport = uploadCandidates.find(
        (item) => String(item.report_id) === String(data.report_id)
    );

    const submitUpload = (event) => {
        event.preventDefault();
        if (!selectedReport || !data.document) {
            return;
        }

        post(
            route('student.monthly-reports.upload-signed', selectedReport.report_id),
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    reset('document');
                    setFileName('Nessun file selezionato');
                    if (fileInputRef.current) {
                        fileInputRef.current.value = '';
                    }
                },
            }
        );
    };

    const setSelectedFile = (file) => {
        setData('document', file);
        setFileName(file ? file.name : 'Nessun file selezionato');
    };

    const handleFileDrop = (event) => {
        event.preventDefault();
        setIsDragOver(false);

        const file = event.dataTransfer.files?.[0] ?? null;
        setSelectedFile(file);
    };

    const handleFileDragOver = (event) => {
        event.preventDefault();
        setIsDragOver(true);
    };

    const handleFileDragLeave = (event) => {
        event.preventDefault();
        setIsDragOver(false);
    };

    return (
        <AuthenticatedLayout header="Report mensili">
            <Head title="Report mensili studente" />

            <div className="grid gap-6 lg:grid-cols-3">
                <aside className="order-1 space-y-4 lg:order-2">
                    <section className="rounded-3xl border border-slate-200 bg-white p-7 shadow-sm">
                        <h3 className="text-base font-semibold text-slate-800">
                            Carica report firmato
                        </h3>
                        <p className="mt-2 text-sm text-slate-500">
                            Dopo stampa e firma, carica la scansione per approvazione docente.
                        </p>
                        {selectedReport?.rejection_comment && (
                            <div className="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                                <p className="font-semibold">
                                    Report rifiutato: ricarica richiesta
                                </p>
                                <p className="mt-1 whitespace-pre-wrap">
                                    {selectedReport.rejection_comment}
                                </p>
                            </div>
                        )}

                        <form className="mt-5 space-y-4" onSubmit={submitUpload}>
                            <label className="flex flex-col gap-2 text-sm text-slate-600">
                                Report da aggiornare
                                <select
                                    className="rounded-xl border border-slate-200 px-3 py-2.5 text-sm"
                                    value={data.report_id}
                                    onChange={(event) => setData('report_id', event.target.value)}
                                    disabled={uploadCandidates.length === 0}
                                >
                                    {uploadCandidates.length === 0 && (
                                        <option value="">Nessun report disponibile</option>
                                    )}
                                    {uploadCandidates.map((item) => (
                                        <option key={item.report_id} value={item.report_id}>
                                            {item.code} - {item.month}
                                        </option>
                                    ))}
                                </select>
                            </label>

                            <input
                                ref={fileInputRef}
                                type="file"
                                className="hidden"
                                accept=".pdf,.jpg,.jpeg,.png"
                                onChange={(event) => {
                                    const file = event.target.files?.[0] ?? null;
                                    setSelectedFile(file);
                                }}
                            />

                            <div
                                className={`flex min-h-[150px] flex-col items-center justify-center rounded-2xl border border-dashed p-6 text-center text-sm transition ${
                                    isDragOver
                                        ? 'border-slate-500 bg-slate-50 text-slate-700'
                                        : 'border-slate-300 text-slate-500'
                                }`}
                                onDrop={handleFileDrop}
                                onDragOver={handleFileDragOver}
                                onDragLeave={handleFileDragLeave}
                            >
                                <span className="block break-words">
                                    Trascina qui il report firmato oppure selezionalo dal dispositivo.
                                </span>
                            </div>

                            <button
                                type="button"
                                onClick={() => fileInputRef.current?.click()}
                                className="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700"
                                disabled={uploadCandidates.length === 0}
                            >
                                Seleziona file firmato
                            </button>
                            <p className="text-sm text-slate-500">{fileName}</p>
                            {errors.document && (
                                <p className="text-sm text-rose-500">{errors.document}</p>
                            )}

                            <button
                                type="submit"
                                disabled={
                                    processing ||
                                    uploadCandidates.length === 0 ||
                                    !data.document
                                }
                                className="w-full rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-400"
                            >
                                Carica report firmato
                            </button>
                        </form>
                    </section>
                </aside>

                <section className="order-2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:order-1 lg:col-span-2">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-900">
                            Archivio report mensili
                        </h2>
                        <p className="text-sm text-slate-500">
                            Scarica il report originale e carica la versione firmata.
                        </p>

                        <div className="mt-4 grid gap-2 md:grid-cols-[minmax(0,1fr)_220px]">
                            <input
                                className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                placeholder="Cerca mese o codice"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                            />
                            <select
                                className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                value={bucket}
                                onChange={(event) => setBucket(event.target.value)}
                            >
                                {bucketOptions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-xs uppercase tracking-wide text-slate-400">
                                <tr>
                                    <th className="py-3 text-center align-middle">Report</th>
                                    <th className="py-3 text-center align-middle">Mese</th>
                                    <th className="py-3 text-center align-middle">Stato</th>
                                    <th className="py-3 text-center align-middle">Data generato</th>
                                    <th className="py-3 text-center align-middle">Azioni</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {filtered.length === 0 && (
                                    <tr>
                                        <td
                                            className="py-6 text-center text-sm text-slate-400"
                                            colSpan={5}
                                        >
                                            Nessun report trovato.
                                        </td>
                                    </tr>
                                )}
                                {filtered.map((item) => (
                                    <tr key={item.report_id} className="text-slate-600">
                                        <td className="py-3 text-center align-middle font-medium text-slate-900">
                                            {item.code}
                                        </td>
                                        <td className="py-3 text-center align-middle">{item.month}</td>
                                        <td className="py-3 text-center align-middle">
                                            <span
                                                className={`rounded-full px-3 py-1 text-xs font-semibold ${item.badge}`}
                                            >
                                                {item.status_label}
                                            </span>
                                            {item.rejection_comment && (
                                                <p className="mx-auto mt-2 max-w-xs whitespace-pre-wrap text-xs text-rose-600">
                                                    {item.rejection_comment}
                                                </p>
                                            )}
                                        </td>
                                        <td className="py-3 text-center align-middle text-xs">
                                            {item.generated_at ?? '-'}
                                        </td>
                                        <td className="py-3 text-center align-middle">
                                            <div className="inline-flex flex-wrap items-center justify-center gap-2 text-xs">
                                                {item.original_download_url && (
                                                    <a
                                                        href={item.original_download_url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="btn-soft-neutral h-8"
                                                    >
                                                        Scarica originale
                                                    </a>
                                                )}
                                                {item.signed_download_url && (
                                                    <a
                                                        href={item.signed_download_url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="btn-soft-info h-8"
                                                    >
                                                        Scarica firmato
                                                    </a>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
