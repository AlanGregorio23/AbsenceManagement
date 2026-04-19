import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const inputClass =
    'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-100';

const actionStyles = {
    approve: 'border-lime-500 bg-lime-500 text-white hover:bg-lime-600',
    approve_without_guardian:
        'border-green-500 bg-green-500 text-white hover:bg-green-600',
    reject: 'border-red-500 bg-red-500 text-white hover:bg-red-600',
    delete: 'border-rose-600 bg-rose-600 text-white hover:bg-rose-700',
    extend: 'border-amber-400 bg-amber-400 text-slate-900 hover:bg-amber-500',
    accept_certificate: 'border-sky-500 bg-sky-500 text-white hover:bg-sky-600',
    reject_certificate: 'border-red-500 bg-red-500 text-white hover:bg-red-600',
    edit: 'border-slate-600 bg-slate-600 text-white hover:bg-slate-700',
};

const resolveExclusionComment = (counts40Hours, comment) => {
    if (counts40Hours) {
        return '';
    }

    const trimmed = String(comment ?? '').trim();
    return trimmed !== '' ? trimmed : 'Esclusa dalle 40 ore da regola motivo.';
};

const statusLabels = {
    reported: 'Segnalata',
    justified: 'Giustificata',
    arbitrary: 'Arbitraria',
};

const yesNoLabel = (value) => (value ? 'Si' : 'No');

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

export default function TeacherAbsenceDetail({
    item,
    initialAction = '',
    reasons = [],
    history = [],
}) {
    const modalActions = [
        'approve',
        'approve_without_guardian',
        'reject',
        'delete',
        'extend',
        'edit',
        'accept_certificate',
        'reject_certificate',
    ];

    const initialCounts40Hours = Boolean(item?.conteggio_40_ore);
    const initialCounts40Comment = resolveExclusionComment(
        initialCounts40Hours,
        item?.counts_40_hours_comment ?? ''
    );

    const [activeAction, setActiveAction] = useState(
        modalActions.includes(initialAction) ? initialAction : ''
    );
    const [counts40Hours, setCounts40Hours] = useState(initialCounts40Hours);
    const [counts40Comment, setCounts40Comment] = useState(initialCounts40Comment);
    const [editConfirmStep, setEditConfirmStep] = useState('form');
    const [deleteConfirmChecked, setDeleteConfirmChecked] = useState(false);
    const [deleteConfirmCode, setDeleteConfirmCode] = useState('');

    const approveForm = useForm({ comment: '' });
    const approveWithoutForm = useForm({
        comment: '',
        counts_40_hours: initialCounts40Hours,
        counts_40_hours_comment: initialCounts40Comment,
    });
    const rejectForm = useForm({ comment: '' });
    const deleteForm = useForm({});
    const extendForm = useForm({ extension_days: 2, comment: '' });
    const resendGuardianEmailForm = useForm({});
    const updateForm = useForm({
        start_date: item.start_date ?? '',
        end_date: item.end_date ?? '',
        hours: item.hours ?? 0,
        motivation: item.motivation ?? '',
        status: item.stato_code ?? 'reported',
        counts_40_hours: initialCounts40Hours,
        counts_40_hours_comment: initialCounts40Comment,
        comment: '',
    });
    const acceptForm = useForm({});
    const rejectCertificateForm = useForm({ comment: '' });

    const previewUrl = item?.certificate?.viewer_url
        ?? (item?.absence_id
            ? route('teacher.absences.certificate.view', item.absence_id)
            : null);
    const downloadUrl = item?.certificate?.download_url
        ?? (item?.absence_id
            ? route('teacher.absences.certificate.download', item.absence_id)
            : null);
    const guardianSignaturePreviewUrl = item?.guardian_signature?.viewer_url ?? null;
    const expectedDeleteCode = String(item?.id ?? '').trim();
    const deleteCodeMatches = deleteConfirmCode.trim() === expectedDeleteCode;
    const canSubmitDelete = deleteConfirmChecked && deleteCodeMatches && expectedDeleteCode !== '';

    const actions = useMemo(() => {
        const list = [
            { key: 'approve', label: 'Approva', enabled: item.can_approve },
            {
                key: 'approve_without_guardian',
                label: 'Approva senza firma',
                enabled: item.can_approve_without_guardian,
            },
            { key: 'reject', label: 'Rifiuta', enabled: item.can_reject },
            { key: 'extend', label: 'Proroga', enabled: item.can_extend_deadline },
            { key: 'edit', label: 'Modifica assenza', enabled: item.can_edit_absence },
            {
                key: 'delete',
                label: 'Elimina definitivamente',
                enabled: item.can_delete_absence,
            },
        ];

        return list.filter((action) => action.enabled);
    }, [item]);

    const certificateActions = useMemo(() => {
        const list = [
            {
                key: 'accept_certificate',
                label: 'Accetta certificato',
                enabled: !item.from_leave && item.can_accept_certificate,
            },
            {
                key: 'reject_certificate',
                label: 'Rifiuta certificato',
                enabled: !item.from_leave && item.can_reject_certificate,
            },
        ];

        return list.filter((action) => action.enabled);
    }, [item]);

    const reasonOptions = useMemo(() => {
        if (!Array.isArray(reasons)) {
            return [];
        }

        return reasons
            .map((reason) => ({
                id: reason?.id ?? reason?.name ?? '',
                name: String(reason?.name ?? '').trim(),
            }))
            .filter((reason) => reason.name !== '');
    }, [reasons]);

    const editReasonOptions = useMemo(() => {
        const currentMotivation = String(updateForm.data.motivation ?? '').trim();
        if (currentMotivation === '') {
            return reasonOptions;
        }

        const alreadyListed = reasonOptions.some(
            (reason) => reason.name === currentMotivation
        );
        if (alreadyListed) {
            return reasonOptions;
        }

        return [
            {
                id: '__current_reason__',
                name: currentMotivation,
            },
            ...reasonOptions,
        ];
    }, [reasonOptions, updateForm.data.motivation]);

    const editChanges = useMemo(() => {
        const changes = [];
        const pushChange = (label, before, after) => {
            const beforeText = String(before ?? '').trim();
            const afterText = String(after ?? '').trim();

            if (beforeText === afterText) {
                return;
            }

            changes.push({
                label,
                before: beforeText !== '' ? beforeText : '-',
                after: afterText !== '' ? afterText : '-',
            });
        };

        pushChange('Data inizio', item.start_date ?? '', updateForm.data.start_date ?? '');
        pushChange('Data fine', item.end_date ?? '', updateForm.data.end_date ?? '');
        pushChange('Ore', item.hours ?? '', updateForm.data.hours ?? '');
        pushChange(
            'Stato',
            statusLabels[String(item.stato_code ?? 'reported')] ?? String(item.stato_code ?? '-'),
            statusLabels[String(updateForm.data.status ?? 'reported')]
                ?? String(updateForm.data.status ?? '-')
        );
        pushChange('Motivazione', item.motivation ?? '', updateForm.data.motivation ?? '');
        pushChange(
            'Rientra nelle 40 ore',
            yesNoLabel(Boolean(item.conteggio_40_ore)),
            yesNoLabel(Boolean(updateForm.data.counts_40_hours))
        );

        const beforeCountsComment = Boolean(item.conteggio_40_ore)
            ? '-'
            : resolveExclusionComment(false, item.counts_40_hours_comment ?? '');
        const afterCountsComment = Boolean(updateForm.data.counts_40_hours)
            ? '-'
            : resolveExclusionComment(false, updateForm.data.counts_40_hours_comment);
        pushChange('Motivo esclusione 40 ore', beforeCountsComment, afterCountsComment);
        pushChange('Commento docente', item.commento_docente ?? '', updateForm.data.comment ?? '');

        return changes;
    }, [item, updateForm.data]);

    useEffect(() => {
        const nextCounts40Hours = Boolean(item?.conteggio_40_ore);
        const nextCounts40Comment = resolveExclusionComment(
            nextCounts40Hours,
            item?.counts_40_hours_comment ?? ''
        );

        setCounts40Hours(nextCounts40Hours);
        setCounts40Comment(nextCounts40Comment);
        approveWithoutForm.setData('counts_40_hours', nextCounts40Hours);
        approveWithoutForm.setData('counts_40_hours_comment', nextCounts40Comment);
        updateForm.setData('start_date', item.start_date ?? '');
        updateForm.setData('end_date', item.end_date ?? '');
        updateForm.setData('hours', item.hours ?? 0);
        updateForm.setData('motivation', item.motivation ?? '');
        updateForm.setData('status', item.stato_code ?? 'reported');
        updateForm.setData('counts_40_hours', nextCounts40Hours);
        updateForm.setData('counts_40_hours_comment', nextCounts40Comment);
        setActiveAction(modalActions.includes(initialAction) ? initialAction : '');
        setEditConfirmStep('form');
        setDeleteConfirmChecked(false);
        setDeleteConfirmCode('');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [item.absence_id, initialAction]);

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
        if (actionKey === 'edit') {
            setEditConfirmStep('form');
        }
        if (actionKey === 'delete') {
            setDeleteConfirmChecked(false);
            setDeleteConfirmCode('');
        }
    };

    const closeAction = () => {
        setActiveAction('');
        setEditConfirmStep('form');
        setDeleteConfirmChecked(false);
        setDeleteConfirmCode('');
    };

    const submitApprove = (event) => {
        event.preventDefault();
        approveForm.post(route('teacher.absences.approve', item.absence_id), {
            preserveScroll: true,
            onSuccess: () => {
                approveForm.reset('comment');
                closeAction();
            },
        });
    };

    const submitApproveWithoutGuardian = (event) => {
        event.preventDefault();
        approveWithoutForm.post(
            route('teacher.absences.approve-without-guardian', item.absence_id),
            {
                preserveScroll: true,
                onSuccess: () => {
                    approveWithoutForm.reset('comment');
                    closeAction();
                },
            }
        );
    };

    const submitReject = (event) => {
        event.preventDefault();
        rejectForm.post(route('teacher.absences.reject', item.absence_id), {
            preserveScroll: true,
            onSuccess: () => {
                rejectForm.reset('comment');
                closeAction();
            },
        });
    };

    const submitDelete = (event) => {
        event.preventDefault();
        if (!canSubmitDelete) {
            return;
        }

        deleteForm.delete(route('teacher.absences.destroy', item.absence_id), {
            preserveScroll: true,
            onSuccess: closeAction,
        });
    };

    const submitExtend = (event) => {
        event.preventDefault();
        extendForm.post(
            route('teacher.absences.extend-deadline', item.absence_id),
            {
                preserveScroll: true,
                onSuccess: () => {
                    extendForm.reset('comment');
                    closeAction();
                },
            }
        );
    };

    const submitResendGuardianEmail = (event) => {
        event.preventDefault();
        resendGuardianEmailForm.post(
            route('teacher.absences.resend-guardian-email', item.absence_id),
            {
                preserveScroll: true,
            }
        );
    };

    const submitEdit = (event) => {
        event.preventDefault();

        if (editConfirmStep === 'form') {
            setEditConfirmStep('confirm');
            return;
        }

        updateForm.post(route('teacher.absences.update', item.absence_id), {
            preserveScroll: true,
            onError: () => {
                setEditConfirmStep('form');
            },
            onSuccess: () => {
                const nextCounts40Hours = Boolean(updateForm.data.counts_40_hours);
                const nextCounts40Comment = resolveExclusionComment(
                    nextCounts40Hours,
                    updateForm.data.counts_40_hours_comment
                );

                setCounts40Hours(nextCounts40Hours);
                setCounts40Comment(nextCounts40Comment);
                approveWithoutForm.setData('counts_40_hours', nextCounts40Hours);
                approveWithoutForm.setData(
                    'counts_40_hours_comment',
                    nextCounts40Comment
                );
                updateForm.reset('comment');
                setEditConfirmStep('form');
                closeAction();
            },
        });
    };

    const submitAcceptCertificate = (event) => {
        event.preventDefault();
        acceptForm.post(
            route('teacher.absences.accept-certificate', item.absence_id),
            {
                preserveScroll: true,
                onSuccess: closeAction,
            }
        );
    };

    const submitRejectCertificate = (event) => {
        event.preventDefault();
        rejectCertificateForm.post(
            route('teacher.absences.reject-certificate', item.absence_id),
            {
                preserveScroll: true,
                onSuccess: () => {
                    rejectCertificateForm.reset('comment');
                    closeAction();
                },
            }
        );
    };

    const modalTitle = useMemo(() => {
        const titles = {
            approve: 'Approva con firma tutore',
            approve_without_guardian: 'Approva senza firma',
            reject: 'Rifiuta assenza',
            delete: 'Elimina assenza',
            extend: 'Proroga assenza arbitraria',
            edit: 'Modifica assenza',
            accept_certificate: 'Accetta certificato',
            reject_certificate: 'Rifiuta certificato',
        };

        return titles[activeAction] ?? 'Azione';
    }, [activeAction]);

    const resolvedHours = Math.max(Number(item?.hours ?? 0), 0);
    const durataLabel =
        item?.durata ??
        (resolvedHours === 1 ? '1 ora' : `${resolvedHours} ore`);
    const today = new Date().toISOString().slice(0, 10);

    return (
        <AuthenticatedLayout>
            <Head title={`Dettaglio ${item.id}`} />
            <div className="space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-slate-400">
                            Assenza
                        </p>
                        <h1 className="text-xl font-semibold text-slate-900">
                            {item.id} - {item.studente}
                        </h1>
                        <p className="text-sm text-slate-500">
                            Classe {item.classe} - {item.stato}
                        </p>
                        {item.from_leave && item.derived_leave_code && (
                            <p className="mt-1 text-xs font-semibold text-indigo-700">
                                Assenza derivata da congedo {item.derived_leave_code}
                            </p>
                        )}
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div>
                            <h2 className="text-sm font-semibold text-slate-800">
                                Dati assenza
                            </h2>
                            <dl className="mt-3 space-y-2 text-sm text-slate-600">
                                <div className="flex justify-between gap-3">
                                    <dt>Data</dt>
                                    <dd>{item.data}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Durata</dt>
                                    <dd>{durataLabel}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Scadenza</dt>
                                    <dd>
                                        {item.scadenza} ({item.countdown})
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Firma tutore</dt>
                                    <dd>
                                        {item.firma_tutore_label ??
                                            (item.firma_tutore_presente
                                                ? item.firma_tutore_data
                                                    ? `Presente (${item.firma_tutore_data})`
                                                    : 'Presente'
                                                : 'Assente')}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>40 ore</dt>
                                    <dd>
                                        {counts40Hours
                                            ? 'Rientra nelle 40 ore'
                                            : 'Esclusa dalle 40 ore'}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Obbligo certificato</dt>
                                    <dd>
                                        <span
                                            className={`rounded-full px-3 py-1 text-xs font-semibold ${item.certificato_obbligo_badge ?? 'bg-slate-100 text-slate-700'}`}
                                        >
                                            {item.certificato_obbligo ??
                                                'Certificato non obbligatorio'}
                                        </span>
                                    </dd>
                                </div>
                                {item.from_leave && item.derived_leave_code && (
                                    <div className="flex justify-between gap-3">
                                        <dt>Origine</dt>
                                        <dd>
                                            {item.derived_leave_url ? (
                                                <a
                                                    href={item.derived_leave_url}
                                                    className="font-semibold text-indigo-700 underline"
                                                >
                                                    Congedo {item.derived_leave_code}
                                                </a>
                                            ) : (
                                                `Congedo ${item.derived_leave_code}`
                                            )}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                            <p className="mt-3 text-sm text-slate-700">
                                <span className="font-semibold">Motivo:</span>{' '}
                                {item.motivo}
                            </p>
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
                                        <p className="sm:col-span-2">
                                            <span className="font-semibold text-slate-700">
                                                Origine firma:
                                            </span>{' '}
                                            {item.guardian_signature.source_label || 'Firma richiesta assenza'}
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

                        <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <h3 className="text-sm font-semibold text-slate-800">
                                Gestione 40 ore
                            </h3>
                            <p className="mt-2 text-sm text-slate-700">
                                {counts40Hours
                                    ? 'Rientra nelle 40 ore'
                                    : 'Esclusa dalle 40 ore'}
                            </p>
                            <p className="mt-1 text-xs text-slate-500">
                                Modifica il valore dal form "Modifica assenza".
                            </p>
                            {!counts40Hours && (
                                <p className="mt-2 text-xs text-slate-500">
                                    Motivo esclusione: {counts40Comment}
                                </p>
                            )}
                        </div>

                        <div id="azioni" className="rounded-xl border border-slate-200 p-3">
                            <h3 className="text-sm font-semibold text-slate-800">
                                Azioni assenza
                            </h3>
                            {actions.length === 0 && (
                                <p className="mt-2 text-sm text-slate-500">
                                    Nessuna azione disponibile su questa assenza.
                                </p>
                            )}
                            <div className="mt-3 flex flex-nowrap items-center gap-2 overflow-x-auto whitespace-nowrap pb-1">
                                {actions.map((action) => {
                                    const iconOnly = action.key === 'edit' || action.key === 'delete';

                                    return (
                                        <button
                                            key={action.key}
                                            type="button"
                                            title={action.label}
                                            aria-label={action.label}
                                            className={`shrink-0 rounded-md border text-xs font-semibold transition ${actionStyles[action.key]} ${
                                                iconOnly
                                                    ? 'flex h-7 w-7 items-center justify-center p-0'
                                                    : 'px-3 py-1 whitespace-nowrap'
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
                                            className="rounded-md border border-indigo-500 bg-indigo-500 px-3 py-1 text-xs font-semibold text-white whitespace-nowrap transition hover:bg-indigo-600 disabled:cursor-not-allowed disabled:opacity-70"
                                            disabled={resendGuardianEmailForm.processing}
                                        >
                                            Reinvia email conferma tutore
                                        </button>
                                    </form>
                                )}
                            </div>
                        </div>
                    </section>

                    <div className="space-y-4">
                        <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="text-lg font-semibold text-slate-900">
                                    Certificato medico
                                </h2>
                                {item.certificato_caricato && (
                                    <div className="flex gap-2">
                                        <a
                                            href={previewUrl}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="btn-soft-neutral h-8"
                                        >
                                            Apri
                                        </a>
                                        <a
                                            href={downloadUrl}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="btn-soft-info h-8"
                                        >
                                            Scarica
                                        </a>
                                    </div>
                                )}
                            </div>

                            {!item.certificato_caricato && (
                                <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-12 text-center text-sm text-slate-500">
                                    Nessun certificato caricato.
                                </div>
                            )}
                            {item.certificato_caricato && (
                                <>
                                    <div className="grid gap-2 text-xs text-slate-500 sm:grid-cols-4">
                                        <p>
                                            <span className="font-semibold text-slate-700">
                                                File:
                                            </span>{' '}
                                            {item.certificate?.filename ?? '-'}
                                        </p>
                                        <p>
                                            <span className="font-semibold text-slate-700">
                                                Caricato:
                                            </span>{' '}
                                            {item.certificate?.uploaded_at ?? '-'}
                                        </p>
                                        <p>
                                            <span className="font-semibold text-slate-700">
                                                Stato:
                                            </span>{' '}
                                            {item.certificato_validato
                                                ? 'Validato'
                                                : 'In revisione'}
                                        </p>
                                        <p>
                                            <span className="font-semibold text-slate-700">
                                                Origine:
                                            </span>{' '}
                                            {item.certificate?.source_label ?? 'Certificato assenza'}
                                        </p>
                                    </div>

                                    <div className="overflow-hidden rounded-xl border border-slate-200">
                                        <iframe
                                            title={`Preview certificato ${item.id}`}
                                            src={previewUrl}
                                            className="h-[420px] w-full bg-slate-50"
                                        />
                                    </div>

                                    <div className="rounded-xl border border-slate-200 p-3">
                                        <h3 className="text-sm font-semibold text-slate-800">
                                            Azioni certificato
                                        </h3>
                                        {certificateActions.length === 0 && (
                                            <p className="mt-2 text-sm text-slate-500">
                                                Nessuna azione disponibile sul certificato.
                                            </p>
                                        )}
                                        {certificateActions.length > 0 && (
                                            <div className="mt-3 flex flex-nowrap items-center gap-2 overflow-x-auto whitespace-nowrap pb-1">
                                                {certificateActions.map((action) => (
                                                    <button
                                                        key={action.key}
                                                        type="button"
                                                        className={`shrink-0 rounded-md border px-3 py-1 text-xs font-semibold whitespace-nowrap transition ${actionStyles[action.key]}`}
                                                        onClick={() =>
                                                            openAction(action.key)
                                                        }
                                                    >
                                                        {action.label}
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                </>
                            )}
                        </section>
                    </div>
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
                                <form
                                    onSubmit={submitApproveWithoutGuardian}
                                    className="mt-4 space-y-3"
                                >
                                    <p className="text-xs text-slate-500">
                                        40 ore:{' '}
                                        {counts40Hours
                                            ? 'Rientra nelle 40 ore'
                                            : 'Esclusa dalle 40 ore'}
                                    </p>
                                    {!counts40Hours && (
                                        <p className="text-xs text-slate-500">
                                            Motivo esclusione: {counts40Comment}
                                        </p>
                                    )}
                                    <label className="block text-xs font-semibold text-slate-700">
                                        Commento obbligatorio
                                    </label>
                                    <textarea
                                        rows={4}
                                        className={inputClass}
                                        value={approveWithoutForm.data.comment}
                                        onChange={(event) =>
                                            approveWithoutForm.setData(
                                                'comment',
                                                event.target.value
                                            )
                                        }
                                    />
                                    {(approveWithoutForm.errors.comment ||
                                        approveWithoutForm.errors
                                            .counts_40_hours_comment) && (
                                        <p className="text-xs text-rose-600">
                                            {approveWithoutForm.errors.comment ||
                                                approveWithoutForm.errors
                                                    .counts_40_hours_comment}
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
                                            disabled={approveWithoutForm.processing}
                                        >
                                            Salva
                                        </button>
                                    </div>
                                </form>
                            )}

                            {activeAction === 'reject' && (
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
                                        <p className="text-xs text-rose-600">
                                            {rejectForm.errors.comment}
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
                                            className="rounded-lg bg-red-500 px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:bg-red-300"
                                            disabled={rejectForm.processing}
                                        >
                                            Salva
                                        </button>
                                    </div>
                                </form>
                            )}

                            {activeAction === 'delete' && (
                                <form onSubmit={submitDelete} className="mt-4 space-y-3">
                                    <p className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                                        Questa operazione elimina definitivamente assenza, firme e certificati collegati.
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
                            {activeAction === 'extend' && (
                                <form onSubmit={submitExtend} className="mt-4 space-y-3">
                                    <label className="block text-xs font-semibold text-slate-700">
                                        Giorni lavorativi
                                    </label>
                                    <input
                                        type="number"
                                        min="1"
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
                                    {(extendForm.errors.comment ||
                                        extendForm.errors.extension_days) && (
                                        <p className="text-xs text-rose-600">
                                            {extendForm.errors.comment ||
                                                extendForm.errors.extension_days}
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

                            {activeAction === 'edit' && (
                                <>
                                    {editConfirmStep === 'form' && (
                                        <form onSubmit={submitEdit} className="mt-4 space-y-3">
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                <div>
                                                    <label className="block text-xs font-semibold text-slate-700">
                                                        Data inizio
                                                    </label>
                                                    <input
                                                        type="date"
                                                        className={`${inputClass} mt-1`}
                                                        value={updateForm.data.start_date}
                                                        max={today}
                                                        onChange={(event) =>
                                                            updateForm.setData(
                                                                'start_date',
                                                                event.target.value
                                                            )
                                                        }
                                                    />
                                                </div>
                                                <div>
                                                    <label className="block text-xs font-semibold text-slate-700">
                                                        Data fine
                                                    </label>
                                                    <input
                                                        type="date"
                                                        className={`${inputClass} mt-1`}
                                                        value={updateForm.data.end_date}
                                                        max={today}
                                                        onChange={(event) =>
                                                            updateForm.setData(
                                                                'end_date',
                                                                event.target.value
                                                            )
                                                        }
                                                    />
                                                </div>
                                            </div>
                                            <label className="block text-xs font-semibold text-slate-700">
                                                Ore
                                            </label>
                                            <input
                                                type="number"
                                                min="1"
                                                className={inputClass}
                                                value={updateForm.data.hours}
                                                onChange={(event) =>
                                                    updateForm.setData(
                                                        'hours',
                                                        Number(event.target.value || 1)
                                                    )
                                                }
                                            />
                                            <label className="block text-xs font-semibold text-slate-700">
                                                Stato
                                            </label>
                                            <select
                                                className={inputClass}
                                                value={updateForm.data.status}
                                                onChange={(event) => {
                                                    const nextStatus = event.target.value;
                                                    updateForm.setData('status', nextStatus);

                                                    if (nextStatus === 'arbitrary') {
                                                        updateForm.setData('counts_40_hours', true);
                                                    }
                                                }}
                                            >
                                                <option value="reported">Segnalata</option>
                                                <option value="justified">Giustificata</option>
                                                <option value="arbitrary">Arbitraria</option>
                                            </select>
                                            <label className="block text-xs font-semibold text-slate-700">
                                                Motivazione
                                            </label>
                                            {reasonOptions.length > 0 ? (
                                                <select
                                                    className={inputClass}
                                                    value={updateForm.data.motivation}
                                                    onChange={(event) =>
                                                        updateForm.setData(
                                                            'motivation',
                                                            event.target.value
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        Seleziona una motivazione
                                                    </option>
                                                    {editReasonOptions.map((reason) => (
                                                        <option
                                                            key={`${reason.id}-${reason.name}`}
                                                            value={reason.name}
                                                        >
                                                            {reason.name}
                                                        </option>
                                                    ))}
                                                </select>
                                            ) : (
                                                <textarea
                                                    rows={2}
                                                    className={inputClass}
                                                    value={updateForm.data.motivation}
                                                    onChange={(event) =>
                                                        updateForm.setData(
                                                            'motivation',
                                                            event.target.value
                                                        )
                                                    }
                                                />
                                            )}
                                            <label className="flex items-center gap-2 text-xs text-slate-700">
                                                <input
                                                    type="checkbox"
                                                    checked={Boolean(updateForm.data.counts_40_hours)}
                                                    disabled={updateForm.data.status === 'arbitrary'}
                                                    onChange={(event) =>
                                                        updateForm.setData(
                                                            'counts_40_hours',
                                                            event.target.checked
                                                        )
                                                    }
                                                />
                                                Rientra nelle 40 ore
                                            </label>
                                            {!updateForm.data.counts_40_hours && (
                                                <>
                                                    <label className="block text-xs font-semibold text-slate-700">
                                                        Motivo esclusione 40 ore
                                                    </label>
                                                    <textarea
                                                        rows={2}
                                                        className={inputClass}
                                                        value={updateForm.data.counts_40_hours_comment}
                                                        onChange={(event) =>
                                                            updateForm.setData(
                                                                'counts_40_hours_comment',
                                                                event.target.value
                                                            )
                                                        }
                                                    />
                                                </>
                                            )}
                                            <label className="block text-xs font-semibold text-slate-700">
                                                Commento obbligatorio
                                            </label>
                                            <textarea
                                                rows={3}
                                                className={inputClass}
                                                value={updateForm.data.comment}
                                                onChange={(event) =>
                                                    updateForm.setData('comment', event.target.value)
                                                }
                                            />
                                            {(updateForm.errors.comment ||
                                                updateForm.errors.start_date ||
                                                updateForm.errors.end_date ||
                                                updateForm.errors.hours ||
                                                updateForm.errors.status ||
                                                updateForm.errors.motivation ||
                                                updateForm.errors.counts_40_hours ||
                                                updateForm.errors.counts_40_hours_comment) && (
                                                <p className="text-xs text-rose-600">
                                                    {updateForm.errors.comment ||
                                                        updateForm.errors.start_date ||
                                                        updateForm.errors.end_date ||
                                                        updateForm.errors.hours ||
                                                        updateForm.errors.status ||
                                                        updateForm.errors.motivation ||
                                                        updateForm.errors.counts_40_hours ||
                                                        updateForm.errors.counts_40_hours_comment}
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
                                                    Continua
                                                </button>
                                            </div>
                                        </form>
                                    )}

                                    {editConfirmStep === 'confirm' && (
                                        <form onSubmit={submitEdit} className="mt-4 space-y-3">
                                            <p className="text-sm text-slate-700">
                                                Conferma modifiche: verifica il riepilogo prima di salvare.
                                            </p>

                                            {editChanges.length === 0 ? (
                                                <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">
                                                    Nessuna modifica rilevata rispetto ai valori attuali.
                                                </div>
                                            ) : (
                                                <div className="space-y-2">
                                                    {editChanges.map((change) => (
                                                        <div
                                                            key={change.label}
                                                            className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs"
                                                        >
                                                            <p className="font-semibold text-slate-800">
                                                                {change.label}
                                                            </p>
                                                            <p className="text-slate-500">
                                                                Prima: {change.before}
                                                            </p>
                                                            <p className="text-slate-700">
                                                                Dopo: {change.after}
                                                            </p>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}

                                            <div className="flex justify-end gap-2">
                                                <button
                                                    type="button"
                                                    className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700"
                                                    onClick={() => setEditConfirmStep('form')}
                                                >
                                                    Indietro
                                                </button>
                                                <button
                                                    type="submit"
                                                    className="rounded-lg bg-slate-700 px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-400"
                                                    disabled={updateForm.processing || editChanges.length === 0}
                                                >
                                                    Conferma modifica
                                                </button>
                                            </div>
                                        </form>
                                    )}
                                </>
                            )}

                            {activeAction === 'accept_certificate' && (
                                <form
                                    onSubmit={submitAcceptCertificate}
                                    className="mt-4 space-y-3"
                                >
                                    <p className="text-sm text-slate-600">
                                        Confermi la validazione del certificato?
                                    </p>
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
                                            disabled={acceptForm.processing}
                                        >
                                            Salva
                                        </button>
                                    </div>
                                </form>
                            )}

                            {activeAction === 'reject_certificate' && (
                                <form
                                    onSubmit={submitRejectCertificate}
                                    className="mt-4 space-y-3"
                                >
                                    <label className="block text-xs font-semibold text-slate-700">
                                        Motivo rifiuto certificato
                                    </label>
                                    <textarea
                                        rows={4}
                                        className={inputClass}
                                        value={rejectCertificateForm.data.comment}
                                        onChange={(event) =>
                                            rejectCertificateForm.setData(
                                                'comment',
                                                event.target.value
                                            )
                                        }
                                    />
                                    {rejectCertificateForm.errors.comment && (
                                        <p className="text-xs text-rose-600">
                                            {rejectCertificateForm.errors.comment}
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
                                            className="rounded-lg bg-red-500 px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:bg-red-300"
                                            disabled={rejectCertificateForm.processing}
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
