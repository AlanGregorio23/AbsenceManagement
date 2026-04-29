import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

const actionStyles = {
    resend: 'btn-soft-info',
    approve: 'btn-soft-success',
    reject: 'btn-soft-danger',
};

export default function TeacherMonthlyReportDetail({ item, history = [] }) {
    const resendForm = useForm({});
    const approveForm = useForm({});
    const rejectForm = useForm({ comment: '' });
    const [showRejectForm, setShowRejectForm] = useState(false);
    const previewUrl = item.signed_download_url || item.original_download_url;
    const previewTitle = item.signed_download_url
        ? 'Anteprima documento firmato'
        : 'Anteprima documento originale';

    const submitResend = (event) => {
        event.preventDefault();
        resendForm.post(route('teacher.monthly-reports.resend-email', item.report_id), {
            preserveScroll: true,
        });
    };

    const submitApprove = (event) => {
        event.preventDefault();
        approveForm.post(route('teacher.monthly-reports.approve', item.report_id), {
            preserveScroll: true,
        });
    };

    const submitReject = (event) => {
        event.preventDefault();
        rejectForm.post(route('teacher.monthly-reports.reject', item.report_id), {
            preserveScroll: true,
            onSuccess: () => {
                rejectForm.reset('comment');
                setShowRejectForm(false);
            },
        });
    };

    return (
        <AuthenticatedLayout header={`Dettaglio ${item.code}`}>
            <Head title={`Dettaglio ${item.code}`} />

            <div className="space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="mb-2 text-xs uppercase tracking-wide text-slate-400">
                            Report mensile
                        </p>
                        <h1 className="text-xl font-semibold text-slate-900">
                            {item.code} - {item.student_name}
                        </h1>
                        <p className="text-sm text-slate-500">
                            Classe {item.class_label} - {item.month}
                        </p>
                    </div>
                </div>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
                    <section className="flex min-h-[728px] flex-col rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div>
                            <h2 className="text-sm font-semibold text-slate-800">
                                Dati report
                            </h2>
                            <dl className="mt-3 space-y-2 text-sm text-slate-600">
                                <div className="flex justify-between gap-3">
                                    <dt>Studente</dt>
                                    <dd>{item.student_name}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Classe</dt>
                                    <dd>{item.class_label}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Mese</dt>
                                    <dd>{item.month}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Stato</dt>
                                    <dd>
                                        <span
                                            className={`rounded-full px-3 py-1 text-xs font-semibold ${item.badge}`}
                                        >
                                            {item.status_label}
                                        </span>
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Ore assenza</dt>
                                    <dd>{item.absence_hours}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Ritardi</dt>
                                    <dd>{item.delay_count}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Congedi</dt>
                                    <dd>{item.leave_count}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Certificati mancanti</dt>
                                    <dd>{item.missing_certificates}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Generato</dt>
                                    <dd>{item.generated_at ?? '-'}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Inviato</dt>
                                    <dd>{item.sent_at ?? '-'}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Firmato caricato</dt>
                                    <dd>{item.signed_uploaded_at ?? '-'}</dd>
                                </div>
                            </dl>
                            {item.rejection_comment && (
                                <div className="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                                    <p className="font-semibold">Motivo rifiuto</p>
                                    <p className="mt-1 whitespace-pre-wrap">{item.rejection_comment}</p>
                                </div>
                            )}
                        </div>

                        <div id="azioni" className="mt-auto rounded-xl border border-slate-200 p-3">
                            <h3 className="text-sm font-semibold text-slate-800">
                                Azioni report
                            </h3>
                            <div className="mt-3 flex flex-nowrap items-center gap-2 overflow-x-auto whitespace-nowrap pb-1">
                                {item.can_resend_email && (
                                    <form onSubmit={submitResend}>
                                        <button
                                            type="submit"
                                            className={`h-8 ${actionStyles.resend}`}
                                            disabled={resendForm.processing}
                                        >
                                            Reinvia email
                                        </button>
                                    </form>
                                )}
                                {item.can_approve && (
                                    <form onSubmit={submitApprove}>
                                        <button
                                            type="submit"
                                            className={`h-8 ${actionStyles.approve}`}
                                            disabled={approveForm.processing}
                                        >
                                            Approva report
                                        </button>
                                    </form>
                                )}
                                {item.can_reject && (
                                    <button
                                        type="button"
                                        className={`h-8 ${actionStyles.reject}`}
                                        onClick={() => setShowRejectForm((visible) => !visible)}
                                    >
                                        Rifiuta report
                                    </button>
                                )}
                                {!item.can_resend_email && !item.can_approve && !item.can_reject && (
                                    <span className="text-xs text-slate-500">
                                        Nessuna azione disponibile su questo report.
                                    </span>
                                )}
                            </div>
                            {item.can_reject && showRejectForm && (
                                <form onSubmit={submitReject} className="mt-4 space-y-2">
                                    <label className="block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        Motivo rifiuto
                                    </label>
                                    <textarea
                                        className="min-h-[100px] w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                        value={rejectForm.data.comment}
                                        onChange={(event) =>
                                            rejectForm.setData('comment', event.target.value)
                                        }
                                        placeholder="Scrivi cosa deve correggere o ricaricare lo studente"
                                    />
                                    {rejectForm.errors.comment && (
                                        <p className="text-xs text-rose-500">
                                            {rejectForm.errors.comment}
                                        </p>
                                    )}
                                    <button
                                        type="submit"
                                        className={`h-8 ${actionStyles.reject}`}
                                        disabled={rejectForm.processing}
                                    >
                                        Conferma rifiuto
                                    </button>
                                </form>
                            )}
                            {item.status_code === 'approved' && (
                                <p className="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
                                    Report archiviato: il reinvio email e disabilitato.
                                </p>
                            )}
                        </div>
                    </section>

                    <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="flex items-center justify-between gap-2">
                            <h3 className="text-sm font-semibold text-slate-800">
                                {previewTitle}
                            </h3>
                            <div className="flex flex-nowrap items-center gap-2 overflow-x-auto whitespace-nowrap pb-1">
                                {item.original_download_url ? (
                                    <a
                                        href={item.original_download_url}
                                        className="btn-soft-neutral h-8"
                                    >
                                        Apri originale
                                    </a>
                                ) : (
                                    <span className="rounded-md bg-slate-100 px-3 py-1 text-xs text-slate-500">
                                        Originale non disponibile
                                    </span>
                                )}
                                {item.signed_download_url ? (
                                    <a
                                        href={item.signed_download_url}
                                        className="btn-soft-info h-8"
                                    >
                                        Apri firmato
                                    </a>
                                ) : (
                                    <span className="rounded-md bg-slate-100 px-3 py-1 text-xs text-slate-500">
                                        Firmato non caricato
                                    </span>
                                )}
                            </div>
                        </div>

                        {previewUrl ? (
                            <div className="overflow-hidden rounded-xl border border-slate-200">
                                <iframe
                                    title={`Anteprima ${item.code}`}
                                    src={previewUrl}
                                    className="h-[640px] w-full bg-slate-50"
                                />
                            </div>
                        ) : (
                            <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-16 text-center text-sm text-slate-500">
                                Nessun documento disponibile in anteprima.
                            </div>
                        )}
                    </section>
                </div>

                <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h3 className="text-sm font-semibold text-slate-800">
                        Storico operazioni
                    </h3>
                    <div className="mt-3 space-y-2">
                        {history.length === 0 && (
                            <p className="text-xs text-slate-500">
                                Nessuna operazione registrata.
                            </p>
                        )}
                        {history.map((entry, index) => (
                            <div
                                key={`${entry.action}-${entry.decided_at}-${index}`}
                                className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600"
                            >
                                <p className="font-semibold text-slate-700">{entry.label}</p>
                                <p>{entry.decided_at || '-'}</p>
                                <p>{entry.decided_by || 'Sistema'}</p>
                                {entry.notes && <p className="mt-1">{entry.notes}</p>}
                            </div>
                        ))}
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
