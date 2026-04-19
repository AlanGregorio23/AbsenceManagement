import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import { Head, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const inputClass =
    'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-100';

const actionStyles = {
    approve: 'border-lime-500 bg-lime-500 text-white hover:bg-lime-600',
    approve_without_guardian: 'border-green-500 bg-green-500 text-white hover:bg-green-600',
    reject: 'border-sky-500 bg-sky-500 text-white hover:bg-sky-600',
    extend: 'border-amber-400 bg-amber-400 text-slate-900 hover:bg-amber-500',
    delete: 'border-rose-600 bg-rose-600 text-white hover:bg-rose-700',
    edit: 'border-slate-600 bg-slate-600 text-white hover:bg-slate-700',
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

export default function TeacherDelayDetail({
    item,
    initialAction = '',
    history = [],
}) {
    const today = new Date().toISOString().slice(0, 10);
    const modalActions = ['approve', 'approve_without_guardian', 'reject', 'extend', 'edit', 'delete'];
    const [activeAction, setActiveAction] = useState(
        modalActions.includes(initialAction) ? initialAction : ''
    );
    const [deleteConfirmChecked, setDeleteConfirmChecked] = useState(false);
    const [deleteConfirmCode, setDeleteConfirmCode] = useState('');

    const approveForm = useForm({ comment: '' });
    const approveWithoutGuardianForm = useForm({ comment: '' });
    const rejectForm = useForm({ comment: '' });
    const extendForm = useForm({ extension_days: 2, comment: '' });
    const deleteForm = useForm({});
    const resendGuardianEmailForm = useForm({});
    const updateForm = useForm({
        delay_date: item.delay_date ?? item.date ?? '',
        delay_minutes: item.minutes ?? 0,
        motivation: item.motivation ?? item.motivo ?? '',
        status: item.stato_code ?? 'reported',
    });
    const expectedDeleteCode = String(item?.id ?? '').trim();
    const deleteCodeMatches = deleteConfirmCode.trim() === expectedDeleteCode;
    const canSubmitDelete = deleteConfirmChecked && deleteCodeMatches && expectedDeleteCode !== '';
    const isReportedDelay = item.stato_code === 'reported';
    const approveActionLabel = isReportedDelay ? 'Giustifica' : 'Approva';
    const rejectActionLabel = isReportedDelay ? 'Registra' : 'Rifiuta';
    const actionStylesForItem = useMemo(() => ({
        ...actionStyles,
        reject: isReportedDelay
            ? 'border-sky-500 bg-sky-500 text-white hover:bg-sky-600'
            : 'border-red-500 bg-red-500 text-white hover:bg-red-600',
    }), [isReportedDelay]);

    useEffect(() => {
        setActiveAction(modalActions.includes(initialAction) ? initialAction : '');
        updateForm.setData('delay_date', item.delay_date ?? item.date ?? '');
        updateForm.setData('delay_minutes', item.minutes ?? 0);
        updateForm.setData('motivation', item.motivation ?? item.motivo ?? '');
        updateForm.setData('status', item.stato_code ?? 'reported');
        approveForm.reset('comment');
        approveWithoutGuardianForm.reset('comment');
        rejectForm.reset('comment');
        extendForm.reset('extension_days', 'comment');
        updateForm.clearErrors();
        approveForm.clearErrors();
        approveWithoutGuardianForm.clearErrors();
        rejectForm.clearErrors();
        extendForm.clearErrors();
        setDeleteConfirmChecked(false);
        setDeleteConfirmCode('');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [item.delay_id, initialAction]);

    useEffect(() => {
        if (!activeAction) {
            return;
        }

        const timer = window.setTimeout(() => {
            const panel = document.getElementById('action-panel');
            if (panel) {
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }, 0);

        return () => window.clearTimeout(timer);
    }, [activeAction]);

    const openAction = (actionKey) => {
        setActiveAction(actionKey);
        if (actionKey === 'delete') {
            setDeleteConfirmChecked(false);
            setDeleteConfirmCode('');
        }
    };

    const closeAction = () => {
        setActiveAction('');
        setDeleteConfirmChecked(false);
        setDeleteConfirmCode('');
    };

    const actions = useMemo(() => {
        const list = [
            { key: 'approve', label: approveActionLabel, enabled: item.can_approve },
            { key: 'approve_without_guardian', label: 'Approva senza firma', enabled: item.can_approve_without_guardian },
            { key: 'reject', label: rejectActionLabel, enabled: item.can_reject },
            { key: 'extend', label: 'Proroga', enabled: item.can_extend_deadline },
            { key: 'edit', label: 'Modifica ritardo', enabled: item.can_edit_delay },
            { key: 'delete', label: 'Elimina definitivamente', enabled: item.can_delete_delay },
        ];

        return list.filter((action) => action.enabled);
    }, [approveActionLabel, item, rejectActionLabel]);
    const hasAvailableActions = actions.length > 0 || item.can_resend_guardian_email;

    const submitApprove = (event) => {
        event.preventDefault();
        approveForm.post(route('teacher.delays.approve', item.delay_id), {
            preserveScroll: true,
            onSuccess: () => {
                approveForm.reset('comment');
                closeAction();
            },
        });
    };

    const submitReject = (event) => {
        event.preventDefault();
        rejectForm.post(route('teacher.delays.reject', item.delay_id), {
            preserveScroll: true,
            onSuccess: () => {
                rejectForm.reset('comment');
                closeAction();
            },
        });
    };

    const submitApproveWithoutGuardian = (event) => {
        event.preventDefault();
        approveWithoutGuardianForm.post(
            route('teacher.delays.approve-without-guardian', item.delay_id),
            {
                preserveScroll: true,
                onSuccess: () => {
                    approveWithoutGuardianForm.reset('comment');
                    closeAction();
                },
            }
        );
    };

    const submitExtend = (event) => {
        event.preventDefault();
        extendForm.post(route('teacher.delays.extend-deadline', item.delay_id), {
            preserveScroll: true,
            onSuccess: () => {
                extendForm.reset('comment');
                closeAction();
            },
        });
    };

    const submitResendGuardianEmail = (event) => {
        event.preventDefault();
        resendGuardianEmailForm.post(
            route('teacher.delays.resend-guardian-email', item.delay_id),
            {
                preserveScroll: true,
            }
        );
    };

    const submitEdit = (event) => {
        event.preventDefault();
        updateForm.post(route('teacher.delays.update', item.delay_id), {
            preserveScroll: true,
            onSuccess: () => {
                closeAction();
            },
        });
    };

    const submitDelete = (event) => {
        event.preventDefault();
        if (!canSubmitDelete) {
            return;
        }

        deleteForm.delete(route('teacher.delays.destroy', item.delay_id), {
            preserveScroll: true,
            onSuccess: closeAction,
        });
    };

    const modalTitle = useMemo(() => {
        const titles = {
            approve: isReportedDelay ? 'Giustifica ritardo' : 'Approva ritardo',
            approve_without_guardian: 'Approva ritardo senza firma',
            reject: isReportedDelay ? 'Registra ritardo' : 'Rifiuta ritardo',
            extend: 'Proroga ritardo arbitrario',
            edit: 'Modifica ritardo',
            delete: 'Elimina ritardo',
        };

        return titles[activeAction] ?? 'Azione';
    }, [activeAction, isReportedDelay]);

    return (
        <AuthenticatedLayout header={`Dettaglio ${item.id}`}>
            <Head title={`Dettaglio ${item.id}`} />

            <div className="space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-slate-400">
                            Ritardo
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
                                Dati ritardo
                            </h2>
                            <dl className="mt-3 space-y-2 text-sm text-slate-600">
                                <div className="flex justify-between gap-3">
                                    <dt>Data</dt>
                                    <dd>{item.data}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Minuti</dt>
                                    <dd>{item.durata}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Scadenza gestione</dt>
                                    <dd>
                                        {item.scadenza === '-'
                                            ? '-'
                                            : `${item.scadenza} (${item.countdown})`}
                                    </dd>
                                </div>
                                {item.firma_tutore_richiesta && (
                                    <div className="flex justify-between gap-3">
                                        <dt>Firma tutore</dt>
                                        <dd>{item.firma_tutore_label ?? 'Assente'}</dd>
                                    </div>
                                )}
                                <div className="flex justify-between gap-3">
                                    <dt>Stato</dt>
                                    <dd>
                                        <span
                                            className={`rounded-full px-3 py-1 text-xs font-semibold ${item.badge}`}
                                        >
                                            {item.stato}
                                        </span>
                                    </dd>
                                </div>
                            </dl>
                            <p className="mt-3 text-sm text-slate-700">
                                <span className="font-semibold">Commento allievo:</span>{' '}
                                {item.motivo || '-'}
                            </p>
                            {item.commento_docente && (
                                <p className="mt-1 text-sm text-slate-700">
                                    <span className="font-semibold">Commento docente:</span>{' '}
                                    {item.commento_docente}
                                </p>
                            )}
                        </div>

                        <div id="azioni" className="rounded-xl border border-slate-200 p-3">
                            <h3 className="text-sm font-semibold text-slate-800">
                                Azioni ritardo
                            </h3>
                            {!hasAvailableActions && (
                                <p className="mt-2 text-sm text-slate-500">
                                    Nessuna azione disponibile su questo ritardo.
                                </p>
                            )}
                            {hasAvailableActions && (
                                <div className="mt-3 flex flex-wrap items-center gap-2.5">
                                    {actions.map((action) => {
                                        const iconOnly = action.key === 'edit' || action.key === 'delete';

                                        return (
                                            <button
                                                key={action.key}
                                                type="button"
                                                title={action.label}
                                                aria-label={action.label}
                                                className={`shrink-0 rounded-md border text-xs font-semibold transition ${actionStylesForItem[action.key]} ${
                                                    iconOnly
                                                        ? 'flex h-8 w-8 items-center justify-center p-0'
                                                        : 'px-3 py-1.5 whitespace-nowrap'
                                                }`}
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
                                    {item.can_resend_guardian_email && (
                                        <form
                                            onSubmit={submitResendGuardianEmail}
                                            className="shrink-0"
                                        >
                                            <button
                                                type="submit"
                                                className="rounded-md border border-indigo-500 bg-indigo-500 px-3 py-1.5 text-xs font-semibold text-white whitespace-nowrap transition hover:bg-indigo-600 disabled:cursor-not-allowed disabled:opacity-70"
                                                disabled={resendGuardianEmailForm.processing}
                                            >
                                                Reinvia email conferma tutore
                                            </button>
                                        </form>
                                    )}
                                </div>
                            )}
                        </div>
                    </section>

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
                                    <p className="font-semibold text-slate-700">
                                        {entry.label}
                                    </p>
                                    <p>{entry.decided_at || '-'}</p>
                                    <p>{entry.decided_by || 'Sistema'}</p>
                                    {entry.notes && <p className="mt-1">{entry.notes}</p>}
                                </div>
                            ))}
                        </div>
                    </section>
                </div>

                <Modal show={Boolean(activeAction)} onClose={closeAction} maxWidth="2xl">
                    <div
                        id="action-panel"
                        className="max-h-[calc(100vh-7rem)] overflow-y-auto p-4 sm:max-h-[80vh] sm:p-5"
                    >
                        <div className="mx-auto w-full max-w-2xl">
                            <h3 className="text-lg font-semibold text-slate-900">
                                {modalTitle}
                            </h3>

                            {activeAction === 'approve' && (
                                <form onSubmit={submitApprove} className="mt-4 space-y-3">
                                    <label className="block text-xs font-semibold text-slate-700">
                                        Commento (facoltativo)
                                    </label>
                                    <textarea
                                        rows={4}
                                        className={inputClass}
                                        value={approveForm.data.comment}
                                        onChange={(event) =>
                                            approveForm.setData('comment', event.target.value)
                                        }
                                    />
                                    {approveForm.errors.delay && (
                                        <p className="text-xs text-rose-600">
                                            {approveForm.errors.delay}
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

                            {activeAction === 'approve_without_guardian' && (
                                <form onSubmit={submitApproveWithoutGuardian} className="mt-4 space-y-3">
                                    <label className="block text-xs font-semibold text-slate-700">
                                        Commento obbligatorio
                                    </label>
                                    <textarea
                                        rows={4}
                                        className={inputClass}
                                        value={approveWithoutGuardianForm.data.comment}
                                        onChange={(event) =>
                                            approveWithoutGuardianForm.setData('comment', event.target.value)
                                        }
                                    />
                                    {(approveWithoutGuardianForm.errors.delay || approveWithoutGuardianForm.errors.comment) && (
                                        <p className="text-xs text-rose-600">
                                            {approveWithoutGuardianForm.errors.delay || approveWithoutGuardianForm.errors.comment}
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
                                            className="rounded-lg bg-green-500 px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:bg-green-300"
                                            disabled={approveWithoutGuardianForm.processing}
                                        >
                                            Salva
                                        </button>
                                    </div>
                                </form>
                            )}

                            {activeAction === 'reject' && (
                                <form onSubmit={submitReject} className="mt-4 space-y-3">
                                    <label className="block text-xs font-semibold text-slate-700">
                                        {isReportedDelay ? 'Commento (facoltativo)' : 'Commento obbligatorio'}
                                    </label>
                                    <textarea
                                        rows={4}
                                        className={inputClass}
                                        value={rejectForm.data.comment}
                                        onChange={(event) =>
                                            rejectForm.setData('comment', event.target.value)
                                        }
                                    />
                                    {rejectForm.errors.delay && (
                                        <p className="text-xs text-rose-600">
                                            {rejectForm.errors.delay}
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
                                            className={`rounded-lg px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed ${
                                                isReportedDelay
                                                    ? 'bg-sky-500 disabled:bg-sky-300'
                                                    : 'bg-red-500 disabled:bg-red-300'
                                            }`}
                                            disabled={rejectForm.processing}
                                        >
                                            Salva
                                        </button>
                                    </div>
                                </form>
                            )}

                            {activeAction === 'extend' && (
                                <form onSubmit={submitExtend} className="mt-4 space-y-3">
                                    <label className="block text-xs font-semibold text-slate-700">
                                        Giorni lavorativi di proroga
                                    </label>
                                    <input
                                        type="number"
                                        min="1"
                                        max="30"
                                        className={inputClass}
                                        value={extendForm.data.extension_days}
                                        onChange={(event) =>
                                            extendForm.setData(
                                                'extension_days',
                                                Number(event.target.value || 0)
                                            )
                                        }
                                    />
                                    <label className="block text-xs font-semibold text-slate-700">
                                        Commento obbligatorio
                                    </label>
                                    <textarea
                                        rows={3}
                                        className={inputClass}
                                        value={extendForm.data.comment}
                                        onChange={(event) =>
                                            extendForm.setData('comment', event.target.value)
                                        }
                                    />
                                    {(extendForm.errors.delay || extendForm.errors.comment || extendForm.errors.extension_days) && (
                                        <p className="text-xs text-rose-600">
                                            {extendForm.errors.delay || extendForm.errors.comment || extendForm.errors.extension_days}
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
                                            className="rounded-lg bg-amber-400 px-4 py-2 text-xs font-semibold text-slate-900 disabled:cursor-not-allowed disabled:bg-amber-200"
                                            disabled={extendForm.processing}
                                        >
                                            Salva
                                        </button>
                                    </div>
                                </form>
                            )}

                            {activeAction === 'delete' && (
                                <form onSubmit={submitDelete} className="mt-4 space-y-3">
                                    <p className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                                        Questa operazione elimina definitivamente il ritardo.
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

                            {activeAction === 'edit' && (
                                <form onSubmit={submitEdit} className="mt-4 space-y-3">
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-700">
                                                Data ritardo
                                            </label>
                                            <input
                                                type="date"
                                                className={`${inputClass} mt-1`}
                                                value={updateForm.data.delay_date}
                                                max={today}
                                                onChange={(event) =>
                                                    updateForm.setData(
                                                        'delay_date',
                                                        event.target.value
                                                    )
                                                }
                                            />
                                            {updateForm.errors.delay_date && (
                                                <p className="mt-1 text-xs text-rose-600">
                                                    {updateForm.errors.delay_date}
                                                </p>
                                            )}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-700">
                                                Minuti ritardo
                                            </label>
                                            <input
                                                type="number"
                                                min="1"
                                                max="480"
                                                step="1"
                                                className={`${inputClass} mt-1`}
                                                value={updateForm.data.delay_minutes}
                                                onChange={(event) =>
                                                    updateForm.setData(
                                                        'delay_minutes',
                                                        event.target.value
                                                    )
                                                }
                                            />
                                            {updateForm.errors.delay_minutes && (
                                                <p className="mt-1 text-xs text-rose-600">
                                                    {updateForm.errors.delay_minutes}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700">
                                            Stato
                                        </label>
                                        <select
                                            className={`${inputClass} mt-1`}
                                            value={updateForm.data.status}
                                            onChange={(event) =>
                                                updateForm.setData('status', event.target.value)
                                            }
                                        >
                                            <option value="reported">Segnalato</option>
                                            <option value="justified">Giustificato</option>
                                            <option value="registered">Registrato</option>
                                        </select>
                                        {updateForm.errors.status && (
                                            <p className="mt-1 text-xs text-rose-600">
                                                {updateForm.errors.status}
                                            </p>
                                        )}
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700">
                                            Commento allievo
                                        </label>
                                        <textarea
                                            rows={3}
                                            className={`${inputClass} mt-1`}
                                            value={updateForm.data.motivation}
                                            onChange={(event) =>
                                                updateForm.setData(
                                                    'motivation',
                                                    event.target.value
                                                )
                                            }
                                        />
                                        {updateForm.errors.motivation && (
                                            <p className="mt-1 text-xs text-rose-600">
                                                {updateForm.errors.motivation}
                                            </p>
                                        )}
                                    </div>
                                    {updateForm.errors.delay && (
                                        <p className="text-xs text-rose-600">
                                            {updateForm.errors.delay}
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
                                            disabled={updateForm.processing}
                                        >
                                            Salva
                                        </button>
                                    </div>
                                </form>
                            )}
                        </div>
                    </div>
                </Modal>
            </div>
        </AuthenticatedLayout>
    );
}
