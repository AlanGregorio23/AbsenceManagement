import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export default function Documents({
    documents = [],
    absenceOptions = [],
    leaveOptions = [],
    uploadOptions = [],
}) {
    const page = usePage();
    const fileInputRef = useRef(null);
    const preferredTargetKeyRef = useRef('');
    const [fileName, setFileName] = useState('Nessun file selezionato');
    const [isDragOver, setIsDragOver] = useState(false);

    const normalizedUploadOptions =
        uploadOptions.length > 0
            ? uploadOptions
            : [
                  ...absenceOptions.map((absence) => ({
                      key: `absence:${absence.id}`,
                      type: 'absence',
                      target_id: absence.id,
                      label: absence.label,
                      subtitle: `Assenza del ${
                          absence.giorno_assenza ?? absence.data ?? absence.scadenza
                      }`,
                      status:
                          absence.certificato_obbligo_short ??
                          (absence.richiede_certificato
                              ? 'Necessario'
                              : 'Non richiesto'),
                      comment: absence.certificate_rejection_comment ?? '',
                  })),
                  ...leaveOptions.map((leave) => ({
                      key: `leave:${leave.id}`,
                      type: 'leave',
                      target_id: leave.id,
                      label: leave.label,
                      subtitle: `Congedo - ${leave.stato}`,
                      status: 'Documentazione / scansione',
                      comment: leave.commento ?? '',
                  })),
              ];

    const { data, setData, post, processing, errors, reset } = useForm({
        target_key: normalizedUploadOptions[0]?.key ?? '',
        document: null,
    });

    useEffect(() => {
        const search = String(page.url ?? '').split('?')[1] ?? '';
        const params = new URLSearchParams(search);
        preferredTargetKeyRef.current = String(params.get('target') ?? '').trim();
    }, [page.url]);

    useEffect(() => {
        if (normalizedUploadOptions.length === 0) {
            if (data.target_key !== '') {
                setData('target_key', '');
            }
            return;
        }

        const preferredTargetKey = preferredTargetKeyRef.current;
        if (preferredTargetKey !== '') {
            const hasPreferredOption = normalizedUploadOptions.some(
                (option) => String(option.key) === preferredTargetKey
            );
            preferredTargetKeyRef.current = '';

            if (hasPreferredOption && data.target_key !== preferredTargetKey) {
                setData('target_key', preferredTargetKey);
                return;
            }
        }

        const hasCurrentOption = normalizedUploadOptions.some(
            (option) => String(option.key) === String(data.target_key)
        );

        if (!hasCurrentOption) {
            setData('target_key', normalizedUploadOptions[0].key);
        }
    }, [normalizedUploadOptions, data.target_key, setData]);

    const selectedTarget = normalizedUploadOptions.find(
        (option) => String(option.key) === String(data.target_key)
    );

    const selectFile = () => {
        fileInputRef.current?.click();
    };

    const setFile = (file) => {
        setData('document', file);
        setFileName(file ? file.name : 'Nessun file selezionato');
    };

    const handleFileChange = (event) => {
        const file = event.target.files?.[0] ?? null;
        setFile(file);
    };

    const handleFileDrop = (event) => {
        event.preventDefault();
        setIsDragOver(false);

        const file = event.dataTransfer.files?.[0] ?? null;
        setFile(file);
    };

    const handleFileDragOver = (event) => {
        event.preventDefault();
        setIsDragOver(true);
    };

    const handleFileDragLeave = (event) => {
        event.preventDefault();
        setIsDragOver(false);
    };

    const submitUpload = (event) => {
        event.preventDefault();
        if (!selectedTarget || !data.document) {
            return;
        }

        const routeName =
            selectedTarget.type === 'leave'
                ? 'student.leaves.documentation.upload'
                : 'student.absences.certificate.upload';

        post(route(routeName, selectedTarget.target_id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset('document');
                setFileName('Nessun file selezionato');
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            },
        });
    };

    const uploadCard = (
        <section className="rounded-3xl border border-slate-200 bg-white p-7 shadow-sm">
            <h3 className="text-base font-semibold text-slate-800">
                Upload rapido
            </h3>
            <p className="mt-2 text-sm text-slate-500">
                Carica certificati, talloncini o email su assenze e congedi.
            </p>
            <form onSubmit={submitUpload} className="mt-5 space-y-4">
                <label className="flex flex-col gap-2 text-sm text-slate-600">
                    Seleziona richiesta
                    <select
                        className="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"
                        value={data.target_key}
                        onChange={(event) =>
                            setData('target_key', event.target.value)
                        }
                        disabled={normalizedUploadOptions.length === 0}
                    >
                        {normalizedUploadOptions.length === 0 && (
                            <option value="">Nessuna richiesta disponibile</option>
                        )}
                        {normalizedUploadOptions.map((option) => (
                            <option key={option.key} value={option.key}>
                                {option.label} - {option.subtitle}
                            </option>
                        ))}
                    </select>
                </label>

                {selectedTarget?.status && (
                    <p className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                        Stato: {selectedTarget.status}
                    </p>
                )}

                {String(selectedTarget?.comment ?? '').trim() !== '' && (
                    <p className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">
                        Commento scuola: {selectedTarget.comment}
                    </p>
                )}

                <input
                    ref={fileInputRef}
                    type="file"
                    className="hidden"
                    accept=".pdf,.jpg,.jpeg,.png"
                    onChange={handleFileChange}
                />
                <div
                    className={`flex min-h-[150px] flex-col items-center justify-center rounded-2xl border border-dashed p-6 text-center text-sm transition ${isDragOver
                        ? 'border-slate-500 bg-slate-50 text-slate-700'
                        : 'border-slate-300 text-slate-500'
                    }`}
                    onDrop={handleFileDrop}
                    onDragOver={handleFileDragOver}
                    onDragLeave={handleFileDragLeave}
                >
                    <span className="block break-words">
                        Trascina qui il file oppure selezionalo dal dispositivo.
                    </span>
                </div>
                <button
                    type="button"
                    className="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700"
                    onClick={selectFile}
                >
                    Seleziona file
                </button>
                <p className="text-sm text-slate-500">{fileName}</p>
                {errors.document && (
                    <p className="text-sm text-rose-500">{errors.document}</p>
                )}
                <button
                    type="submit"
                    className="w-full rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-400"
                    disabled={processing || !selectedTarget || !data.document}
                >
                    {selectedTarget?.type === 'leave'
                        ? 'Carica documentazione congedo'
                        : 'Carica certificato assenza'}
                </button>
            </form>
        </section>
    );

    return (
        <AuthenticatedLayout header="Documenti">
            <Head title="Documenti" />

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="lg:hidden">{uploadCard}</div>

                <section className="min-w-0 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                    <div className="flex items-center justify-between">
                        <div className="space-y-1">
                            <h2 className="text-lg font-semibold text-slate-900">
                                Archivio documenti
                            </h2>
                            <p className="text-sm text-slate-500">
                                Documenti caricati per assenze e congedi.
                            </p>
                        </div>
                    </div>

                    <div className="mt-4 space-y-3">
                        {documents.length === 0 && (
                            <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
                                Nessun documento disponibile.
                            </div>
                        )}
                        {documents.map((doc) => (
                            <div
                                key={doc.id}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3"
                            >
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-semibold text-slate-900">
                                        {doc.nome}
                                    </p>
                                    <p className="truncate text-xs text-slate-500">
                                        {doc.tipo} - {doc.origine} - {doc.data}
                                    </p>
                                </div>
                                <span
                                    className={`rounded-full px-3 py-1 text-xs font-semibold ${doc.badge}`}
                                >
                                    {doc.stato}
                                </span>
                            </div>
                        ))}
                    </div>
                </section>

                <aside className="hidden lg:block lg:sticky lg:top-[96px] lg:h-fit">
                    {uploadCard}
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
