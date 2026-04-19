import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import * as XLSX from 'xlsx';

export default function AddUser({ roleOptions = [], classOptions = [] }) {
    const fileInputRef = useRef(null);
    const [fileName, setFileName] = useState('Nessun file selezionato');
    const [conversionError, setConversionError] = useState('');
    const defaultRole = roleOptions[0]?.code ?? 'student';
    const manualForm = useForm({
        name: '',
        surname: '',
        email: '',
        role: defaultRole,
        birth_date: '',
        class_id: '',
    });
    const csvForm = useForm({
        file: null,
    });

    const convertXlsxToCsv = async (file) => {
        const buffer = await file.arrayBuffer();
        const workbook = XLSX.read(buffer, { type: 'array' });
        const sheetName = workbook.SheetNames[0];
        const sheet = workbook.Sheets[sheetName];
        const csv = XLSX.utils.sheet_to_csv(sheet, { FS: ';' });
        const csvName = file.name.replace(/\.(xlsx|xls)$/i, '.csv');

        return new File([csv], csvName, { type: 'text/csv' });
    };

    const handleFileChange = async (event) => {
        setConversionError('');
        const file = event.target.files?.[0] ?? null;
        if (!file) {
            csvForm.setData('file', null);
            setFileName('Nessun file selezionato');
            return;
        }

        const isSpreadsheet = /\.(xlsx|xls)$/i.test(file.name);
        if (isSpreadsheet) {
            try {
                const converted = await convertXlsxToCsv(file);
                csvForm.setData('file', converted);
                setFileName(`${file.name} (convertito in CSV)`);
            } catch (error) {
                csvForm.setData('file', null);
                setFileName('Nessun file selezionato');
                setConversionError('Conversione file non riuscita.');
            }
            return;
        }

        csvForm.setData('file', file);
        setFileName(file.name);
    };

    const submitManual = (event) => {
        event.preventDefault();

        manualForm.post(route('admin.user.manual.store'), {
            preserveScroll: true,
            onSuccess: () => {
                manualForm.reset();
                manualForm.setData('role', defaultRole);
            },
        });
    };

    const submitCsv = (event) => {
        event.preventDefault();

        if (!csvForm.data.file) {
            return;
        }

        csvForm.post(route('admin.user.FromCSVStore'), {
            forceFormData: true,
            onSuccess: () => {
                csvForm.reset('file');
                setFileName('Nessun file selezionato');
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            },
        });
    };

    const handleRoleChange = (value) => {
        manualForm.setData('role', value);

        if (value !== 'student') {
            manualForm.setData('class_id', '');
        }
    };

    return (
        <AuthenticatedLayout header="Aggiunta utenti">
            <Head title="Aggiunta utenti" />

            <div className="space-y-6">
                <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">
                            Pannello admin
                        </p>
                        <div className="mt-2">
                            <h1 className="text-2xl font-semibold text-slate-900">
                                Crea nuovi utenti in due modi
                            </h1>
                            <p className="mt-2 max-w-2xl text-sm text-slate-600">
                                Inserisci manualmente un profilo singolo oppure carica
                                un file CSV con piu utenti.
                            </p>
                        </div>
                    </div>
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-semibold text-slate-900">
                                    Inserimento manuale
                                </h2>
                                <p className="text-sm text-slate-500">
                                    Crea un utente alla volta. Al salvataggio parte email impostazione password.
                                </p>
                            </div>
                            <span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">
                                1 utente
                            </span>
                        </div>

                        <form
                            onSubmit={submitManual}
                            className="mt-5 space-y-4 text-sm text-slate-600"
                        >
                            <div className="grid gap-3 sm:grid-cols-2">
                                <label className="flex flex-col gap-2">
                                    Nome
                                    <input
                                        className="rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                        placeholder="Luca"
                                        value={manualForm.data.name}
                                        onChange={(event) =>
                                            manualForm.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    {manualForm.errors.name && (
                                        <p className="text-xs text-rose-500">
                                            {manualForm.errors.name}
                                        </p>
                                    )}
                                </label>
                                <label className="flex flex-col gap-2">
                                    Cognome
                                    <input
                                        className="rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                        placeholder="Rossi"
                                        value={manualForm.data.surname}
                                        onChange={(event) =>
                                            manualForm.setData(
                                                'surname',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    {manualForm.errors.surname && (
                                        <p className="text-xs text-rose-500">
                                            {manualForm.errors.surname}
                                        </p>
                                    )}
                                </label>
                                <label className="flex flex-col gap-2 sm:col-span-2">
                                    Email
                                    <input
                                        className="rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                        type="email"
                                        placeholder="nome.cognome@scuola.ch"
                                        value={manualForm.data.email}
                                        onChange={(event) =>
                                            manualForm.setData(
                                                'email',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    {manualForm.errors.email && (
                                        <p className="text-xs text-rose-500">
                                            {manualForm.errors.email}
                                        </p>
                                    )}
                                </label>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2">
                                <label className="flex flex-col gap-2">
                                    Ruolo
                                    <select
                                        className="rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                        value={manualForm.data.role}
                                        onChange={(event) =>
                                            handleRoleChange(event.target.value)
                                        }
                                    >
                                        {roleOptions.map((role) => (
                                            <option
                                                key={role.code}
                                                value={role.code}
                                            >
                                                {role.label}
                                            </option>
                                        ))}
                                    </select>
                                    {manualForm.errors.role && (
                                        <p className="text-xs text-rose-500">
                                            {manualForm.errors.role}
                                        </p>
                                    )}
                                </label>

                                <label className="flex flex-col gap-2">
                                    Data di nascita (opzionale)
                                    <input
                                        type="date"
                                        className="rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                        value={manualForm.data.birth_date}
                                        onChange={(event) =>
                                            manualForm.setData(
                                                'birth_date',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    {manualForm.errors.birth_date && (
                                        <p className="text-xs text-rose-500">
                                            {manualForm.errors.birth_date}
                                        </p>
                                    )}
                                </label>
                            </div>

                            <label className="flex flex-col gap-2">
                                Classe (opzionale, solo studente)
                                <select
                                    className="rounded-xl border border-slate-200 px-3 py-2 text-sm disabled:bg-slate-100"
                                    value={manualForm.data.class_id}
                                    onChange={(event) =>
                                        manualForm.setData(
                                            'class_id',
                                            event.target.value,
                                        )
                                    }
                                    disabled={manualForm.data.role !== 'student'}
                                >
                                    <option value="">Nessuna classe</option>
                                    {classOptions.map((classOption) => (
                                        <option
                                            key={classOption.id}
                                            value={classOption.id}
                                        >
                                            {classOption.label}
                                        </option>
                                    ))}
                                </select>
                                {manualForm.errors.class_id && (
                                    <p className="text-xs text-rose-500">
                                        {manualForm.errors.class_id}
                                    </p>
                                )}
                            </label>

                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={() => {
                                        manualForm.reset();
                                        manualForm.setData('role', defaultRole);
                                    }}
                                    className="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600"
                                >
                                    Resetta
                                </button>
                                <button
                                    type="submit"
                                    disabled={manualForm.processing}
                                    className="rounded-xl bg-slate-900 px-4 py-2 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-400"
                                >
                                    {manualForm.processing
                                        ? 'Creazione...'
                                        : 'Crea utente'}
                                </button>
                            </div>
                        </form>
                    </section>

                    <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-semibold text-slate-900">
                                    Importazione da GAGI
                                </h2>
                                <p className="text-sm text-slate-500">
                                    Carica file GAGI in formato XLSX/XLS o CSV.
                                </p>
                            </div>
                            <span className="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">
                                Massivo
                            </span>
                        </div>

                        <form
                            onSubmit={submitCsv}
                            className="mt-4 rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4"
                        >
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept=".csv,.xlsx,.xls,text/csv"
                                className="hidden"
                                onChange={handleFileChange}
                            />
                            <div className="flex flex-wrap items-center gap-3">
                                <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-slate-500 shadow-sm">
                                    <svg
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="1.6"
                                        className="h-6 w-6"
                                    >
                                        <path d="M12 3v12" />
                                        <path d="M8 11l4 4 4-4" />
                                        <path d="M4 17h16" />
                                    </svg>
                                </div>
                                <div className="flex-1">
                                    <p className="text-sm font-semibold text-slate-700">
                                        Trascina qui il file GAGI
                                    </p>
                                    <p className="text-xs text-slate-500">
                                        Oppure seleziona dal computer. Supportati XLSX, XLS, CSV.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => fileInputRef.current?.click()}
                                    className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600"
                                >
                                    Seleziona file
                                </button>
                            </div>
                            <div className="mt-3 flex items-center justify-between rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs text-slate-500">
                                <span>{fileName}</span>
                                <button
                                    type="submit"
                                    disabled={csvForm.processing || !csvForm.data.file}
                                    className="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-400"
                                >
                                    Carica
                                </button>
                            </div>
                            {csvForm.errors.file && (
                                <p className="mt-2 text-xs text-rose-500">
                                    {csvForm.errors.file}
                                </p>
                            )}
                            {conversionError && (
                                <p className="mt-2 text-xs text-rose-500">
                                    {conversionError}
                                </p>
                            )}
                        </form>

                        <div className="mt-5 space-y-3 text-sm text-slate-600">
                            <p className="text-sm font-semibold text-slate-700">
                                Passi consigliati
                            </p>
                            <ol className="space-y-2 text-xs text-slate-500">
                                <li className="flex items-center gap-2">
                                    <span className="flex h-5 w-5 items-center justify-center rounded-full bg-slate-900 text-[10px] font-semibold text-white">
                                        1
                                    </span>
                                    Esporta da GAGI (colonne: Allievo, Data di nascita, Sezione, NetworkId).
                                </li>
                                <li className="flex items-center gap-2">
                                    <span className="flex h-5 w-5 items-center justify-center rounded-full bg-slate-900 text-[10px] font-semibold text-white">
                                        2
                                    </span>
                                    Controlla che email e ruoli siano validi.
                                </li>
                                <li className="flex items-center gap-2">
                                    <span className="flex h-5 w-5 items-center justify-center rounded-full bg-slate-900 text-[10px] font-semibold text-white">
                                        3
                                    </span>
                                    Carica il file e verifica gli utenti importati.
                                </li>
                            </ol>
                        </div>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
