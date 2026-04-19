import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

const inputClass =
    'rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-100';

const toSafeArray = (value) => (Array.isArray(value) ? value : []);

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

export default function Users({
    utenti = [],
    pagination = null,
    filters = {},
    roleOptions = [],
    classOptions = [],
}) {
    const { auth } = usePage().props;
    const authUserId = auth?.user?.id ?? null;
    const [query, setQuery] = useState(filters.query ?? '');
    const [roleFilter, setRoleFilter] = useState(filters.role ?? '');
    const [classFilter, setClassFilter] = useState(filters.class ?? '');
    const [selectedStudentId, setSelectedStudentId] = useState(null);
    const [selectedEditUserId, setSelectedEditUserId] = useState(null);
    const [statusProcessingUserId, setStatusProcessingUserId] = useState(null);
    const [deleteProcessingUserId, setDeleteProcessingUserId] = useState(null);
    const [resetPasswordProcessingUserId, setResetPasswordProcessingUserId] = useState(null);
    const [feedback, setFeedback] = useState('');
    const isInitialRender = useRef(true);

    const guardianForm = useForm({
        guardian_name: '',
        guardian_email: '',
        relationship: 'Tutore legale',
    });
    const editUserForm = useForm({
        name: '',
        surname: '',
        role: '',
        birth_date: '',
        email: '',
    });

    useEffect(() => {
        setQuery(filters.query ?? '');
        setRoleFilter(filters.role ?? '');
        setClassFilter(filters.class ?? '');
    }, [filters.query, filters.role, filters.class]);

    useEffect(() => {
        if (isInitialRender.current) {
            isInitialRender.current = false;
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                route('admin.users'),
                {
                    query: query.trim() || undefined,
                    role: roleFilter || undefined,
                    class: classFilter || undefined,
                },
                {
                    preserveScroll: true,
                    preserveState: true,
                    replace: true,
                    only: ['utenti', 'pagination', 'filters', 'roleOptions', 'classOptions'],
                },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [query, roleFilter, classFilter]);

    const selectedStudent = useMemo(() => {
        if (!selectedStudentId) {
            return null;
        }

        return utenti.find((user) => user.user_id === selectedStudentId) ?? null;
    }, [utenti, selectedStudentId]);

    const selectedEditUser = useMemo(() => {
        if (!selectedEditUserId) {
            return null;
        }

        return utenti.find((user) => user.user_id === selectedEditUserId) ?? null;
    }, [utenti, selectedEditUserId]);
    const isEditingSelf = selectedEditUser?.user_id === authUserId;

    const openGuardianModal = (student) => {
        const guardians = toSafeArray(student?.tutori);
        const firstGuardian =
            guardians.find((guardian) => Boolean(guardian?.is_active)) ??
            guardians[0] ??
            null;

        setSelectedEditUserId(null);
        editUserForm.reset();
        editUserForm.clearErrors();
        guardianForm.setData({
            guardian_name: firstGuardian?.name ?? '',
            guardian_email: firstGuardian?.email ?? '',
            relationship: firstGuardian?.relationship ?? 'Tutore legale',
        });
        guardianForm.clearErrors();
        setFeedback('');
        setSelectedStudentId(student.user_id);
    };

    const closeGuardianModal = () => {
        guardianForm.reset();
        guardianForm.clearErrors();
        setFeedback('');
        setSelectedStudentId(null);
    };

    const submitGuardian = (event) => {
        event.preventDefault();
        if (!selectedStudent) {
            return;
        }

        setFeedback('');

        guardianForm.post(
            route('admin.students.guardian.assign', selectedStudent.user_id),
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setFeedback('Tutore salvato con successo.');
                    guardianForm.clearErrors();
                },
            }
        );
    };

    const openEditUserModal = (user) => {
        setSelectedStudentId(null);
        setFeedback('');
        editUserForm.setData({
            name: user.nome ?? '',
            surname: user.cognome ?? '',
            role: user.ruolo_code ?? 'student',
            birth_date: user.birth_date ?? '',
            email: user.email ?? '',
        });
        editUserForm.clearErrors();
        setSelectedEditUserId(user.user_id);
    };

    const closeEditUserModal = () => {
        editUserForm.reset();
        editUserForm.clearErrors();
        setSelectedEditUserId(null);
    };

    const submitEditUser = (event) => {
        event.preventDefault();
        if (!selectedEditUser) {
            return;
        }

        editUserForm.patch(route('admin.users.update', selectedEditUser.user_id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                closeEditUserModal();
            },
        });
    };

    const toggleUserStatus = (user) => {
        const isActive = user.stato === 'Attivo';
        const verb = isActive ? 'disattivare' : 'attivare';
        const fullName = `${user.nome ?? ''} ${user.cognome ?? ''}`.trim();

        if (isActive && authUserId === user.user_id) {
            window.alert('Non puoi disattivare il tuo account mentre sei autenticato.');
            return;
        }

        if (!window.confirm(`Vuoi ${verb} ${fullName}?`)) {
            return;
        }

        setStatusProcessingUserId(user.user_id);
        router.patch(route('admin.users.toggle-active', user.user_id), {}, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                setStatusProcessingUserId(null);
            },
        });
    };

    const deleteUser = (user) => {
        const fullName = `${user.nome ?? ''} ${user.cognome ?? ''}`.trim();

        if (authUserId === user.user_id) {
            window.alert('Non puoi eliminare il tuo account mentre sei autenticato.');
            return;
        }

        if (!window.confirm(`Vuoi eliminare definitivamente ${fullName}?`)) {
            return;
        }

        setDeleteProcessingUserId(user.user_id);
        router.delete(route('admin.users.destroy', user.user_id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                if (selectedEditUserId === user.user_id) {
                    closeEditUserModal();
                }
            },
            onFinish: () => {
                setDeleteProcessingUserId(null);
            },
        });
    };

    const resetUserPassword = (user) => {
        const fullName = `${user.nome ?? ''} ${user.cognome ?? ''}`.trim();

        if (!window.confirm(`Inviare email reset password a ${fullName}?`)) {
            return;
        }

        setResetPasswordProcessingUserId(user.user_id);
        router.post(route('admin.users.reset-password', user.user_id), {}, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                window.alert('Email reset password inviata.');
            },
            onError: () => {
                window.alert('Invio reset password non riuscito.');
            },
            onFinish: () => {
                setResetPasswordProcessingUserId(null);
            },
        });
    };

    const removeGuardian = (guardianId) => {
        if (!selectedStudent) {
            return;
        }

        setFeedback('');

        router.delete(
            route('admin.students.guardian.remove', {
                student: selectedStudent.user_id,
                guardian: guardianId,
            }),
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setFeedback('Tutore rimosso con successo.');
                },
            }
        );
    };

    return (
        <AuthenticatedLayout header="Gestione utenti">
            <Head title="Gestione utenti" />

            <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-900">Utenti</h2>
                        <p className="text-sm text-slate-500">
                            Gestisci studenti, docenti e staff.
                        </p>
                    </div>
                    <Link
                        href={route('admin.user.create')}
                        className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white"
                    >
                        + Aggiungi utente
                    </Link>
                </div>

                <div className="mt-4 grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 md:grid-cols-3">
                    <label className="flex flex-col gap-2">
                        Nome o email
                        <input
                            className={inputClass}
                            placeholder="Cerca..."
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                        />
                    </label>
                    <label className="flex flex-col gap-2">
                        Ruolo
                        <select
                            className={inputClass}
                            value={roleFilter}
                            onChange={(event) => setRoleFilter(event.target.value)}
                        >
                            <option value="">Tutti</option>
                            {roleOptions.map((role) => (
                                <option key={role.code} value={role.code}>
                                    {role.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-2">
                        Classe
                        <select
                            className={inputClass}
                            value={classFilter}
                            onChange={(event) => setClassFilter(event.target.value)}
                        >
                            <option value="">Tutte</option>
                            {classOptions.map((className) => (
                                <option key={className} value={className}>
                                    {className}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>

                <div className="mt-4 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th className="py-3 text-center align-middle">Nome</th>
                                <th className="py-3 text-center align-middle">Cognome</th>
                                <th className="py-3 text-center align-middle">Email</th>
                                <th className="py-3 text-center align-middle">Classe</th>
                                <th className="py-3 text-center align-middle">Ruolo</th>
                                <th className="py-3 text-center align-middle">Tutori</th>
                                <th className="py-3 text-center align-middle">Stato</th>
                                <th className="py-3 text-center">Azioni</th>
                                <th className="py-3 text-center">Operazioni</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {utenti.length === 0 && (
                                <tr>
                                    <td
                                        className="py-6 text-center text-sm text-slate-400"
                                        colSpan={9}
                                    >
                                        Nessun utente trovato.
                                    </td>
                                </tr>
                            )}
                            {utenti.map((user) => (
                                <tr key={user.id}>
                                    <td className="py-3 text-center align-middle font-medium text-slate-800">{user.nome}</td>
                                    <td className="py-3 text-center align-middle">{user.cognome}</td>
                                    <td className="py-3 text-center align-middle text-slate-500">{user.email}</td>
                                    <td className="py-3 text-center align-middle">{user.classe}</td>
                                    <td className="py-3 text-center align-middle">{user.ruolo}</td>
                                    <td className="py-3 text-center align-middle text-xs text-slate-600">
                                        {toSafeArray(user.tutori).length > 0 ? (
                                            <div className="mx-auto w-fit space-y-1 text-left">
                                                <p>
                                                    {`${user.tutori[0].name} (${user.tutori[0].email})${
                                                        toSafeArray(user.tutori).length > 1
                                                            ? ` +${toSafeArray(user.tutori).length - 1}`
                                                            : ''
                                                    }`}
                                                </p>
                                                <p className="text-[11px] text-slate-500">
                                                    Attivi: {user.tutori_attivi ?? 0} | Inattivi:{' '}
                                                    {user.tutori_inattivi ?? 0}
                                                </p>
                                                {user.ruolo_code === 'student' &&
                                                    user.is_adult && (
                                                        <p className="text-[11px] text-slate-500">
                                                            Info tutori precedenti:{' '}
                                                            {user.notify_previous_guardians_enabled
                                                                ? 'Attiva'
                                                                : 'Disattivata'}
                                                        </p>
                                                    )}
                                            </div>
                                        ) : (
                                            '-'
                                        )}
                                    </td>
                                    <td className="py-3 text-center align-middle">
                                        <span
                                            className={`rounded-full px-3 py-1 text-xs font-semibold ${
                                                user.stato === 'Attivo'
                                                    ? 'bg-emerald-100 text-emerald-700'
                                                    : 'bg-amber-100 text-amber-700'
                                            }`}
                                        >
                                            {user.stato ?? '-'}
                                        </span>
                                    </td>
                                    <td className="py-3 text-center align-middle">
                                        <div className="flex flex-wrap items-center justify-center gap-2">
                                            {user.ruolo_code === 'student' && (
                                                <button
                                                    type="button"
                                                    className="btn-soft-info px-3.5"
                                                    onClick={() => openGuardianModal(user)}
                                                >
                                                    Tutore
                                                </button>
                                            )}
                                            <button
                                                type="button"
                                                className={`px-3.5 ${
                                                    user.stato === 'Attivo'
                                                        ? 'btn-soft-danger'
                                                        : 'btn-soft-info'
                                                }`}
                                                onClick={() => toggleUserStatus(user)}
                                                disabled={
                                                    statusProcessingUserId === user.user_id ||
                                                    deleteProcessingUserId === user.user_id
                                                }
                                            >
                                                {statusProcessingUserId === user.user_id
                                                    ? 'Aggiorno...'
                                                    : user.stato === 'Attivo'
                                                        ? 'Disattiva'
                                                        : 'Attiva'}
                                            </button>
                                        </div>
                                    </td>
                                    <td className="py-3 text-center align-middle">
                                        <div className="inline-flex w-full items-center justify-center gap-2">
                                            <button
                                                type="button"
                                                title="Modifica"
                                                aria-label="Modifica"
                                                className="btn-soft-icon"
                                                onClick={() => openEditUserModal(user)}
                                            >
                                                <ActionGlyph actionKey="edit" className="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                title={deleteProcessingUserId === user.user_id ? 'Elimino...' : 'Elimina'}
                                                aria-label={deleteProcessingUserId === user.user_id ? 'Elimino...' : 'Elimina'}
                                                className="btn-soft-icon-danger"
                                                onClick={() => deleteUser(user)}
                                                disabled={
                                                    deleteProcessingUserId === user.user_id ||
                                                    statusProcessingUserId === user.user_id
                                                }
                                            >
                                                <ActionGlyph actionKey="delete" className="h-4 w-4" />
                                            </button>
                                        </div>
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
                            : `Record visualizzati: ${utenti.length}`}
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

            {selectedStudent && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 px-4">
                    <div className="w-full max-w-2xl rounded-2xl bg-white p-6 shadow-2xl">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h3 className="text-lg font-semibold text-slate-900">
                                    Tutori - {selectedStudent.nome} {selectedStudent.cognome}
                                </h3>
                                <p className="text-sm text-slate-500">
                                    Associa o aggiorna i tutori dello studente.
                                </p>
                                {selectedStudent.ruolo_code === 'student' &&
                                    selectedStudent.is_adult && (
                                        <p className="mt-1 text-xs text-slate-500">
                                            Notifica ai tutori precedenti:{' '}
                                            <span
                                                className={
                                                    selectedStudent.notify_previous_guardians_enabled
                                                        ? 'font-semibold text-emerald-700'
                                                        : 'font-semibold text-amber-700'
                                                }
                                            >
                                                {selectedStudent.notify_previous_guardians_enabled
                                                    ? 'Attiva'
                                                    : 'Disattivata'}
                                            </span>
                                        </p>
                                    )}
                            </div>
                            <button
                                type="button"
                                className="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600"
                                onClick={closeGuardianModal}
                            >
                                Chiudi
                            </button>
                        </div>

                        <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                Tutori associati
                            </p>
                            <div className="mt-2 space-y-2">
                                {toSafeArray(selectedStudent.tutori).length === 0 && (
                                    <p className="text-sm text-slate-500">Nessun tutore associato.</p>
                                )}
                                {toSafeArray(selectedStudent.tutori).map((guardian) => (
                                    <div
                                        key={`${selectedStudent.user_id}-${guardian.id}`}
                                        className="flex items-center justify-between rounded-lg border border-slate-200 bg-white px-3 py-2"
                                    >
                                        <div className="text-sm text-slate-700">
                                            <span className="font-semibold">{guardian.name}</span>{' '}
                                            <span className="text-slate-500">({guardian.email})</span>{' '}
                                            <span className="text-xs text-slate-500">
                                                {guardian.relationship || 'Tutore'}
                                            </span>
                                            <span
                                                className={`ml-2 rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                                    guardian.is_active
                                                        ? 'bg-emerald-100 text-emerald-700'
                                                        : 'bg-amber-100 text-amber-700'
                                                }`}
                                            >
                                                {guardian.is_active ? 'Attivo' : 'Inattivo'}
                                            </span>
                                            {!guardian.is_active &&
                                                guardian.deactivated_at && (
                                                    <span className="ml-2 text-[11px] text-slate-500">
                                                        disattivato il{' '}
                                                        {new Date(
                                                            guardian.deactivated_at
                                                        ).toLocaleDateString('it-CH')}
                                                    </span>
                                                )}
                                        </div>
                                        {guardian.is_active ? (
                                            <button
                                                type="button"
                                                className="rounded-full border border-rose-200 px-3 py-1 text-xs text-rose-600"
                                                onClick={() => removeGuardian(guardian.id)}
                                            >
                                                Disattiva
                                            </button>
                                        ) : (
                                            <span className="rounded-full border border-slate-200 px-3 py-1 text-xs text-slate-500">
                                                Storico
                                            </span>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>

                        <form onSubmit={submitGuardian} className="mt-4 space-y-3">
                            <div className="grid gap-3 md:grid-cols-2">
                                <label className="flex flex-col gap-1 text-xs font-semibold text-slate-600">
                                    Nome tutore
                                    <input
                                        className={inputClass}
                                        value={guardianForm.data.guardian_name}
                                        onChange={(event) =>
                                            guardianForm.setData(
                                                'guardian_name',
                                                event.target.value
                                            )
                                        }
                                        placeholder="Mario Rossi"
                                    />
                                </label>
                                <label className="flex flex-col gap-1 text-xs font-semibold text-slate-600">
                                    Email tutore
                                    <input
                                        className={inputClass}
                                        type="email"
                                        value={guardianForm.data.guardian_email}
                                        onChange={(event) =>
                                            guardianForm.setData(
                                                'guardian_email',
                                                event.target.value
                                            )
                                        }
                                        placeholder="mario.rossi@email.com"
                                    />
                                </label>
                                <label className="flex flex-col gap-1 text-xs font-semibold text-slate-600 md:col-span-2">
                                    Relazione
                                    <input
                                        className={inputClass}
                                        value={guardianForm.data.relationship}
                                        onChange={(event) =>
                                            guardianForm.setData(
                                                'relationship',
                                                event.target.value
                                            )
                                        }
                                        placeholder="Tutore legale"
                                    />
                                </label>
                            </div>

                            {(guardianForm.errors.guardian_name ||
                                guardianForm.errors.guardian_email ||
                                guardianForm.errors.relationship) && (
                                <p className="text-xs text-rose-600">
                                    {guardianForm.errors.guardian_name ||
                                        guardianForm.errors.guardian_email ||
                                        guardianForm.errors.relationship}
                                </p>
                            )}

                            {feedback && (
                                <p className="text-xs text-emerald-600">{feedback}</p>
                            )}

                            <div className="flex justify-end gap-2">
                                <button
                                    type="button"
                                    className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700"
                                    onClick={closeGuardianModal}
                                >
                                    Annulla
                                </button>
                                <button
                                    type="submit"
                                    className="btn-soft-neutral px-4 py-2 text-xs"
                                    disabled={guardianForm.processing}
                                >
                                    Salva tutore
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {selectedEditUser && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 px-4">
                    <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h3 className="text-lg font-semibold text-slate-900">
                                    Modifica utente
                                </h3>
                                <p className="text-sm text-slate-500">
                                    Aggiorna i dati principali e il ruolo.
                                </p>
                            </div>
                            <button
                                type="button"
                                className="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600"
                                onClick={closeEditUserModal}
                            >
                                Chiudi
                            </button>
                        </div>

                        <form onSubmit={submitEditUser} className="mt-4 space-y-3">
                            <div className="grid gap-3 md:grid-cols-2">
                                <label className="flex flex-col gap-1 text-xs font-semibold text-slate-600">
                                    Nome
                                    <input
                                        className={inputClass}
                                        value={editUserForm.data.name}
                                        onChange={(event) =>
                                            editUserForm.setData('name', event.target.value)
                                        }
                                        placeholder="Nome"
                                    />
                                </label>
                                <label className="flex flex-col gap-1 text-xs font-semibold text-slate-600">
                                    Cognome
                                    <input
                                        className={inputClass}
                                        value={editUserForm.data.surname}
                                        onChange={(event) =>
                                            editUserForm.setData('surname', event.target.value)
                                        }
                                        placeholder="Cognome"
                                    />
                                </label>
                                <label className="flex flex-col gap-1 text-xs font-semibold text-slate-600 md:col-span-2">
                                    Data di nascita
                                    <input
                                        className={inputClass}
                                        type="date"
                                        value={editUserForm.data.birth_date}
                                        max={new Date().toISOString().slice(0, 10)}
                                        onChange={(event) =>
                                            editUserForm.setData(
                                                'birth_date',
                                                event.target.value
                                            )
                                        }
                                    />
                                </label>
                                <label className="flex flex-col gap-1 text-xs font-semibold text-slate-600 md:col-span-2">
                                    Email
                                    <input
                                        className={inputClass}
                                        type="email"
                                        value={editUserForm.data.email}
                                        disabled={isEditingSelf}
                                        onChange={(event) =>
                                            editUserForm.setData('email', event.target.value)
                                        }
                                        placeholder="nome.cognome@scuola.ch"
                                    />
                                    {isEditingSelf && (
                                        <span className="text-[11px] font-normal text-slate-500">
                                            La tua email deve cambiarla un altro admin.
                                        </span>
                                    )}
                                </label>
                                <label className="flex flex-col gap-1 text-xs font-semibold text-slate-600 md:col-span-2">
                                    Ruolo
                                    <select
                                        className={inputClass}
                                        value={editUserForm.data.role}
                                        onChange={(event) =>
                                            editUserForm.setData('role', event.target.value)
                                        }
                                    >
                                        {roleOptions.map((role) => (
                                            <option key={role.code} value={role.code}>
                                                {role.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>

                            {(editUserForm.errors.name ||
                                editUserForm.errors.surname ||
                                editUserForm.errors.role ||
                                editUserForm.errors.birth_date ||
                                editUserForm.errors.email) && (
                                <p className="text-xs text-rose-600">
                                    {editUserForm.errors.name ||
                                        editUserForm.errors.surname ||
                                        editUserForm.errors.role ||
                                        editUserForm.errors.birth_date ||
                                        editUserForm.errors.email}
                                </p>
                            )}

                            <div className="flex justify-end gap-2">
                                <button
                                    type="button"
                                    className="btn-soft-info px-4 py-2 text-xs"
                                    onClick={() => resetUserPassword(selectedEditUser)}
                                    disabled={resetPasswordProcessingUserId === selectedEditUser.user_id}
                                >
                                    {resetPasswordProcessingUserId === selectedEditUser.user_id
                                        ? 'Invio reset...'
                                        : 'Reset password'}
                                </button>
                                <button
                                    type="button"
                                    className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700"
                                    onClick={closeEditUserModal}
                                >
                                    Annulla
                                </button>
                                <button
                                    type="submit"
                                    className="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-400"
                                    disabled={editUserForm.processing}
                                >
                                    Salva modifiche
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
