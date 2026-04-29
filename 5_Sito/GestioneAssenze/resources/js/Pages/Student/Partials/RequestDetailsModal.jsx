import Modal from '@/Components/Modal';
import { resolveAnnualHoursLimitLabels } from '@/annualHoursLimit';
import { usePage } from '@inertiajs/react';

const baseStatusBadge = 'bg-slate-100 text-slate-700';

export default function RequestDetailsModal({ item = null, open = false, onClose = () => {} }) {
    const { props } = usePage();
    const annualHoursLimit = resolveAnnualHoursLimitLabels(props);

    if (!item) {
        return null;
    }

    const isLeave = item.tipo === 'Congedo';
    const detailFields = [
        { label: 'Tipo', content: item.tipo ?? '-' },
        ...(!isLeave ? [{ label: 'Data', content: item.data ?? '-' }] : []),
        { label: 'Durata', content: item.durata ?? '-' },
        { label: 'Motivo', content: item.motivo ?? '-' },
        {
            label: 'Stato',
            content: (
                <span
                    className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${item.badge ?? baseStatusBadge}`}
                >
                    {item.stato ?? '-'}
                </span>
            ),
        },
    ];

    if (item.scadenza && item.scadenza !== '-') {
        detailFields.push({
            label: 'Scadenza completamento',
            content: item.scadenza,
        });
    }

    if (item.countdown && item.countdown !== '-') {
        detailFields.push({
            label: 'Tempo residuo',
            content: item.countdown,
        });
    }

    if (item.tipo === 'Assenza') {
        const teacherComment = String(item.commento_docente ?? '').trim();
        const rejectedCertificateComment = String(
            item.commento_rifiuto_certificato ?? ''
        ).trim();

        detailFields.push(
            {
                label: 'Obbligo certificato',
                content: (
                    <span
                        className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${item.certificato_obbligo_badge ?? baseStatusBadge}`}
                    >
                        {item.certificato_obbligo_short ?? 'Non richiesto'}
                    </span>
                ),
            },
            {
                label: 'Firma tutore',
                content: item.firma_tutore_label
                    ?? (item.firma_tutore_presente
                        ? item.firma_tutore_data
                            ? `Presente (${item.firma_tutore_data})`
                            : 'Presente'
                        : 'Assente'),
            },
            {
                label: annualHoursLimit.counted,
                content: item.conteggio_40_ore_label ?? '-',
            }
        );

        if (item.derived_leave_code) {
            detailFields.push({
                label: 'Derivata da congedo',
                content: item.derived_leave_code,
            });
        }

        if (teacherComment !== '') {
            detailFields.push({
                label: 'Commento docente',
                content: teacherComment,
            });
        }

        if (rejectedCertificateComment !== '') {
            detailFields.push({
                label: 'Commento rifiuto certificato',
                content: rejectedCertificateComment,
            });
        }
    }

    if (isLeave && item.periodo) {
        detailFields.push({
            label: 'Periodo',
            content: item.periodo,
        });
        detailFields.push({
            label: 'Destinazione',
            content: item.destinazione || item.destination || '-',
        });

        if (item.conteggio_40_ore_label) {
            detailFields.push({
                label: annualHoursLimit.counted,
                content: item.conteggio_40_ore_label,
            });
        }

        if (item.commento_workflow) {
            detailFields.push({
                label: 'Commento scuola',
                content: item.commento_workflow,
            });
        }

        if (item.can_download_forwarding_pdf && item.forwarding_pdf_url) {
            detailFields.push({
                label: 'PDF inoltro direzione',
                content: (
                    <a
                        href={item.forwarding_pdf_url}
                        target="_blank"
                        rel="noreferrer"
                        className="btn-soft-neutral h-8"
                    >
                        Scarica
                    </a>
                ),
            });
        }
    }

    if (item.tipo === 'Ritardo') {
        const teacherComment = String(item.commento_docente ?? '').trim();

        detailFields.push({
            label: 'Firma tutore',
            content:
                item.firma_tutore_label
                ?? (item.firma_tutore_presente
                    ? item.firma_tutore_data
                        ? `Presente (${item.firma_tutore_data})`
                        : 'Presente'
                    : 'Assente'),
        });

        if (teacherComment !== '') {
            detailFields.push({
                label: 'Commento docente',
                content: teacherComment,
            });
        }
    }

    return (
        <Modal show={open} onClose={onClose} maxWidth="2xl">
            <div className="max-h-[calc(100vh-7rem)] overflow-y-auto p-4 sm:max-h-[80vh] sm:p-5">
                <div className="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-slate-400">
                            Dettaglio richiesta
                        </p>
                        <h3 className="text-lg font-semibold text-slate-900">
                            {item.id ?? 'Richiesta'}
                        </h3>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                    >
                        Chiudi
                    </button>
                </div>

                <dl className="grid gap-x-6 gap-y-4 grid-cols-1 sm:grid-cols-2">
                    {detailFields.map((field) => (
                        <div key={`${item.id}-${field.label}`}>
                            <dt className="text-xs uppercase tracking-wide text-slate-400">
                                {field.label}
                            </dt>
                            <dd className="mt-1 text-sm text-slate-700">
                                {field.content}
                            </dd>
                        </div>
                    ))}
                </dl>

                {item.tipo === 'Assenza' && item.can_submit_draft && (
                    <div className="mt-4 space-y-3 rounded-xl border border-sky-200 bg-sky-50 p-4">
                        <p className="text-xs font-semibold uppercase tracking-wide text-sky-700">
                            Bozza assenza da congedo
                        </p>
                        <p className="text-xs text-sky-700">
                            Apri la pagina bozza, modifica solo le ore e invia l assenza ufficiale.
                        </p>
                        <div className="flex justify-end">
                            <a
                                href={item.draft_edit_url}
                                className="btn-soft-info h-8"
                            >
                                Apri pagina bozza
                            </a>
                        </div>
                    </div>
                )}
            </div>
        </Modal>
    );
}
