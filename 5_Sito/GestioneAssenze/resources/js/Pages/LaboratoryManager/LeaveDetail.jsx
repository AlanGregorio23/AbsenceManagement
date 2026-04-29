import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { resolveAnnualHoursLimitLabels } from '@/annualHoursLimit';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const inputClass =
    'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-100';

const actionStyles = {
    pre_approve: 'btn-soft-warning',
    approve: 'btn-soft-success',
    reject: 'btn-soft-danger',
    forward_to_management: 'btn-soft-info',
    documentation: 'btn-soft-info',
    reject_documentation: 'btn-soft-danger',
    edit: 'btn-soft-icon',
    delete: 'btn-soft-icon-danger',
};

const ActionGlyph = ({ actionKey, className = 'h-3.5 w-3.5' }) => {
    if (actionKey === 'edit') {
        return (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className={className}>
                <path d="M4 20h4l10-10-4-4L4 16v4zM13 7l4 4M15 5l2-2 4 4-2 2" />
            </svg>
        );
    }

    if (actionKey === 'delete') {
        return (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className={className}>
                <path d="M4 7h16M10 11v6M14 11v6M6 7l1 12h10l1-12M9 7V4h6v3" />
            </svg>
        );
    }

    return null;
};

export default function LeaveDetail({
    item,
    history = [],
    initialAction = '',
    role = '',
    reasons = [],
}) {
    const { props } = usePage();
    const annualHoursLimit = resolveAnnualHoursLimitLabels(props);
    const modalActions = [
        'pre_approve',
        'approve',
        'reject',
        'forward_to_management',
        'documentation',
        'reject_documentation',
        'edit',
        'delete',
    ];

    const isActionAvailable = (actionKey) => {
        const availability = {
            pre_approve: Boolean(item?.can_pre_approve),
            approve: Boolean(item?.can_approve),
            reject: Boolean(item?.can_reject),
            forward_to_management: Boolean(item?.can_forward_to_management),
            documentation: Boolean(item?.can_request_documentation),
            reject_documentation: Boolean(item?.can_reject_documentation),
            edit: Boolean(item?.can_edit),
            delete: Boolean(item?.can_delete),
        };

        return availability[actionKey] ?? false;
    };

    const [activeAction, setActiveAction] = useState(
        modalActions.includes(initialAction) && isActionAvailable(initialAction)
            ? initialAction
            : ''
    );
    const [deleteConfirmChecked, setDeleteConfirmChecked] = useState(false);
    const [deleteConfirmCode, setDeleteConfirmCode] = useState('');

    const preApproveForm = useForm({ comment: '' });
    const approveForm = useForm({ comment: '' });
    const rejectForm = useForm({ comment: '' });
    const forwardToManagementForm = useForm({ comment: '' });
    const documentationForm = useForm({ comment: '' });
    const rejectDocumentationForm = useForm({ comment: '' });
    const deleteForm = useForm({});
    const resendGuardianEmailForm = useForm({});
    const editForm = useForm({
        start_date: item?.start_date ?? '',
        end_date: item?.end_date ?? '',
        hours: item?.hours ?? 1,
        motivation: item?.motivation ?? '',
        destination: item?.destination ?? '',
        count_hours: Boolean(item?.conteggio_40_ore),
        count_hours_comment: item?.conteggio_40_ore_commento ?? '',
        comment: '',
    });
    const expectedDeleteCode = String(item?.id ?? '').trim();
    const deleteCodeMatches = deleteConfirmCode.trim() === expectedDeleteCode;
    const canSubmitDelete = deleteConfirmChecked && deleteCodeMatches && expectedDeleteCode !== '';

    const documentationPreviewUrl = item?.documentation?.viewer_url ?? null;
    const guardianSignaturePreviewUrl = item?.guardian_signature?.viewer_url ?? null;
    const approvalCommentRequired = !item?.documentation;
    const reasonOptions = useMemo(() => {
        if (!Array.isArray(reasons)) {
            return [];
        }

        return reasons
            .map((reason) => String(reason ?? '').trim())
            .filter((reason) => reason !== '');
    }, [reasons]);

    useEffect(() => {
        editForm.setData('start_date', item?.start_date ?? '');
        editForm.setData('end_date', item?.end_date ?? '');
        editForm.setData('hours', item?.hours ?? 1);
        const nextMotivation = reasonOptions.includes(item?.motivation ?? '')
            ? item?.motivation ?? ''
            : (reasonOptions[0] ?? '');
        editForm.setData('motivation', nextMotivation);
        editForm.setData('destination', item?.destination ?? '');
        editForm.setData('count_hours', Boolean(item?.conteggio_40_ore));
        editForm.setData('count_hours_comment', item?.conteggio_40_ore_commento ?? '');
        editForm.setData('comment', '');
        setActiveAction(
            modalActions.includes(initialAction) && isActionAvailable(initialAction)
                ? initialAction
                : ''
        );
        setDeleteConfirmChecked(false);
        setDeleteConfirmCode('');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [item?.leave_id, initialAction, reasonOptions.join('|')]);

    const actions = useMemo(() => {
        const list = [
            {
                key: 'pre_approve',
                label: 'Approva senza firma',
                enabled: item.can_pre_approve,
            },
            { key: 'approve', label: 'Approva', enabled: item.can_approve },
            {
                key: 'forward_to_management',
                label: 'Inoltra in direzione',
                enabled: item.can_forward_to_management,
            },
            {
                key: 'documentation',
                label: 'Richiedi documentazione',
                enabled: item.can_request_documentation,
            },
            { key: 'reject', label: 'Rifiuta', enabled: item.can_reject },
            {
                key: 'edit',
                label: 'Modifica',
                enabled: item.can_edit,
            },
            {
                key: 'delete',
                label: 'Elimina definitivamente',
                enabled: item.can_delete,
            },
        ];

        return list.filter((action) => action.enabled);
    }, [item]);

    const availableActionKeys = useMemo(() => {
        const keys = new Set(actions.map((action) => action.key));
        if (item?.can_reject_documentation) {
            keys.add('reject_documentation');
        }

        return keys;
    }, [actions, item?.can_reject_documentation]);

    const openAction = (actionKey) => {
        setActiveAction(actionKey);
        if (actionKey === 'delete') {
            setDeleteConfirmChecked(false);
            setDeleteConfirmCode('');
        }
    };

    const closeAction = () => {
        setActiveAction('');
        preApproveForm.reset('comment');
        approveForm.reset('comment');
        rejectForm.reset('comment');
        forwardToManagementForm.reset('comment');
        documentationForm.reset('comment');
        rejectDocumentationForm.reset('comment');
        editForm.reset('comment');
        setDeleteConfirmChecked(false);
        setDeleteConfirmCode('');
    };

    const submitPreApprove = (event) => {
        event.preventDefault();
        preApproveForm.post(route('leaves.pre-approve', item.leave_id), {
            preserveScroll: true,
            onSuccess: closeAction,
        });
    };

    const submitApprove = (event) => {
        event.preventDefault();
        approveForm.post(route('leaves.approve', item.leave_id), {
            preserveScroll: true,
            onSuccess: closeAction,
        });
    };

    const submitReject = (event) => {
        event.preventDefault();
        rejectForm.post(route('leaves.reject', item.leave_id), {
            preserveScroll: true,
            onSuccess: closeAction,
        });
    };

    const submitForwardToManagement = (event) => {
        event.preventDefault();
        forwardToManagementForm.post(
            route('leaves.forward-to-management', item.leave_id),
            {
                preserveScroll: true,
                onSuccess: closeAction,
            }
        );
    };

    const submitDocumentation = (event) => {
        event.preventDefault();
        documentationForm.post(
            route('leaves.request-documentation', item.leave_id),
            {
                preserveScroll: true,
                onSuccess: closeAction,
            }
        );
    };

    const submitRejectDocumentation = (event) => {
        event.preventDefault();
        rejectDocumentationForm.post(
            route('leaves.reject-documentation', item.leave_id),
            {
                preserveScroll: true,
                onSuccess: closeAction,
            }
        );
    };

    const submitEdit = (event) => {
        event.preventDefault();
        editForm.post(route('leaves.update', item.leave_id), {
            preserveScroll: true,
            onSuccess: closeAction,
        });
    };

    const submitDelete = (event) => {
        event.preventDefault();
        if (!canSubmitDelete) {
            return;
        }

        deleteForm.delete(route('leaves.destroy', item.leave_id), {
            preserveScroll: true,
            onSuccess: closeAction,
        });
    };

    const submitResendGuardianEmail = (event) => {
        event.preventDefault();
        resendGuardianEmailForm.post(
            route('leaves.resend-guardian-email', item.leave_id),
            {
                preserveScroll: true,
            }
        );
    };

    const modalTitle = useMemo(() => {
        const titles = {
            pre_approve: 'Approva senza firma (override)',
            approve: 'Approvazione congedo',
            reject: 'Rifiuto congedo',
            forward_to_management: 'Inoltro in direzione',
            documentation: 'Richiesta documentazione',
            reject_documentation: 'Rifiuta documentazione',
            edit: 'Modifica congedo',
            delete: 'Elimina congedo',
        };

        return titles[activeAction] ?? 'Azione';
    }, [activeAction]);

    const pageTitle = role === 'teacher' ? 'Dettaglio congedo docente' : 'Dettaglio congedo';
    const isTeacherView = role === 'teacher';

    return (
        <AuthenticatedLayout>
            <Head title={pageTitle} />

            <div className="space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-slate-400">
                            Congedo
                        </p>
                        <h1 className="text-xl font-semibold text-slate-900">
                            {item.id} - {item.studente}
                        </h1>
                        <p className="text-sm text-slate-500">
                            Classe {item.classe} - {item.stato}
                        </p>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div>
                            <h2 className="text-sm font-semibold text-slate-800">
                                Dati congedo
                            </h2>
                            <dl className="mt-3 space-y-2 text-sm text-slate-600">
                                <div className="flex justify-between gap-3">
                                    <dt>Periodo</dt>
                                    <dd>{item.periodo}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Richiesta inviata</dt>
                                    <dd>{item.richiesta_inviata_il || '-'}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Ore</dt>
                                    <dd>{item.hours} ore</dd>
                                </div>
                                {item.requested_lessons_label && (
                                    <div className="flex justify-between gap-3">
                                        <dt>Periodi scolastici</dt>
                                        <dd>{item.requested_lessons_label}</dd>
                                    </div>
                                )}
                                <div className="flex justify-between gap-3">
                                    <dt>Destinazione</dt>
                                    <dd>{item.destination || '-'}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Firma tutore</dt>
                                    <dd>{item.firma_tutore_label}</dd>
                                </div>
                            </dl>
                            <p className="mt-3 text-sm text-slate-700">
                                <span className="font-semibold">Motivo:</span>{' '}
                                {item.motivo}
                            </p>
                            {(item.workflow_comment || item.commento_workflow) && (
                                <p className="mt-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                                    Commento workflow: {item.workflow_comment || item.commento_workflow}
                                </p>
                            )}
                            {Boolean(item.richiesta_tardiva) && (
                                <p className="mt-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                                    {item.richiesta_tardiva_label
                                        || 'Richiesta inviata oltre il termine minimo previsto.'}
                                </p>
                            )}
                            {!isTeacherView && item?.hours_limit_warning?.show && (
                                <div className="mt-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                                    <p className="font-semibold">
                                        {item.hours_limit_warning.title || 'Avviso limite ore'}
                                    </p>
                                    <p className="mt-1">
                                        {item.hours_limit_warning.message}
                                    </p>
                                </div>
                            )}
                        </div>

                        <div className="rounded-xl border border-slate-200 p-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h3 className="text-sm font-semibold text-slate-800">
                                    Firma tutore
                                </h3>
                                {guardianSignaturePreviewUrl && (
                                    <a
                                        href={guardianSignaturePreviewUrl}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                    >
                                        Apri
                                    </a>
                                )}
                            </div>
                            {!item.guardian_signature && (
                                <div className="mt-3 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
                                    Nessuna firma tutore registrata.
                                </div>
                            )}
                            {item.guardian_signature && (
                                <>
                                    <div className="mt-3 grid gap-2 text-xs text-slate-500 sm:grid-cols-2">
                                        <p>
                                            <span className="font-semibold text-slate-700">
                                                Firmato da:
                                            </span>{' '}
                                            {item.guardian_signature.guardian_name || '-'}
                                        </p>
                                        <p>
                                            <span className="font-semibold text-slate-700">
                                                Data firma:
                                            </span>{' '}
                                            {item.guardian_signature.signed_at || '-'}
                                        </p>
                                    </div>
                                    <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
                                        <img
                                            src={guardianSignaturePreviewUrl}
                                            alt={`Firma tutore ${item.id}`}
                                            className="max-h-64 w-full object-contain p-3"
                                        />
                                    </div>
                                </>
                            )}
                        </div>

                        {isTeacherView && (
                            <section className="space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <p className="text-sm font-semibold text-slate-700">
                                    Sola lettura
                                </p>
                                {item.can_delete && (
                                    <button
                                        type="button"
                                        title="Elimina definitivamente"
                                        aria-label="Elimina definitivamente"
                                        className="btn-soft-icon-danger"
                                        onClick={() => openAction('delete')}
                                    >
                                        <ActionGlyph actionKey="delete" className="h-4 w-4" />
                                    </button>
                                )}
                            </section>
                        )}

                        {!isTeacherView && (
                            <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                                <h2 className="text-lg font-semibold text-slate-900">
                                    Azioni disponibili
                                </h2>
                                <p className="text-sm text-slate-500">
                                    Mostrate solo le azioni realmente possibili in questo momento.
                                </p>
                                {actions.length === 0 && (
                                    <p className="text-sm text-slate-500">
                                        Nessuna azione disponibile.
                                    </p>
                                )}
                                {actions.length > 0 && (
                                    <div className="flex w-full flex-nowrap items-center gap-2 overflow-x-auto whitespace-nowrap pb-1">
                                        {actions.map((action) => {
                                            const iconOnly = action.key === 'edit' || action.key === 'delete';
                                            const buttonClass = iconOnly
                                                ? actionStyles[action.key]
                                                : `${actionStyles[action.key]} whitespace-nowrap`;

                                            return (
                                                <button
                                                    key={action.key}
                                                    type="button"
                                                    title={action.label}
                                                    aria-label={action.label}
                                                    className={`shrink-0 ${buttonClass}`}
                                                    onClick={() => openAction(action.key)}
                                                >
                                                    {iconOnly ? (
                                                        <ActionGlyph actionKey={action.key} className="h-4 w-4" />
                                                    ) : (
                                                        <span className="inline-flex items-center gap-1.5">
                                                            <ActionGlyph actionKey={action.key} />
                                                            <span>{action.label}</span>
                                                        </span>
                                                    )}
                                                </button>
                                            );
                                        })}
                                    </div>
                                )}
                                {item.can_resend_guardian_email && (
                                    <form onSubmit={submitResendGuardianEmail}>
                                        <button
                                            type="submit"
                                            className="btn-soft-info whitespace-nowrap"
                                            disabled={resendGuardianEmailForm.processing}
                                        >
                                            Reinvia email conferma tutore
                                        </button>
                                    </form>
                                )}
                                {item.forwarding_pdf_url && (
                                    <a
                                        href={item.forwarding_pdf_url}
                                        className="btn-soft-neutral h-8 text-xs"
                                    >
                                        Scarica PDF inoltro direzione
                                    </a>
                                )}
                            </section>
                        )}
                    </section>

                    <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="rounded-xl border border-slate-200 p-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h3 className="text-sm font-semibold text-slate-800">
                                    Documentazione / talloncino
                                </h3>
                                {documentationPreviewUrl && (
                                    <a
                                        href={documentationPreviewUrl}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="btn-soft-neutral h-8"
                                    >
                                        Apri
                                    </a>
                                )}
                            </div>
                            {!item.documentation && (
                                <div className="mt-3 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
                                    Nessun file caricato.
                                </div>
                            )}
                            {item.documentation && (
                                <>
                                    <div className="mt-3 grid gap-2 text-xs text-slate-500 sm:grid-cols-2">
                                        <p>
                                            <span className="font-semibold text-slate-700">
                                                File:
                                            </span>{' '}
                                            {item.documentation.filename}
                                        </p>
                                        <p>
                                            <span className="font-semibold text-slate-700">
                                                Caricato:
                                            </span>{' '}
                                            {item.documentation.uploaded_at || '-'}
                                        </p>
                                    </div>
                                    {documentationPreviewUrl && (
                                        <div className="mt-3 overflow-hidden rounded-xl border border-slate-200">
                                            <iframe
                                                title={`Documentazione ${item.id}`}
                                                src={documentationPreviewUrl}
                                                className="h-[460px] w-full bg-slate-50"
                                            />
                                        </div>
                                    )}
                                </>
                            )}
                            {item.documentation_request_comment && (
                                <p className="mt-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-700">
                                    Richiesta documentazione: {item.documentation_request_comment}
                                </p>
                            )}
                            {!isTeacherView && item.can_reject_documentation && (
                                <div className="mt-3 flex justify-end">
                                    <button
                                        type="button"
                                        className="btn-soft-danger"
                                        onClick={() => openAction('reject_documentation')}
                                    >
                                        Rifiuta documentazione
                                    </button>
                                </div>
                            )}
                        </div>

                    </section>
                </div>

                <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h3 className="text-sm font-semibold text-slate-800">
                        Storico decisioni
                    </h3>
                    <div className="mt-3 space-y-2">
                        {history.length === 0 && (
                            <p className="text-xs text-slate-500">
                                Nessuna decisione registrata.
                            </p>
                        )}
                        {history.map((entry, index) => (
                            <div
                                key={`${entry.decision}-${entry.decided_at}-${index}`}
                                className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600"
                            >
                                <p className="font-semibold text-slate-700">{entry.label}</p>
                                <p>{entry.decided_at || '-'}</p>
                                <p>{entry.decided_by || 'Sistema'}</p>
                                {entry.override_guardian_signature && (
                                    <p className="text-amber-700">Override firma tutore</p>
                                )}
                                {entry.notes && <p className="mt-1">{entry.notes}</p>}
                            </div>
                        ))}
                    </div>
                </section>

                {activeAction && (!isTeacherView || activeAction === 'delete') && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                        <div className="w-full max-w-2xl rounded-2xl bg-white p-5 shadow-2xl">
                            <h3 className="text-lg font-semibold text-slate-900">
                                {modalTitle}
                            </h3>

                            {activeAction === 'pre_approve' && availableActionKeys.has('pre_approve') && (
                                <form onSubmit={submitPreApprove} className="mt-4 space-y-3">
                                    <label className="block text-xs font-semibold text-slate-700">
                                        Commento override obbligatorio
                                    </label>
                                    <textarea
                                        rows={4}
                                        className={inputClass}
                                        value={preApproveForm.data.comment}
                                        onChange={(event) =>
                                            preApproveForm.setData('comment', event.target.value)
                                        }
                                    />
                                    {preApproveForm.errors.comment && (
                                        <p className="text-xs text-rose-600">{preApproveForm.errors.comment}</p>
                                    )}
                                    <div className="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700"
                                            onClick={closeAction}
                                        >
                                            Annulla
                                        </button>
                                        <button
                                            type="submit"
                                            className="rounded-lg bg-amber-400 px-4 py-2 text-xs font-semibold text-slate-900 disabled:cursor-not-allowed disabled:bg-amber-200"
                                            disabled={preApproveForm.processing}
                                        >
                                            Salva
                                        </button>
                                    </div>
                                </form>
                            )}

                            {activeAction === 'approve' && availableActionKeys.has('approve') && (
                                <form onSubmit={submitApprove} className="mt-4 space-y-3">
                                    <label className="block text-xs font-semibold text-slate-700">
                                        {approvalCommentRequired ? 'Commento obbligatorio' : 'Commento (facoltativo)'}
                                    </label>
                                    <textarea
                                        rows={3}
                                        className={inputClass}
                                        value={approveForm.data.comment}
                                        onChange={(event) =>
                                            approveForm.setData('comment', event.target.value)
                                        }
                                    />
                                    {(approveForm.errors.comment || approveForm.errors.count_hours_comment) && (
                                        <p className="text-xs text-rose-600">
                                            {approveForm.errors.comment || approveForm.errors.count_hours_comment}
                                        </p>
                                    )}
                                    <div className="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700"
                                            onClick={closeAction}
                                        >
                                            Annulla
                                        </button>
                                        <button
                                            type="submit"
                                            className="rounded-lg bg-lime-500 px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:bg-lime-300"
                                            disabled={approveForm.processing}
                                        >
                                            Salva
                                        </button>
                                    </div>
                                </form>
                            )}

                            {activeAction === 'reject' && availableActionKeys.has('reject') && (
                                <form onSubmit={submitReject} className="mt-4 space-y-3">
                                    <label className="block text-xs font-semibold text-slate-700">
                                        Motivo obbligatorio
                                    </label>
                                    <textarea
                                        rows={4}
                                        className={inputClass}
                                        value={rejectForm.data.comment}
                                        onChange={(event) =>
                                            rejectForm.setData('comment', event.target.value)
                                        }
                                    />
                                    {rejectForm.errors.comment && (
                                        <p className="text-xs text-rose-600">{rejectForm.errors.comment}</p>
                                    )}
                                    <div className="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700"
                                            onClick={closeAction}
                                        >
                                            Annulla
                                        </button>
                                        <button
                                            type="submit"
                                            className="rounded-lg bg-red-500 px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:bg-red-300"
                                            disabled={rejectForm.processing}
                                        >
                                            Salva
                                        </button>
                                    </div>
                                </form>
                            )}

                            {activeAction === 'forward_to_management'
                                && availableActionKeys.has('forward_to_management') && (
                                    <form
                                        onSubmit={submitForwardToManagement}
                                        className="mt-4 space-y-3"
                                    >
                                        <label className="block text-xs font-semibold text-slate-700">
                                            Motivazione inoltro (obbligatoria)
                                        </label>
                                        <textarea
                                            rows={4}
                                            className={inputClass}
                                            value={forwardToManagementForm.data.comment}
                                            onChange={(event) =>
                                                forwardToManagementForm.setData(
                                                    'comment',
                                                    event.target.value
                                                )
                                            }
                                        />
                                        {forwardToManagementForm.errors.comment && (
                                            <p className="text-xs text-rose-600">
                                                {forwardToManagementForm.errors.comment}
                                            </p>
                                        )}
                                        <div className="flex justify-end gap-2">
                                            <button
                                                type="button"
                                                className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700"
                                                onClick={closeAction}
                                            >
                                                Annulla
                                            </button>
                                            <button
                                                type="submit"
                                                className="rounded-lg bg-violet-600 px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:bg-violet-300"
                                                disabled={forwardToManagementForm.processing}
                                            >
                                                Inoltra
                                            </button>
                                        </div>
                                    </form>
                                )}

                            {activeAction === 'documentation' && availableActionKeys.has('documentation') && (
                                <form onSubmit={submitDocumentation} className="mt-4 space-y-3">
                                    <label className="block text-xs font-semibold text-slate-700">
                                        Commento obbligatorio
                                    </label>
                                    <textarea
                                        rows={4}
                                        className={inputClass}
                                        value={documentationForm.data.comment}
                                        onChange={(event) =>
                                            documentationForm.setData('comment', event.target.value)
                                        }
                                    />
                                    {documentationForm.errors.comment && (
                                        <p className="text-xs text-rose-600">{documentationForm.errors.comment}</p>
                                    )}
                                    <div className="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700"
                                            onClick={closeAction}
                                        >
                                            Annulla
                                        </button>
                                        <button
                                            type="submit"
                                            className="rounded-lg bg-sky-500 px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:bg-sky-300"
                                            disabled={documentationForm.processing}
                                        >
                                            Salva
                                        </button>
                                    </div>
                                </form>
                            )}

                            {activeAction === 'reject_documentation' && availableActionKeys.has('reject_documentation') && (
                                <form onSubmit={submitRejectDocumentation} className="mt-4 space-y-3">
                                    <label className="block text-xs font-semibold text-slate-700">
                                        Motivo rifiuto documentazione
                                    </label>
                                    <textarea
                                        rows={4}
                                        className={inputClass}
                                        value={rejectDocumentationForm.data.comment}
                                        onChange={(event) =>
                                            rejectDocumentationForm.setData('comment', event.target.value)
                                        }
                                    />
                                    {rejectDocumentationForm.errors.comment && (
                                        <p className="text-xs text-rose-600">{rejectDocumentationForm.errors.comment}</p>
                                    )}
                                    <div className="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700"
                                            onClick={closeAction}
                                        >
                                            Annulla
                                        </button>
                                        <button
                                            type="submit"
                                            className="rounded-lg bg-rose-500 px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:bg-rose-300"
                                            disabled={rejectDocumentationForm.processing}
                                        >
                                            Salva
                                        </button>
                                    </div>
                                </form>
                            )}

                            {activeAction === 'edit' && availableActionKeys.has('edit') && (
                                <form onSubmit={submitEdit} className="mt-4 space-y-3">
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <label className="text-xs font-semibold text-slate-700">
                                            Data inizio
                                            <input
                                                type="date"
                                                className={`${inputClass} mt-1`}
                                                value={editForm.data.start_date}
                                                onChange={(event) =>
                                                    editForm.setData('start_date', event.target.value)
                                                }
                                            />
                                        </label>
                                        <label className="text-xs font-semibold text-slate-700">
                                            Data fine
                                            <input
                                                type="date"
                                                className={`${inputClass} mt-1`}
                                                value={editForm.data.end_date}
                                                min={editForm.data.start_date || undefined}
                                                onChange={(event) =>
                                                    editForm.setData('end_date', event.target.value)
                                                }
                                            />
                                        </label>
                                        <label className="text-xs font-semibold text-slate-700">
                                            Ore
                                            <input
                                                type="number"
                                                min="1"
                                                className={`${inputClass} mt-1`}
                                                value={editForm.data.hours}
                                                onChange={(event) =>
                                                    editForm.setData('hours', Number(event.target.value || 0))
                                                }
                                            />
                                        </label>
                                        <label className="text-xs font-semibold text-slate-700">
                                            Destinazione
                                            <input
                                                type="text"
                                                className={`${inputClass} mt-1`}
                                                value={editForm.data.destination}
                                                onChange={(event) =>
                                                    editForm.setData('destination', event.target.value)
                                                }
                                            />
                                        </label>
                                    </div>
                                    <label className="block text-xs font-semibold text-slate-700">
                                        Motivo (regole)
                                    </label>
                                    <select
                                        className={inputClass}
                                        value={editForm.data.motivation}
                                        onChange={(event) =>
                                            editForm.setData('motivation', event.target.value)
                                        }
                                        disabled={reasonOptions.length === 0}
                                    >
                                        {reasonOptions.length === 0 && (
                                            <option value="">
                                                Nessun motivo configurato
                                            </option>
                                        )}
                                        {reasonOptions.map((reason) => (
                                            <option key={reason} value={reason}>
                                                {reason}
                                            </option>
                                        ))}
                                    </select>
                                    <label className="flex items-center gap-2 text-xs text-slate-700">
                                        <input
                                            type="checkbox"
                                            checked={Boolean(editForm.data.count_hours)}
                                            onChange={(event) =>
                                                editForm.setData('count_hours', event.target.checked)
                                            }
                                        />
                                        {annualHoursLimit.included}
                                    </label>
                                    {!editForm.data.count_hours && (
                                        <>
                                            <label className="block text-xs font-semibold text-slate-700">
                                                {annualHoursLimit.countedNote}
                                            </label>
                                            <textarea
                                                rows={3}
                                                className={inputClass}
                                                value={editForm.data.count_hours_comment}
                                                onChange={(event) =>
                                                    editForm.setData(
                                                        'count_hours_comment',
                                                        event.target.value
                                                    )
                                                }
                                            />
                                        </>
                                    )}
                                    <label className="block text-xs font-semibold text-slate-700">
                                        Commento modifica obbligatorio
                                    </label>
                                    <textarea
                                        rows={3}
                                        className={inputClass}
                                        value={editForm.data.comment}
                                        onChange={(event) =>
                                            editForm.setData('comment', event.target.value)
                                        }
                                    />
                                    {(editForm.errors.start_date
                                        || editForm.errors.end_date
                                        || editForm.errors.hours
                                        || editForm.errors.motivation
                                        || editForm.errors.destination
                                        || editForm.errors.count_hours_comment
                                        || editForm.errors.comment) && (
                                        <p className="text-xs text-rose-600">
                                            {editForm.errors.start_date
                                                || editForm.errors.end_date
                                                || editForm.errors.hours
                                                || editForm.errors.motivation
                                                || editForm.errors.destination
                                                || editForm.errors.count_hours_comment
                                                || editForm.errors.comment}
                                        </p>
                                    )}
                                    <div className="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700"
                                            onClick={closeAction}
                                        >
                                            Annulla
                                        </button>
                                        <button
                                            type="submit"
                                            className="rounded-lg bg-slate-700 px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-400"
                                            disabled={editForm.processing || reasonOptions.length === 0}
                                        >
                                            Salva
                                        </button>
                                    </div>
                                </form>
                            )}

                            {activeAction === 'delete' && availableActionKeys.has('delete') && (
                                <form onSubmit={submitDelete} className="mt-4 space-y-3">
                                    <p className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                                        Questa operazione elimina definitivamente il congedo e le eventuali assenze generate dalla pratica.
                                    </p>
                                    <label className="flex items-center gap-2 text-xs font-semibold text-slate-700">
                                        <input
                                            type="checkbox"
                                            checked={deleteConfirmChecked}
                                            onChange={(event) => setDeleteConfirmChecked(event.target.checked)}
                                        />
                                        Confermo eliminazione definitiva
                                    </label>
                                    <label className="block text-xs font-semibold text-slate-700">
                                        Scrivi <span className="rounded bg-slate-100 px-1 py-0.5">{expectedDeleteCode}</span> per confermare
                                    </label>
                                    <input
                                        type="text"
                                        className={inputClass}
                                        value={deleteConfirmCode}
                                        onChange={(event) => setDeleteConfirmCode(event.target.value)}
                                        placeholder={expectedDeleteCode}
                                        autoComplete="off"
                                    />
                                    {deleteConfirmCode.trim() !== '' && !deleteCodeMatches && (
                                        <p className="text-xs text-rose-600">
                                            Codice non valido. Inserisci esattamente {expectedDeleteCode}.
                                        </p>
                                    )}
                                    <div className="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700"
                                            onClick={closeAction}
                                        >
                                            Annulla
                                        </button>
                                        <button
                                            type="submit"
                                            className="rounded-lg bg-rose-600 px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:bg-rose-300"
                                            disabled={deleteForm.processing || !canSubmitDelete}
                                        >
                                            Elimina definitivamente
                                        </button>
                                    </div>
                                </form>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
