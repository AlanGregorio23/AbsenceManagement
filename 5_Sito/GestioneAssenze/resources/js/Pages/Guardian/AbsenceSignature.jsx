import { Head, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

const inputClass =
    'w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base text-slate-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100';

export default function AbsenceSignature({
    token,
    status,
    signatureSuccess = false,
    canSign = false,
    alreadySigned = false,
    signedAt = null,
    signedBy = null,
    absence = null,
    guardian = null,
    oldInput = null,
}) {
    const canvasRef = useRef(null);
    const hasStrokeRef = useRef(false);
    const drawingRef = useRef(false);

    const [signatureClientError, setSignatureClientError] = useState('');
    const {
        data,
        setData,
        post,
        processing,
        errors,
        clearErrors,
        transform,
    } = useForm({
        full_name: oldInput?.full_name ?? guardian?.name ?? '',
        consent: Boolean(oldInput?.consent),
        signature_data: oldInput?.signature_data ?? '',
    });

    useEffect(() => {
        const canvas = canvasRef.current;
        if (!canvas || !canSign) {
            return undefined;
        }

        const context = canvas.getContext('2d');
        if (!context) {
            return undefined;
        }

        const setupCanvas = () => {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            const rect = canvas.getBoundingClientRect();
            canvas.width = Math.floor(rect.width * ratio);
            canvas.height = Math.floor(rect.height * ratio);
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            context.lineWidth = 2;
            context.lineCap = 'round';
            context.lineJoin = 'round';
            context.strokeStyle = '#0f172a';
            context.clearRect(0, 0, rect.width, rect.height);
            hasStrokeRef.current = false;

            if (data.signature_data) {
                const image = new Image();
                image.onload = () => {
                    context.drawImage(image, 0, 0, rect.width, rect.height);
                    hasStrokeRef.current = true;
                };
                image.src = data.signature_data;
            }
        };

        const getPosition = (event) => {
            const rect = canvas.getBoundingClientRect();

            return {
                x: event.clientX - rect.left,
                y: event.clientY - rect.top,
            };
        };

        const onPointerDown = (event) => {
            event.preventDefault();
            const position = getPosition(event);
            drawingRef.current = true;
            context.beginPath();
            context.moveTo(position.x, position.y);
        };

        const onPointerMove = (event) => {
            if (!drawingRef.current) {
                return;
            }

            event.preventDefault();
            const position = getPosition(event);
            context.lineTo(position.x, position.y);
            context.stroke();
            hasStrokeRef.current = true;
            setSignatureClientError('');
            clearErrors('signature_data');
        };

        const onPointerStop = () => {
            if (!drawingRef.current) {
                return;
            }

            drawingRef.current = false;
            context.closePath();
        };

        canvas.addEventListener('pointerdown', onPointerDown);
        canvas.addEventListener('pointermove', onPointerMove);
        canvas.addEventListener('pointerup', onPointerStop);
        canvas.addEventListener('pointerleave', onPointerStop);
        canvas.addEventListener('pointercancel', onPointerStop);
        window.addEventListener('resize', setupCanvas);

        setupCanvas();

        return () => {
            canvas.removeEventListener('pointerdown', onPointerDown);
            canvas.removeEventListener('pointermove', onPointerMove);
            canvas.removeEventListener('pointerup', onPointerStop);
            canvas.removeEventListener('pointerleave', onPointerStop);
            canvas.removeEventListener('pointercancel', onPointerStop);
            window.removeEventListener('resize', setupCanvas);
        };
    }, [canSign, data.signature_data]);

    const clearSignature = () => {
        const canvas = canvasRef.current;
        if (!canvas) {
            return;
        }

        const context = canvas.getContext('2d');
        if (!context) {
            return;
        }

        const rect = canvas.getBoundingClientRect();
        context.clearRect(0, 0, rect.width, rect.height);
        hasStrokeRef.current = false;
        setData('signature_data', '');
        setSignatureClientError('');
        clearErrors('signature_data');
    };

    const submit = (event) => {
        event.preventDefault();

        const canvas = canvasRef.current;
        if (!canvas || !hasStrokeRef.current) {
            setSignatureClientError('Firma grafica obbligatoria.');
            return;
        }

        const signatureData = canvas.toDataURL('image/png');
        transform((formData) => ({
            ...formData,
            signature_data: signatureData,
        }));

        post(route('guardian.absences.signature.store', { token }), {
            preserveScroll: true,
            onFinish: () => {
                transform((formData) => formData);
            },
        });
    };

    return (
        <>
            <Head title="Firma assenza tutore" />

            <div className="min-h-screen bg-slate-100 px-3 py-4 text-slate-900 sm:px-6 sm:py-8">
                <main className="mx-auto w-full max-w-4xl rounded-lg border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                    <h1 className="text-xl font-semibold text-slate-900">
                        Conferma assenza e firma tutore
                    </h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Link sicuro con token temporaneo.
                    </p>

                    {absence && (
                        <dl className="mt-5 grid gap-2 sm:grid-cols-2 sm:gap-3">
                            <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                <dt className="text-xs uppercase tracking-wide text-slate-500">
                                    Studente
                                </dt>
                                <dd className="mt-1 break-words text-sm font-semibold text-slate-800">
                                    {absence.student_name}
                                </dd>
                            </div>
                            <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                <dt className="text-xs uppercase tracking-wide text-slate-500">
                                    Tutore
                                </dt>
                                <dd className="mt-1 break-words text-sm font-semibold text-slate-800">
                                    {guardian?.name ?? '-'}
                                </dd>
                            </div>
                            <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                <dt className="text-xs uppercase tracking-wide text-slate-500">
                                    Assenza
                                </dt>
                                <dd className="mt-1 break-words text-sm font-semibold text-slate-800">
                                    {absence.id}
                                </dd>
                            </div>
                            <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                <dt className="text-xs uppercase tracking-wide text-slate-500">
                                    Periodo
                                </dt>
                                <dd className="mt-1 break-words text-sm font-semibold text-slate-800">
                                    {absence.start_date} - {absence.end_date}
                                </dd>
                            </div>
                            <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                <dt className="text-xs uppercase tracking-wide text-slate-500">
                                    Ore
                                </dt>
                                <dd className="mt-1 break-words text-sm font-semibold text-slate-800">
                                    {absence.hours}
                                </dd>
                            </div>
                            <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                <dt className="text-xs uppercase tracking-wide text-slate-500">
                                    Motivo
                                </dt>
                                <dd className="mt-1 break-words text-sm font-semibold text-slate-800">
                                    {absence.reason}
                                </dd>
                            </div>
                            <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 sm:col-span-2">
                                <dt className="text-xs uppercase tracking-wide text-slate-500">
                                    Certificato medico
                                </dt>
                                <dd className="mt-1">
                                    <span
                                        className={`rounded-full px-3 py-1 text-xs font-semibold ${absence.certificate_requirement?.badge ?? 'bg-slate-100 text-slate-700'}`}
                                    >
                                        {absence.certificate_requirement?.label ??
                                            'Certificato non obbligatorio'}
                                    </span>
                                </dd>
                            </div>
                        </dl>
                    )}

                    {errors.token && (
                        <div className="mt-4 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                            {errors.token}
                        </div>
                    )}

                    {signatureSuccess && (
                        <div className="mt-4 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                            Firma registrata con successo. La richiesta e in attesa
                            validazione docente.
                        </div>
                    )}

                    {status === 'expired' && (
                        <div className="mt-4 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                            Il link e scaduto. Chiedi al docente di classe di
                            reinviare la richiesta firma.
                        </div>
                    )}

                    {status === 'used' && (
                        <div className="mt-4 rounded-lg border border-blue-300 bg-blue-50 px-3 py-2 text-sm text-blue-700">
                            Questo link e gia stato utilizzato. Se serve, chiedi al
                            docente un nuovo invio.
                        </div>
                    )}

                    {status === 'invalid' && (
                        <div className="mt-4 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                            Link non valido. Verifica il collegamento ricevuto via
                            email.
                        </div>
                    )}

                    {alreadySigned && (
                        <div className="mt-4 rounded-lg border border-blue-300 bg-blue-50 px-3 py-2 text-sm text-blue-700">
                            Questa assenza risulta gia firmata
                            {signedBy ? ` da ${signedBy}` : ''}.
                            {signedAt ? ` Firma registrata il ${signedAt}.` : ''}
                        </div>
                    )}

                    {canSign && (
                        <form onSubmit={submit} className="mt-5 space-y-5">
                            <div>
                                <label
                                    htmlFor="full_name"
                                    className="block text-sm font-semibold text-slate-700"
                                >
                                    Nome e cognome firmatario
                                </label>
                                <input
                                    id="full_name"
                                    type="text"
                                    maxLength={120}
                                    className={`${inputClass} mt-1`}
                                    value={data.full_name}
                                    onChange={(event) =>
                                        setData('full_name', event.target.value)
                                    }
                                    required
                                />
                                {errors.full_name && (
                                    <p className="mt-1 text-xs text-rose-600">
                                        {errors.full_name}
                                    </p>
                                )}
                            </div>

                            <div className="rounded-lg border border-slate-200 bg-white p-3 sm:p-4">
                                <p className="text-sm font-semibold text-slate-700">
                                    Firma grafica (mouse o touch)
                                </p>
                                <canvas
                                    ref={canvasRef}
                                    className="mt-2 block h-48 w-full rounded-lg border border-dashed border-slate-400 bg-white sm:h-56"
                                    style={{ touchAction: 'none' }}
                                    aria-label="Firma grafica"
                                />
                                <div className="mt-2 flex flex-col gap-2 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                                    <span className="leading-relaxed">
                                        Firma all interno del riquadro prima di
                                        inviare.
                                    </span>
                                    <button
                                        type="button"
                                        className="min-h-10 w-full rounded-md border border-slate-300 px-3 py-2 font-semibold text-slate-700 hover:bg-slate-50 sm:w-auto"
                                        onClick={clearSignature}
                                    >
                                        Cancella firma
                                    </button>
                                </div>
                                {(signatureClientError || errors.signature_data) && (
                                    <p className="mt-2 text-xs text-rose-600">
                                        {signatureClientError || errors.signature_data}
                                    </p>
                                )}
                            </div>

                            <label className="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    className="mt-0.5 h-5 w-5 shrink-0 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                    checked={Boolean(data.consent)}
                                    onChange={(event) =>
                                        setData('consent', event.target.checked)
                                    }
                                />
                                <span>
                                    Dichiaro di aver verificato i dati e confermo
                                    l assenza in qualita di tutore legale.
                                </span>
                            </label>
                            {errors.consent && (
                                <p className="-mt-2 text-xs text-rose-600">
                                    {errors.consent}
                                </p>
                            )}

                            <div>
                                <button
                                    type="submit"
                                    className="min-h-11 w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-blue-300 sm:w-auto"
                                    disabled={processing}
                                >
                                    Conferma e firma
                                </button>
                            </div>
                        </form>
                    )}
                </main>
            </div>
        </>
    );
}
