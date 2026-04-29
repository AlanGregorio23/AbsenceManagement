import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

const inputClass =
    'rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-100';

const toSafeArray = (value) => (Array.isArray(value) ? value : []);
const toIdSet = (value) =>
    new Set(toSafeArray(value).map((item) => Number(item)));
const normalizeSearch = (value) => value.trim().toLowerCase();

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

const optionMatches = (option, query) => {
    if (!query) {
        return true;
    }

    const text = `${option.label} ${option.email ?? ''}`.toLowerCase();
    return text.includes(query);
};

function SelectionPanel({
    title,
    hint,
    searchValue,
    onSearchChange,
    items,
    selectedIds,
    onToggle,
    onToggleFiltered,
    error,
    emptyLabel,
}) {
    const itemIds = items.map((item) => Number(item.id));
    const hasAllFiltered =
        itemIds.length > 0 && itemIds.every((id) => selectedIds.has(id));

    return (
        <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm font-semibold text-slate-700">{title}</p>
                <span className="rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-slate-600">
                    {selectedIds.size}
                </span>
            </div>

            <div className="mt-3 flex gap-2">
                <input
                    className={`${inputClass} flex-1`}
                    value={searchValue}
                    onChange={(event) => onSearchChange(event.target.value)}
                    placeholder={`Cerca ${title.toLowerCase()}...`}
                />
                <button
                    type="button"
                    className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600"
                    onClick={onToggleFiltered}
                >
                    {hasAllFiltered ? 'Deseleziona filtrati' : 'Seleziona filtrati'}
                </button>
            </div>

            <div className="mt-3 max-h-60 space-y-2 overflow-y-auto pr-1">
                {items.length === 0 && (
                    <p className="rounded-lg border border-dashed border-slate-200 bg-white px-3 py-2 text-xs text-slate-500">
                        {emptyLabel}
                    </p>
                )}
                {items.map((item) => (
                    <label
                        key={item.id}
                        className="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 transition hover:border-slate-300"
                    >
                        <input
                            type="checkbox"
                            checked={selectedIds.has(Number(item.id))}
                            onChange={() => onToggle(item.id)}
                            className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                        />
                        <span className="font-medium text-slate-800">
                            {item.label}
                        </span>
                        <span className="text-xs text-slate-500">
                            {item.email}
                        </span>
                    </label>
                ))}
            </div>

            {hint && <p className="mt-2 text-xs text-slate-400">{hint}</p>}
            {error && <p className="mt-2 text-xs text-rose-600">{error}</p>}
        </div>
    );
}

export default function Classes({
    classi = [],
    pagination = null,
    filters = {},
    yearOptions = [],
    availableTeachers = [],
    availableStudents = [],
}) {
    const [query, setQuery] = useState(filters.query ?? '');
    const [yearFilter, setYearFilter] = useState(filters.year ?? '');
    const [teacherFilter, setTeacherFilter] = useState(filters.teacher ?? '');
    const [selectedClassId, setSelectedClassId] = useState(null);
    const [selectedClassDetailId, setSelectedClassDetailId] = useState(null);
    const [deleteProcessingClassId, setDeleteProcessingClassId] = useState(null);
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
    const [createTeacherSearch, setCreateTeacherSearch] = useState('');
    const [createStudentSearch, setCreateStudentSearch] = useState('');
    const [editTeacherSearch, setEditTeacherSearch] = useState('');
    const [editStudentSearch, setEditStudentSearch] = useState('');
    const isInitialRender = useRef(true);

    const createClassForm = useForm({
        name: '',
        section: '',
        year: '',
        teacher_ids: [],
        student_ids: [],
    });

    const editClassForm = useForm({
        name: '',
        section: '',
        year: '',
        teacher_ids: [],
        student_ids: [],
    });

    useEffect(() => {
        setQuery(filters.query ?? '');
        setYearFilter(filters.year ?? '');
        setTeacherFilter(filters.teacher ?? '');
    }, [filters.query, filters.year, filters.teacher]);

    useEffect(() => {
        if (isInitialRender.current) {
            isInitialRender.current = false;
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                route('admin.classes'),
                {
                    query: query.trim() || undefined,
                    year: yearFilter || undefined,
                    teacher: teacherFilter || undefined,
                },
                {
                    preserveScroll: true,
                    preserveState: true,
                    replace: true,
                    only: [
                        'classi',
                        'pagination',
                        'filters',
                        'yearOptions',
                        'availableTeachers',
                        'availableStudents',
                    ],
                },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [query, yearFilter, teacherFilter]);

    const selectedClass = useMemo(() => {
        if (!selectedClassId) {
            return null;
        }

        return classi.find((row) => row.class_id === selectedClassId) ?? null;
    }, [classi, selectedClassId]);

    const selectedClassDetails = useMemo(() => {
        if (!selectedClassDetailId) {
            return null;
        }

        return classi.find((row) => row.class_id === selectedClassDetailId) ?? null;
    }, [classi, selectedClassDetailId]);

    useEffect(() => {
        if (selectedClassDetailId !== null && !selectedClassDetails) {
            setSelectedClassDetailId(null);
        }
    }, [selectedClassDetailId, selectedClassDetails]);

    const selectedCreateTeacherIds = useMemo(
        () => toIdSet(createClassForm.data.teacher_ids),
        [createClassForm.data.teacher_ids]
    );
    const selectedCreateStudentIds = useMemo(
        () => toIdSet(createClassForm.data.student_ids),
        [createClassForm.data.student_ids]
    );

    const selectedEditTeacherIds = useMemo(
        () => toIdSet(editClassForm.data.teacher_ids),
        [editClassForm.data.teacher_ids]
    );
    const selectedEditStudentIds = useMemo(
        () => toIdSet(editClassForm.data.student_ids),
        [editClassForm.data.student_ids]
    );

    const filteredCreateTeachers = useMemo(() => {
        const normalizedQuery = normalizeSearch(createTeacherSearch);
        return availableTeachers.filter((option) =>
            optionMatches(option, normalizedQuery)
        );
    }, [availableTeachers, createTeacherSearch]);

    const filteredCreateStudents = useMemo(() => {
        const normalizedQuery = normalizeSearch(createStudentSearch);
        return availableStudents.filter((option) =>
            optionMatches(option, normalizedQuery)
        );
    }, [availableStudents, createStudentSearch]);

    const filteredEditTeachers = useMemo(() => {
        const normalizedQuery = normalizeSearch(editTeacherSearch);
        return availableTeachers.filter((option) =>
            optionMatches(option, normalizedQuery)
        );
    }, [availableTeachers, editTeacherSearch]);

    const filteredEditStudents = useMemo(() => {
        const normalizedQuery = normalizeSearch(editStudentSearch);
        return availableStudents.filter((option) =>
            optionMatches(option, normalizedQuery)
        );
    }, [availableStudents, editStudentSearch]);

    const openCreateClassModal = () => {
        createClassForm.reset();
        createClassForm.clearErrors();
        setCreateTeacherSearch('');
        setCreateStudentSearch('');
        setIsCreateModalOpen(true);
    };

    const closeCreateClassModal = () => {
        createClassForm.reset();
        createClassForm.clearErrors();
        setCreateTeacherSearch('');
        setCreateStudentSearch('');
        setIsCreateModalOpen(false);
    };

    const submitCreateClass = (event) => {
        event.preventDefault();

        createClassForm.transform((data) => ({
            ...data,
            teacher_ids: toSafeArray(data.teacher_ids).map((value) =>
                Number(value)
            ),
            student_ids: toSafeArray(data.student_ids).map((value) =>
                Number(value)
            ),
        }));

        createClassForm.post(route('admin.classes.store'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                closeCreateClassModal();
            },
        });
    };

    const openEditClassModal = (row) => {
        editClassForm.setData({
            name: row.nome ?? '',
            section: row.sezione ?? '',
            year: row.anno ?? '',
            teacher_ids: toSafeArray(row.teacher_ids).map((value) => Number(value)),
            student_ids: toSafeArray(row.student_ids).map((value) => Number(value)),
        });
        editClassForm.clearErrors();
        setEditTeacherSearch('');
        setEditStudentSearch('');
        setSelectedClassId(row.class_id);
    };

    const openClassDetailsModal = (row) => {
        setSelectedClassDetailId(row.class_id);
    };

    const closeEditClassModal = () => {
        setSelectedClassId(null);
        editClassForm.reset();
        editClassForm.clearErrors();
        setEditTeacherSearch('');
        setEditStudentSearch('');
    };

    const closeClassDetailsModal = () => {
        setSelectedClassDetailId(null);
    };

    const toggleSelection = (form, field, id) => {
        const values = toIdSet(form.data[field]);
        const numericId = Number(id);

        if (values.has(numericId)) {
            values.delete(numericId);
        } else {
            values.add(numericId);
        }

        form.setData(field, Array.from(values));
    };

    const toggleFilteredSelection = (form, field, filteredItems) => {
        const values = toIdSet(form.data[field]);
        const ids = filteredItems.map((item) => Number(item.id));
        const allSelected =
            ids.length > 0 && ids.every((id) => values.has(id));

        ids.forEach((id) => {
            if (allSelected) {
                values.delete(id);
            } else {
                values.add(id);
            }
        });

        form.setData(field, Array.from(values));
    };

    const submitClassUpdate = (event) => {
        event.preventDefault();
        if (!selectedClass) {
            return;
        }

        editClassForm.transform((data) => ({
            ...data,
            teacher_ids: toSafeArray(data.teacher_ids).map((value) =>
                Number(value)
            ),
            student_ids: toSafeArray(data.student_ids).map((value) =>
                Number(value)
            ),
        }));

        editClassForm.patch(route('admin.classes.update', selectedClass.class_id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => closeEditClassModal(),
        });
    };

    const deleteClass = (row) => {
        const classLabel = `${row.nome ?? ''} ${row.anno ?? ''}${row.sezione ?? ''}`.trim();

        if (!window.confirm(`Vuoi eliminare definitivamente la classe ${classLabel}?`)) {
            return;
        }

        setDeleteProcessingClassId(row.class_id);
        router.delete(route('admin.classes.destroy', row.class_id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                if (selectedClassId === row.class_id) {
                    closeEditClassModal();
                }

                if (selectedClassDetailId === row.class_id) {
                    closeClassDetailsModal();
                }
            },
            onFinish: () => {
                setDeleteProcessingClassId(null);
            },
        });
    };

    return (
        <AuthenticatedLayout header="Gestione classi">
            <Head title="Gestione classi" />

            <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-900">
                            Classi
                        </h2>
                        <p className="text-sm text-slate-500">
                            Panoramica classi e docenti associati.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={openCreateClassModal}
                        className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white"
                    >
                        + Aggiungi classe
                    </button>
                </div>

                <div className="mt-4 grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 md:grid-cols-3">
                    <label className="flex flex-col gap-2">
                        Classe
                        <input
                            className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"
                            placeholder="Es. INF4A"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                        />
                    </label>
                    <label className="flex flex-col gap-2">
                        Anno
                        <select
                            className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"
                            value={yearFilter}
                            onChange={(event) => setYearFilter(event.target.value)}
                        >
                            <option value="">Tutti</option>
                            {yearOptions.map((year) => (
                                <option key={year} value={year}>
                                    {year}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-2">
                        Docente
                        <input
                            className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"
                            placeholder="Es. Paolo Rossi"
                            value={teacherFilter}
                            onChange={(event) => setTeacherFilter(event.target.value)}
                        />
                    </label>
                </div>

                <div className="mt-4 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th className="py-3 text-center">Classe</th>
                                <th className="py-3 text-center">Sezione</th>
                                <th className="py-3 text-center">Anno</th>
                                <th className="py-3 text-center">Docente</th>
                                <th className="py-3 text-center">Studenti</th>
                                <th className="py-3 text-center">Azioni</th>
                                <th className="py-3 text-center">Dettagli</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {classi.length === 0 && (
                                <tr>
                                    <td
                                        className="py-6 text-center text-sm text-slate-400"
                                        colSpan={7}
                                    >
                                        Nessuna classe trovata.
                                    </td>
                                </tr>
                            )}
                            {classi.map((row) => (
                                <tr key={row.id}>
                                    <td className="py-3 text-center font-medium text-slate-800">
                                        {row.nome}
                                    </td>
                                    <td className="py-3 text-center">{row.sezione}</td>
                                    <td className="py-3 text-center">{row.anno}</td>
                                    <td className="py-3 text-center">{row.docente}</td>
                                    <td className="py-3 text-center">{row.studenti}</td>
                                    <td className="py-3 text-center">
                                        <div className="inline-flex items-center justify-center gap-2">
                                            <button
                                                type="button"
                                                title="Modifica"
                                                aria-label="Modifica"
                                                className="btn-soft-icon"
                                                onClick={() => openEditClassModal(row)}
                                            >
                                                <ActionGlyph actionKey="edit" className="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                title={deleteProcessingClassId === row.class_id ? 'Elimino...' : 'Elimina'}
                                                aria-label={deleteProcessingClassId === row.class_id ? 'Elimino...' : 'Elimina'}
                                                className="btn-soft-icon-danger"
                                                onClick={() => deleteClass(row)}
                                                disabled={deleteProcessingClassId === row.class_id}
                                            >
                                                <ActionGlyph actionKey="delete" className="h-4 w-4" />
                                            </button>
                                        </div>
                                    </td>
                                    <td className="py-3 text-center">
                                        <button
                                            type="button"
                                            className="inline-flex h-9 items-center justify-center rounded-lg bg-slate-100 px-3 text-xs font-semibold text-slate-700 hover:bg-slate-200"
                                            onClick={() => openClassDetailsModal(row)}
                                        >
                                            Dettagli
                                        </button>
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
                            : `Record visualizzati: ${classi.length}`}
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

            {isCreateModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 px-4">
                    <div className="w-full max-w-5xl rounded-2xl bg-white p-6 shadow-2xl">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h3 className="text-lg font-semibold text-slate-900">
                                    Nuova classe
                                </h3>
                                <p className="text-sm text-slate-500">
                                    Inserisci i dati della classe e le assegnazioni iniziali.
                                </p>
                            </div>
                            <button
                                type="button"
                                className="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600"
                                onClick={closeCreateClassModal}
                            >
                                Chiudi
                            </button>
                        </div>

                        <form onSubmit={submitCreateClass} className="mt-4 space-y-3">
                            <div className="grid gap-3 md:grid-cols-3">
                                <label className="flex flex-col gap-1 text-xs font-semibold text-slate-600 md:col-span-3">
                                    Nome classe
                                    <input
                                        className={inputClass}
                                        value={createClassForm.data.name}
                                        onChange={(event) =>
                                            createClassForm.setData('name', event.target.value)
                                        }
                                        placeholder="Es. INF5A"
                                    />
                                    {createClassForm.errors.name && (
                                        <p className="text-xs text-rose-600">
                                            {createClassForm.errors.name}
                                        </p>
                                    )}
                                </label>
                                <label className="flex flex-col gap-1 text-xs font-semibold text-slate-600">
                                    Sezione
                                    <input
                                        className={inputClass}
                                        value={createClassForm.data.section}
                                        onChange={(event) =>
                                            createClassForm.setData('section', event.target.value)
                                        }
                                        placeholder="A"
                                    />
                                    {createClassForm.errors.section && (
                                        <p className="text-xs text-rose-600">
                                            {createClassForm.errors.section}
                                        </p>
                                    )}
                                </label>
                                <label className="flex flex-col gap-1 text-xs font-semibold text-slate-600">
                                    Anno
                                    <input
                                        className={inputClass}
                                        value={createClassForm.data.year}
                                        onChange={(event) =>
                                            createClassForm.setData('year', event.target.value)
                                        }
                                        placeholder="1"
                                    />
                                    {createClassForm.errors.year && (
                                        <p className="text-xs text-rose-600">
                                            {createClassForm.errors.year}
                                        </p>
                                    )}
                                </label>
                            </div>

                            <div className="grid gap-3 lg:grid-cols-2">
                                <SelectionPanel
                                    title="Docenti assegnati"
                                    hint="Puoi filtrare e selezionare piu docenti in modo rapido."
                                    searchValue={createTeacherSearch}
                                    onSearchChange={setCreateTeacherSearch}
                                    items={filteredCreateTeachers}
                                    selectedIds={selectedCreateTeacherIds}
                                    onToggle={(id) =>
                                        toggleSelection(createClassForm, 'teacher_ids', id)
                                    }
                                    onToggleFiltered={() =>
                                        toggleFilteredSelection(
                                            createClassForm,
                                            'teacher_ids',
                                            filteredCreateTeachers
                                        )
                                    }
                                    error={createClassForm.errors.teacher_ids}
                                    emptyLabel="Nessun docente trovato."
                                />

                                <SelectionPanel
                                    title="Studenti assegnati"
                                    hint="Gli studenti selezionati vengono rimossi automaticamente dalle altre classi."
                                    searchValue={createStudentSearch}
                                    onSearchChange={setCreateStudentSearch}
                                    items={filteredCreateStudents}
                                    selectedIds={selectedCreateStudentIds}
                                    onToggle={(id) =>
                                        toggleSelection(createClassForm, 'student_ids', id)
                                    }
                                    onToggleFiltered={() =>
                                        toggleFilteredSelection(
                                            createClassForm,
                                            'student_ids',
                                            filteredCreateStudents
                                        )
                                    }
                                    error={createClassForm.errors.student_ids}
                                    emptyLabel="Nessuno studente trovato."
                                />
                            </div>

                            <div className="flex items-center justify-between gap-3 border-t border-slate-200 pt-4 text-xs text-slate-500">
                                <div>
                                    Docenti selezionati: {selectedCreateTeacherIds.size} -
                                    Studenti selezionati: {selectedCreateStudentIds.size}
                                </div>
                            </div>

                            <div className="flex justify-end gap-2">
                                <button
                                    type="button"
                                    className="rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700"
                                    onClick={closeCreateClassModal}
                                >
                                    Annulla
                                </button>
                                <button
                                    type="submit"
                                    className="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-400"
                                    disabled={createClassForm.processing}
                                >
                                    {createClassForm.processing ? 'Salvataggio...' : 'Crea classe'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {selectedClassDetails && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/55 p-4 backdrop-blur-[2px]">
                    <div className="w-full max-w-3xl rounded-2xl border border-white/60 bg-white/90 p-6 shadow-2xl backdrop-blur-xl">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h3 className="text-lg font-semibold text-slate-900">
                                    Dettagli classe {selectedClassDetails.nome ?? '-'}
                                </h3>
                                <p className="text-sm text-slate-500">
                                    Vista rapida come nello storico interazioni.
                                </p>
                            </div>
                            <button
                                type="button"
                                className="rounded-lg border border-slate-200 bg-white/70 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-white"
                                onClick={closeClassDetailsModal}
                            >
                                Chiudi
                            </button>
                        </div>

                        <div className="mt-4 grid gap-3 text-sm text-slate-700 sm:grid-cols-2">
                            <p>
                                <span className="font-semibold">Classe:</span>{' '}
                                {selectedClassDetails.nome ?? '-'}
                            </p>
                            <p>
                                <span className="font-semibold">Sezione:</span>{' '}
                                {selectedClassDetails.sezione ?? '-'}
                            </p>
                            <p>
                                <span className="font-semibold">Anno:</span>{' '}
                                {selectedClassDetails.anno ?? '-'}
                            </p>
                            <p>
                                <span className="font-semibold">Docente:</span>{' '}
                                {selectedClassDetails.docente ?? '-'}
                            </p>
                            <p>
                                <span className="font-semibold">Studenti:</span>{' '}
                                {selectedClassDetails.studenti ?? '-'}
                            </p>
                            <p>
                                <span className="font-semibold">Creata il:</span>{' '}
                                {selectedClassDetails.creato_il ?? '-'}
                            </p>
                            <p>
                                <span className="font-semibold">Docenti assegnati:</span>{' '}
                                {toSafeArray(selectedClassDetails.teacher_ids).length}
                            </p>
                            <p>
                                <span className="font-semibold">Studenti assegnati:</span>{' '}
                                {toSafeArray(selectedClassDetails.student_ids).length}
                            </p>
                        </div>

                        <div className="mt-5 flex justify-end gap-2">
                            <button
                                type="button"
                                title="Modifica classe"
                                aria-label="Modifica classe"
                                className="btn-soft-icon"
                                onClick={() => {
                                    closeClassDetailsModal();
                                    openEditClassModal(selectedClassDetails);
                                }}
                            >
                                <ActionGlyph actionKey="edit" className="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                title={deleteProcessingClassId === selectedClassDetails.class_id ? 'Elimino...' : 'Elimina classe'}
                                aria-label={deleteProcessingClassId === selectedClassDetails.class_id ? 'Elimino...' : 'Elimina classe'}
                                className="btn-soft-icon-danger"
                                onClick={() => deleteClass(selectedClassDetails)}
                                disabled={deleteProcessingClassId === selectedClassDetails.class_id}
                            >
                                <ActionGlyph actionKey="delete" className="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {selectedClass && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 px-4">
                    <div className="w-full max-w-5xl rounded-2xl bg-white p-6 shadow-2xl">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h3 className="text-lg font-semibold text-slate-900">
                                    Modifica classe {selectedClass.nome}
                                </h3>
                                <p className="text-sm text-slate-500">
                                    Aggiorna dati classe e persone assegnate.
                                </p>
                            </div>
                            <button
                                type="button"
                                className="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600"
                                onClick={closeEditClassModal}
                            >
                                Chiudi
                            </button>
                        </div>

                        <form onSubmit={submitClassUpdate} className="mt-5 space-y-4">
                            <div className="grid gap-3 md:grid-cols-3">
                                <label className="flex flex-col gap-2 text-sm text-slate-600">
                                    Nome classe
                                    <input
                                        className={inputClass}
                                        value={editClassForm.data.name}
                                        onChange={(event) =>
                                            editClassForm.setData(
                                                'name',
                                                event.target.value
                                            )
                                        }
                                    />
                                    {editClassForm.errors.name && (
                                        <p className="text-xs text-rose-600">
                                            {editClassForm.errors.name}
                                        </p>
                                    )}
                                </label>

                                <label className="flex flex-col gap-2 text-sm text-slate-600">
                                    Sezione
                                    <input
                                        className={inputClass}
                                        value={editClassForm.data.section}
                                        onChange={(event) =>
                                            editClassForm.setData(
                                                'section',
                                                event.target.value
                                            )
                                        }
                                    />
                                    {editClassForm.errors.section && (
                                        <p className="text-xs text-rose-600">
                                            {editClassForm.errors.section}
                                        </p>
                                    )}
                                </label>

                                <label className="flex flex-col gap-2 text-sm text-slate-600">
                                    Anno
                                    <input
                                        className={inputClass}
                                        value={editClassForm.data.year}
                                        onChange={(event) =>
                                            editClassForm.setData(
                                                'year',
                                                event.target.value
                                            )
                                        }
                                    />
                                    {editClassForm.errors.year && (
                                        <p className="text-xs text-rose-600">
                                            {editClassForm.errors.year}
                                        </p>
                                    )}
                                </label>
                            </div>

                            <div className="grid gap-3 lg:grid-cols-2">
                                <SelectionPanel
                                    title="Docenti assegnati"
                                    hint="Puoi filtrare e selezionare piu docenti in modo rapido."
                                    searchValue={editTeacherSearch}
                                    onSearchChange={setEditTeacherSearch}
                                    items={filteredEditTeachers}
                                    selectedIds={selectedEditTeacherIds}
                                    onToggle={(id) =>
                                        toggleSelection(editClassForm, 'teacher_ids', id)
                                    }
                                    onToggleFiltered={() =>
                                        toggleFilteredSelection(
                                            editClassForm,
                                            'teacher_ids',
                                            filteredEditTeachers
                                        )
                                    }
                                    error={editClassForm.errors.teacher_ids}
                                    emptyLabel="Nessun docente trovato."
                                />

                                <SelectionPanel
                                    title="Studenti assegnati"
                                    hint="Gli studenti selezionati vengono rimossi automaticamente dalle altre classi."
                                    searchValue={editStudentSearch}
                                    onSearchChange={setEditStudentSearch}
                                    items={filteredEditStudents}
                                    selectedIds={selectedEditStudentIds}
                                    onToggle={(id) =>
                                        toggleSelection(editClassForm, 'student_ids', id)
                                    }
                                    onToggleFiltered={() =>
                                        toggleFilteredSelection(
                                            editClassForm,
                                            'student_ids',
                                            filteredEditStudents
                                        )
                                    }
                                    error={editClassForm.errors.student_ids}
                                    emptyLabel="Nessuno studente trovato."
                                />
                            </div>

                            <div className="flex items-center justify-between gap-3 border-t border-slate-200 pt-4">
                                <div className="text-xs text-slate-500">
                                    Docenti selezionati: {selectedEditTeacherIds.size} -
                                    Studenti selezionati: {selectedEditStudentIds.size}
                                </div>
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600"
                                        onClick={closeEditClassModal}
                                    >
                                        Annulla
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={editClassForm.processing}
                                        className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-400"
                                    >
                                        {editClassForm.processing
                                            ? 'Salvataggio...'
                                            : 'Salva modifiche'}
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
